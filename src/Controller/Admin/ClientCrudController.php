<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\MouvementPoints;
use App\Entity\Parametre;
use App\Entity\User;
use App\Enum\MotifMouvementPoints;
use App\Enum\StatutReservation;
use App\Repository\CarteFideliteRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\ParametreRepository;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les notes santé (chiffrées) ont leur propre écran : elles ne passent pas par ce formulaire générique.
 * La fiche détaillée affiche la fidélité (solde, carte, historique) et permet de corriger les points.
 *
 * @extends AbstractCrudController<Client>
 */
final class ClientCrudController extends AbstractCrudController
{
    public const VALEUR_POINT_PAR_DEFAUT = 10;
    private const CORRECTION_MAX = 10000;

    /** @var array<int, int>|null soldes chargés une seule fois pour toute la liste */
    private ?array $soldes = null;

    public function __construct(
        private readonly MouvementPointsRepository $mouvements,
        private readonly ReservationRepository $reservations,
        private readonly CarteFideliteRepository $cartes,
        private readonly ParametreRepository $parametres,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Client::class;
    }

    public function createEntity(string $entityFqcn): Client
    {
        return new Client('', '', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Cliente')
            ->setEntityLabelInPlural('Clientes')
            ->setDefaultSort(['nom' => 'ASC', 'prenom' => 'ASC'])
            ->setSearchFields(['prenom', 'nom', 'telephone', 'email'])
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Client $client): string => $client->getNomComplet())
            ->overrideTemplate('crud/detail', 'admin/client/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Suppression définitive interdite : les données passeront par l'anonymisation RGPD.
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('prenom', 'Prénom');
        yield TextField::new('nom');
        yield TelephoneField::new('telephone', 'Téléphone')->setHelp('Format international : +33612345678');
        yield EmailField::new('email')->setRequired(false);
        // Le solde n'est pas une colonne (somme des mouvements) : calculé à partir de l'identifiant.
        yield IntegerField::new('id', 'Points fidélité')
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(fn ($id, ?Client $client): int => null === $client ? 0 : $this->solde($client));
        yield DateTimeField::new('derniereVisiteAt', 'Dernière visite')->hideOnForm()->setFormat('dd/MM/yyyy');
        yield DateTimeField::new('createdAt', 'Fiche créée le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        $client = $responseParameters->get('entity')->getInstance();
        if (!$client instanceof Client) {
            return $responseParameters;
        }

        $reservations = $this->reservations->findPourClient($client, 15);
        $honorees = array_filter($reservations, static fn ($r): bool => StatutReservation::HONOREE === $r->getStatut());

        $responseParameters->setAll([
            'fidelite' => [
                'solde' => $this->mouvements->soldePour($client),
                'valeur_point' => $this->valeurPoint(),
                'carte' => $this->cartes->findActivePour($client),
                'mouvements' => $this->mouvements->historiquePour($client),
            ],
            'reservations' => $reservations,
            'visites' => \count($honorees),
            'non_honorees' => \count(array_filter($reservations, static fn ($r): bool => StatutReservation::NON_HONOREE === $r->getStatut())),
            'correction_max' => self::CORRECTION_MAX,
        ]);

        return $responseParameters;
    }

    /**
     * Ajout ou retrait manuel de points (geste commercial, erreur de saisie…), tracé avec son auteur.
     * Route : admin_client_points.
     *
     * @param AdminContext<Client> $context
     */
    #[AdminRoute('/{entityId}/points', name: 'points', options: ['methods' => ['POST']])]
    public function corrigerPoints(AdminContext $context, Request $request): RedirectResponse
    {
        $client = $context->getEntity()->getInstance();
        if (!$client instanceof Client) {
            throw $this->createNotFoundException();
        }

        $retour = $this->redirectToRoute('admin_client_detail', ['entityId' => $client->getId()]);

        if (!$this->isCsrfTokenValid('points_client_'.$client->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $delta = filter_var($request->request->get('delta'), \FILTER_VALIDATE_INT);
        $commentaire = trim($request->request->getString('commentaire'));
        $solde = $this->mouvements->soldePour($client);

        $erreur = match (true) {
            false === $delta || 0 === $delta => 'Indiquez un nombre de points différent de zéro (négatif pour retirer).',
            abs($delta) > self::CORRECTION_MAX => \sprintf('Une correction ne peut pas dépasser %d points.', self::CORRECTION_MAX),
            '' === $commentaire || mb_strlen($commentaire) > 255 => 'Indiquez la raison de la correction (255 caractères maximum).',
            $solde + $delta < 0 => \sprintf('Le solde ne peut pas devenir négatif (solde actuel : %d points).', $solde),
            default => null,
        };
        if (null !== $erreur) {
            $this->addFlash('danger', $erreur);

            return $retour;
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->entityManager->persist(new MouvementPoints($client, $delta, MotifMouvementPoints::CORRECTION, null, $admin->getUserIdentifier(), $commentaire));
        $this->entityManager->flush();

        $this->logger->notice('Correction de points fidélité.', ['client' => $client->getId(), 'delta' => $delta, 'admin' => $admin->getUserIdentifier()]);
        $this->addFlash('success', \sprintf('%+d points enregistrés. Nouveau solde : %d points.', $delta, $solde + $delta));

        return $retour;
    }

    private function solde(Client $client): int
    {
        $this->soldes ??= $this->mouvements->soldesParClient();

        return $this->soldes[$client->getId()] ?? 0;
    }

    private function valeurPoint(): int
    {
        return $this->parametres->entier(Parametre::VALEUR_POINT_CENTIMES, self::VALEUR_POINT_PAR_DEFAUT);
    }
}
