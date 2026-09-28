<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Indisponibilite;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Indisponibilite>
 */
final class IndisponibiliteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Indisponibilite::class;
    }

    public function createEntity(string $entityFqcn): Indisponibilite
    {
        $demain = new \DateTimeImmutable('tomorrow');

        return new Indisponibilite($demain, $demain->modify('+1 day'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Indisponibilité')
            ->setEntityLabelInPlural('Congés et fermetures')
            ->setDefaultSort(['debut' => 'DESC'])
            ->setHelp('index', 'Aucun créneau n\'est proposé aux clientes sur ces périodes.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('debut', 'Début')->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('fin', 'Fin')->setFormat('dd/MM/yyyy HH:mm');
        yield TextField::new('motif')->setRequired(false);
    }
}
