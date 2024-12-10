<?php
namespace App\Controller;

use App\Entity\Seance;
use App\Repository\SeanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use DateTime;
use DateInterval;

class ReservationController extends AbstractController
{
    #[Route('/reserver-seance/{id}', name: 'app_reserver_seance', methods: ['POST'])]
    public function reserver(int $id, SeanceRepository $repo, EntityManagerInterface $entityManager): Response
    {
        // Récupérer la séance via son ID avec DQL
        $seance = $entityManager->createQueryBuilder()
            ->select('s')
            ->from(Seance::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$seance) {
            // Si la séance n'existe pas, afficher un message d'erreur
            return $this->redirectToRoute('app_hclasses');
        }

        // Récupérer la date actuelle
        $currentDate = new DateTime();
        // Calculer la date de début de la semaine (lundi)
        $startOfWeek = clone $currentDate;
        $startOfWeek->setISODate($currentDate->format('Y'), $currentDate->format('W'));   
        // Calculer la date de fin de la semaine (dimanche)
        $endOfWeek = clone $startOfWeek;
        $endOfWeek->add(new DateInterval('P6D'));

        // Vérifier si la séance est dans l'intervalle de la semaine
        $seanceDate = $seance->getDate(); // Supposons que la méthode getDate() retourne une DateTime
        
        if ($seanceDate < $startOfWeek) {
            // Si la séance est avant le début de la semaine, la marquer comme terminée
            $seance->setStatut('terminée');  
            $entityManager->persist($seance);
            $entityManager->flush();
            $this->addFlash('error', 'La séance est terminée.');
            return $this->redirectToRoute('app_hclasses');
        }

        // Vérifier si la séance est annulée
        if ($seance->getStatut() === 'annulée') {
            // Si la séance est annulée, ne pas permettre la réservation
            $this->addFlash('error', 'La séance a été annulée.');
            return $this->redirectToRoute('app_hclasses');
        }

        // Vérifier si des places sont disponibles
        if ($seance->getParticipantsInscrits() >= $seance->getCapaciteMax()) {
            // Si pas de places disponibles, afficher un message d'erreur
            $this->addFlash('error', 'Désolé, il n\'y a plus de places disponibles pour cette séance.');
            return $this->redirectToRoute('app_hclasses');
        }

        // Ajouter un participant à la séance
        $seance->setParticipantsInscrits($seance->getParticipantsInscrits() + 1);
        $entityManager->persist($seance);
        $entityManager->flush();

        // Afficher un message de confirmation
        $this->addFlash('success', 'Votre réservation a été effectuée avec succès !');

        // Rediriger vers la page des détails de la séance
        return $this->redirectToRoute('app_classes_details', ['nom' => $seance->getNom()]);
    }
}

