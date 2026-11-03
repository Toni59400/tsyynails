<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\RetourCliente;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Réponses au questionnaire de satisfaction (affiche QR code). Lecture et suppression seulement.
 *
 * @extends AbstractCrudController<RetourCliente>
 */
final class RetourClienteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RetourCliente::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réponse')
            ->setEntityLabelInPlural('Questionnaire de satisfaction')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Réponses au questionnaire de l\'affiche QR code (menu « Affiche QR avis » pour l\'imprimer). Les points remercient la réponse au questionnaire, jamais un avis Google : Google interdit toute contrepartie à un avis.');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Reçue le')->setFormat('dd/MM/yyyy HH:mm');
        yield IntegerField::new('note', 'Note')->formatValue(static fn (int $note): string => str_repeat('★', $note).str_repeat('☆', 5 - $note));
        yield TextField::new('recommandeLibelle', 'Recommande');
        yield AssociationField::new('client', 'Cliente')->formatValue(static fn ($valeur, RetourCliente $r): string => $r->getClient()?->getNomComplet() ?? 'Non identifiée');
        yield TextareaField::new('commentaire', 'Commentaire')->setMaxLength(80)->onlyOnIndex();
        yield TextareaField::new('commentaire', 'Commentaire')->onlyOnDetail();
        yield IntegerField::new('pointsCredites', 'Points offerts');
    }
}
