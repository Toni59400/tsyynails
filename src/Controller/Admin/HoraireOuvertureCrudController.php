<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\HoraireOuverture;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

/**
 * @extends AbstractCrudController<HoraireOuverture>
 */
final class HoraireOuvertureCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return HoraireOuverture::class;
    }

    public function createEntity(string $entityFqcn): HoraireOuverture
    {
        return new HoraireOuverture(1, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('18:00'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Plage d\'ouverture')
            ->setEntityLabelInPlural('Horaires d\'ouverture')
            ->setDefaultSort(['jourSemaine' => 'ASC', 'heureDebut' => 'ASC'])
            ->setHelp('index', 'Pour une pause déjeuner, créez deux plages le même jour (ex. 9h-12h et 13h30-19h).');
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('jourSemaine', 'Jour')->setChoices(array_flip(HoraireOuverture::JOURS));
        yield TimeField::new('heureDebut', 'De')->setFormat('HH:mm');
        yield TimeField::new('heureFin', 'À')->setFormat('HH:mm');
    }
}
