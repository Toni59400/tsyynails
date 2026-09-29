<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Client;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lien « Ne plus recevoir ces demandes » des emails d'avis, signé (UriSigner) : aucune connexion requise.
 * Le GET affiche seulement un bouton, pour qu'un logiciel qui ouvre les liens des emails ne désinscrive personne.
 */
final class AvisController extends AbstractController
{
    public function __construct(
        private readonly UriSigner $signataire,
        private readonly ClientRepository $clientes,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/avis/ne-plus-demander', name: 'app_avis_refus', methods: ['GET', 'POST'])]
    public function refus(Request $request): Response
    {
        $client = $this->signataire->checkRequest($request) ? $this->clientes->find($request->query->getString('client')) : null;
        if (!$client instanceof Client || $client->estAnonymise()) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $client->refuserDemandesAvis($this->clock->now());
            $this->entityManager->flush();
        }

        return $this->render('site/avis_refus.html.twig', [
            'enregistre' => $client->refuseDemandesAvis(),
        ]);
    }
}
