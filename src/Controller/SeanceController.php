<?php

namespace App\Controller;

use App\Entity\Seance;
use App\Form\SeanceformType;
use App\Repository\SeanceRepository;
use App\Repository\TypeSeanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Form\SearchSeanceType;
use DateTime;
use DateInterval;
use App\Repository\AbonneRepository;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

class SeanceController extends AbstractController
{
    #[Route('/seance/list', name: 'list_seance')]
    public function listSeance(Request $request, SeanceRepository $seanceRepository): Response
    {
        $form = $this->createForm(SearchSeanceType::class);
        $form->handleRequest($request);
    
        $sortBy = $form->isSubmitted() && $form->isValid()
            ? $form->get('sortBy')->getData()
            : null;
    
        $seances = $seanceRepository->findBySort($sortBy);
    
        return $this->render('seance/list.html.twig', [
            'seances' => $seances,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/seance/new', name: 'add_seance')]
    public function addSeance(Request $request, EntityManagerInterface $entityManager): Response
    {
        $seance = new Seance();
        $form = $this->createForm(SeanceformType::class, $seance);
        $form->handleRequest($request);
    
        if ($form->isSubmitted() && $form->isValid()) {
            // Vérification des conflits : même horaire et même salle
            $existingSeances = $entityManager->getRepository(Seance::class)
                ->findBy(['date' => $seance->getDate(), 'salle' => $seance->getSalle()]);
    
            foreach ($existingSeances as $existingSeance) {
                if ($existingSeance->getDate() == $seance->getDate() &&
                    $existingSeance->getSalle() === $seance->getSalle()) {
                    $this->addFlash(
                        'error',
                        'Une autre séance est déjà planifiée à cet horaire dans cette salle.'
                    );
                    return $this->redirectToRoute('add_seance');
                }
            }
    
            // Vérification qu'un coach ne gère pas deux séances en même temps
            $conflictingCoachSeances = $entityManager->getRepository(Seance::class)
                ->findBy(['date' => $seance->getDate(), 'nomCoach' => $seance->getNomCoach()]);
    
            if ($conflictingCoachSeances) {
                $this->addFlash(
                    'error',
                    'Le coach est déjà assigné à une autre séance à cet horaire.'
                );
                return $this->redirectToRoute('add_seance');
            }
    
            // Validation du nom : lettres, espaces et tirets uniquement
            if (!preg_match('/^[a-zA-Z\s\-]+$/', $seance->getNom())) {
                $this->addFlash(
                    'error',
                    'Le nom de la séance ne doit contenir que des lettres, espaces et tirets.'
                );
                return $this->redirectToRoute('add_seance');
            }
    
            // Validation de la capacité maximale
            if ($seance->getParticipantsInscrits() > $seance->getCapaciteMax()) {
                $this->addFlash(
                    'error',
                    'Le nombre de participants inscrits dépasse la capacité maximale.'
                );
                return $this->redirectToRoute('add_seance');
            }
    
            // Validation des participants inscrits : entier positif
            if ($seance->getParticipantsInscrits() < 0) {
                $this->addFlash(
                    'error',
                    'Le nombre de participants inscrits doit être un entier positif.'
                );
                return $this->redirectToRoute('add_seance');
            }
    
            // Enregistrement si tout est valide
            $entityManager->persist($seance);
            $entityManager->flush();
    
            $this->addFlash('success', 'La séance a été ajoutée avec succès.');
            return $this->redirectToRoute('list_seance');
        }
    
        return $this->render('seance/nouveau.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    
    
    

    #[Route('/seance/delete/{id}', name: 'delete_seance')]
    public function deleteSeance(int $id, EntityManagerInterface $entityManager): Response
    {
        $seance = $entityManager->getRepository(Seance::class)->find($id);

        if ($seance) {
            $entityManager->remove($seance);
            $entityManager->flush();
        }

        return $this->redirectToRoute('list_seance');
    }

    #[Route('/seance/modify/{id}', name: 'modify_seance')]
    public function modifySeance(int $id, Request $request, EntityManagerInterface $entityManager): Response
    {
        // Récupérer la séance existante
        $seance = $entityManager->getRepository(Seance::class)->find($id);
        
        if (!$seance) {
            throw $this->createNotFoundException('Séance non trouvée');
        }
    
        // Créer et gérer le formulaire
        $form = $this->createForm(SeanceformType::class, $seance);
        $form->handleRequest($request);
    
        // Vérification des conflits et validations
        if ($form->isSubmitted() && $form->isValid()) {
            // Validation des conflits de salle et de date/heure
            $existingSeances = $entityManager->getRepository(Seance::class)
                ->findBy(['date' => $seance->getDate(), 'salle' => $seance->getSalle()]);
    
            foreach ($existingSeances as $existingSeance) {
                if ($existingSeance->getId() !== $seance->getId()) {
                    $this->addFlash('error', 'Une autre séance est déjà planifiée à cet horaire dans cette salle.');
                    return $this->redirectToRoute('modify_seance', ['id' => $id]);
                }
            }
    
            // Vérification qu'un coach ne gère pas deux séances en même temps
            $conflictingCoachSeances = $entityManager->getRepository(Seance::class)
                ->findBy(['date' => $seance->getDate(), 'nomCoach' => $seance->getNomCoach()]);
    
            foreach ($conflictingCoachSeances as $conflictingCoachSeance) {
                if ($conflictingCoachSeance->getId() !== $seance->getId()) {
                    $this->addFlash('error', 'Le coach est déjà assigné à une autre séance à cet horaire.');
                    return $this->redirectToRoute('modify_seance', ['id' => $id]);
                }
            }
    
            // Validation du nom de la séance : lettres, espaces et tirets uniquement
            if (!preg_match('/^[a-zA-Z\s\-]+$/', $seance->getNom())) {
                $this->addFlash('error', 'Le nom de la séance ne doit contenir que des lettres, espaces et tirets.');
                return $this->redirectToRoute('modify_seance', ['id' => $id]);
            }
    
            // Validation de la capacité maximale
            if ($seance->getParticipantsInscrits() > $seance->getCapaciteMax()) {
                $this->addFlash('error', 'Le nombre de participants inscrits dépasse la capacité maximale.');
                return $this->redirectToRoute('modify_seance', ['id' => $id]);
            }
    
            // Validation des participants inscrits : entier positif
            if ($seance->getParticipantsInscrits() < 0) {
                $this->addFlash('error', 'Le nombre de participants inscrits doit être un entier positif.');
                return $this->redirectToRoute('modify_seance', ['id' => $id]);
            }
    
            // Enregistrement des modifications si toutes les validations passent
            $typeSeance = $form->get('typeSeance')->getData();
            $seance->setTypeSeance($typeSeance);
    
            $objectif = $form->get('objectif')->getData();
            $seance->setObjectif($objectif);
    
            $entityManager->persist($seance);
            $entityManager->flush();
    
            // Rediriger vers la liste des séances après la modification
            $this->addFlash('success', 'La séance a été modifiée avec succès.');
            return $this->redirectToRoute('list_seance');
        }
    
        // Rendu du formulaire de modification
        return $this->render('seance/modify.html.twig', [
            'form' => $form->createView(),
        ]);
    }
    
    private SeanceRepository $seanceRepository;

    public function __construct(SeanceRepository $seanceRepository)
    {
        $this->seanceRepository = $seanceRepository;
    }


    #[Route('home/classes/search-classes', name:'search_classes', methods :["GET"])]
    public function search(Request $request): Response
    {
        $category = $request->query->get('category'); // Type de séance
        $objective = $request->query->get('objective'); // Objectif
    
        // Appel au repository pour récupérer les résultats
        $seances = $this->seanceRepository->searchSeances($category, $objective);
    
        // Enlever les doublons (en supposant que 'nom' est unique pour chaque séance)
        $seancesUnique = [];
        foreach ($seances as $seance) {
            // Utiliser le nom comme clé pour s'assurer qu'il n'y ait pas de doublon
            $seancesUnique[$seance->getNom()] = $seance;
        }
    
        // Recréer l'array avec les éléments uniques
        $seancesUnique = array_values($seancesUnique);
    
        // Rendu de la vue avec les résultats
        return $this->render('search_results.html.twig', [
            'seances' => $seancesUnique,
        ]);
    }
    #[Route('/seance/stats', name: 'stats_seances')]
    public function statsSeances(SeanceRepository $seanceRepository, TypeSeanceRepository $typeSeanceRepository): Response
    {
        // Récupérer les séances annulées
        $seancesAnnulees = $seanceRepository->createQueryBuilder('s')
        ->select('s.nom AS nomSeance, s.date AS dateSeance, s.statut AS statutSeance')
        ->where('s.statut = :statut')
        ->setParameter('statut', 'annulée')
        ->getQuery()
        ->getResult();

        // Nombre de Séances par Catégorie
        $seancesParCategorie = $seanceRepository->createQueryBuilder('s')
            ->select('t.type AS typeSeance, COUNT(s.id) AS nombreSeances')
            ->leftJoin('s.typeSeance', 't')
            ->groupBy('t.id')
            ->getQuery()
            ->getResult();

        // Taux d'Occupation par Catégorie
        $tauxOccupationParCategorie = $typeSeanceRepository->createQueryBuilder('t')
        ->select('t.type AS typeSeance, 
                  SUM(s.participantsinscrits) AS totalParticipants, 
                  SUM(s.capaciteMax) AS totalCapacite,
                  (SUM(s.participantsinscrits) / SUM(s.capaciteMax)) * 100 AS tauxOccupation')
        ->leftJoin('t.seances', 's')
        ->groupBy('t.id')
        ->getQuery()
        ->getResult();

        // Nombre total de participants par séance et moyenne des participants
        $participantsParSeance = $seanceRepository->createQueryBuilder('s')
            ->select('s.nom AS nomSeance, 
                      SUM(s.participantsinscrits) AS totalParticipants,
                      AVG(s.participantsinscrits) AS moyenneParticipants')
            ->groupBy('s.nom')
            ->getQuery()
            ->getResult();

        // Combiner les résultats
        $stats = [];
        foreach ($seancesParCategorie as $categorie) {
            $typeSeance = $categorie['typeSeance'];
            $nombreSeances = $categorie['nombreSeances'];
            $tauxOccupation = 0;

            // Rechercher le taux d'occupation pour cette catégorie
            foreach ($tauxOccupationParCategorie as $occupation) {
                if ($occupation['typeSeance'] == $typeSeance) {
                    $tauxOccupation = $occupation['tauxOccupation'];
                    break;
                }
            }

            // Ajouter les données combinées
            $stats[] = [
                'typeSeance' => $typeSeance,
                'nombreSeances' => $nombreSeances,
                'tauxOccupation' => $tauxOccupation,
            ];
        }

        // Ajouter les statistiques sur les participants par séance
        foreach ($participantsParSeance as $seance) {
            $nomSeance = $seance['nomSeance'];
            $totalParticipants = $seance['totalParticipants'];
            $moyenneParticipants = $seance['moyenneParticipants'];

            $stats[] = [
                'nomSeance' => $nomSeance,
                'totalParticipants' => $totalParticipants,
                'moyenneParticipants' => $moyenneParticipants,
            ];
        }

        // Passer les résultats à la vue
        return $this->render('stats.html.twig', [
            'participantsParSeance' => $participantsParSeance,
            'tauxOccupationParCategorie' => $tauxOccupationParCategorie,
            'stats' => $stats,
            'seancesAnnulees' => $seancesAnnulees,
        ]);
    }

    #[Route('/home/classes', name: 'app_hclasses', methods: ['GET'])]
    public function afficheSeances(SeanceRepository $repo): Response
    {
        // Récupérer la date actuelle
        $currentDate = new DateTime();
    
        // Calculer la date de début de la semaine (lundi)
        $startOfWeek = clone $currentDate;
        $startOfWeek->setISODate($currentDate->format('Y'), $currentDate->format('W'));
    
        // Calculer la date de fin de la semaine (dimanche)
        $endOfWeek = clone $startOfWeek;
        $endOfWeek->add(new DateInterval('P6D'));
    
        // Récupérer toutes les séances
        $seances = $repo->findAll();
    
        // Step 2: Créer un tableau structuré pour l'emploi du temps
        $timetable = [];
        $uniqueSeances = [];
    
        foreach ($seances as $seance) {
            $date = $seance->getDate();
    
            // Vérifier si la séance est dans l'intervalle de la semaine courante
            if ($date >= $startOfWeek && $date <= $endOfWeek) {
                $nom = $seance->getNom();
                $hour = $date->format('H'); // Extraire l'heure sous forme de chaîne (ex: '06', '08')
                $day = $date->format('l'); // Obtenir le jour de la semaine (ex: 'Monday', 'Tuesday')
    
                // Ajouter à uniqueSeances si nécessaire
                if (!isset($uniqueSeances[$nom])) {
                    $uniqueSeances[$nom] = [
                        'nom' => $nom,
                        'categorie' => $seance->getTypeSeance(),
                    ];
                }
    
                // Initialiser le tableau de l'emploi du temps pour le jour et l'heure si ce n'est pas déjà fait
                if (!isset($timetable[$day])) {
                    $timetable[$day] = [];
                }
                if (!isset($timetable[$day][$hour])) {
                    $timetable[$day][$hour] = [];
                }
    
                // Ajouter la séance à l'emploi du temps
                $timetable[$day][$hour][] = $seance;
            }
        }
    
        // Retourner la vue avec les données filtrées
        return $this->render('class-details.html.twig', [
            'seances' => $seances,
            'timetable' => $timetable,
            'start_of_week' => $startOfWeek,
            'end_of_week' => $endOfWeek,
            'uniqueSeances' => $uniqueSeances,
        ]);
    }
    #[Route('/home/classes/{id}', name: 'app_classes_seances')]
    public function seanceDetails(int $id, EntityManagerInterface $entityManager): Response
    {
    // Récupérer la séance principale via l'id
    $seance = $entityManager->getRepository(Seance::class)->find($id);
    
    if (!$seance) {
        throw $this->createNotFoundException('Séance non trouvée');
    }

    // Récupérer toutes les séances avec le même nom que celle trouvée
    $availableSessions = $entityManager->getRepository(Seance::class)->findBy([
        'nom' => $seance->getNom(),  // Séances du même nom
    ]);

    return $this->render('seances-details.html.twig', [
        'seance' => $seance,
        'availableSessions' => $availableSessions,
    ]);
    }

    #[Route('/home/classes/details/{nom}', name: 'app_classes_details', methods: ['GET'])]
    public function afficheDetailsSeance(string $nom, SeanceRepository $repo): Response
    {
        // Récupérer toutes les séances correspondant au nom donné
        $seances = $repo->findBy(['nom' => $nom]);
    
        // Vérifier si des séances existent pour ce nom
        if (!$seances) {
            $this->addFlash('error', 'Aucune séance trouvée pour ce nom.');
            return $this->redirectToRoute('app_hclasses');
        }
    
        return $this->render('seances-details.html.twig', [
            'nom' => $nom,
            'seances' => $seances,
        ]);
    }

    #[Route('/annuler-seance/{id}', name: 'app_annuler_seance', methods: ['POST'])]
    public function annulerSeance(
        int $id,
        MailerInterface $mailer,
        AbonneRepository $abonneRepository,
        EntityManagerInterface $entityManager
    ): Response {
        $seance = $entityManager->getRepository(Seance::class)->find($id);

        if (!$seance) {
            throw $this->createNotFoundException('Séance non trouvée.');
        }

        // Mettre à jour le statut de la séance
        $seance->setStatut('annulée');
        $entityManager->flush();

        // Récupérer les abonnés inscrits à cette séance
        $abonnes = $abonneRepository->findBy(['seance' => $seance]);

        // Envoyer un email à chaque abonné
        foreach ($abonnes as $abonne) {
            $email = (new Email())
                ->from('your-email@example.com') // Adresse de l'expéditeur
                ->to($abonne->getEmail()) // Email de l'abonné
                ->subject('Séance Annulée')
                ->text(sprintf('Bonjour %s, la séance "%s" a été annulée.', $abonne->getPrenom(), $seance->getNom()))
                ->html(sprintf(
                    '<p>Bonjour %s,</p><p>La séance <strong>%s</strong> prévue le %s a été annulée.</p>',
                    $abonne->getPrenom(),
                    $seance->getNom(),
                    $seance->getDate()->format('d/m/Y H:i')
                ));

            $mailer->send($email);
        }

        // Retourner une réponse ou rediriger
        $this->addFlash('success', 'Séance annulée et notifications envoyées.');
        return $this->redirectToRoute('app_hclasses');
    }

}

