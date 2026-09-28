<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Client;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Les notes santé (chiffrées) et la carte fidélité auront leurs propres écrans :
 * elles ne passent pas par ce formulaire générique.
 *
 * @extends AbstractCrudController<Client>
 */
final class ClientCrudController extends AbstractCrudController
{
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
            ->setSearchFields(['prenom', 'nom', 'telephone', 'email']);
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
        yield DateTimeField::new('derniereVisiteAt', 'Dernière visite')->hideOnForm()->setFormat('dd/MM/yyyy');
        yield DateTimeField::new('createdAt', 'Fiche créée le')->onlyOnDetail()->setFormat('dd/MM/yyyy HH:mm');
    }
}
