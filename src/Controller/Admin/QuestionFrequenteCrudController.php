<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\QuestionFrequente;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * FAQ publique (/questions-frequentes), reprise dans les données structurées et llms.txt.
 *
 * @extends AbstractCrudController<QuestionFrequente>
 */
final class QuestionFrequenteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return QuestionFrequente::class;
    }

    public function createEntity(string $entityFqcn): QuestionFrequente
    {
        return new QuestionFrequente('', '');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Question')
            ->setEntityLabelInPlural('Questions fréquentes')
            ->setDefaultSort(['ordre' => 'ASC'])
            ->setHelp(Crud::PAGE_INDEX, 'Formulez les questions comme vos clientes les posent (« Combien de temps tient une pose gel ? ») et commencez la réponse par l\'information essentielle : c\'est ce que Google et les assistants IA reprennent.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('ordre', 'Ordre')->setHelp('Les plus petits nombres s\'affichent en premier.');
        yield TextField::new('question');
        yield TextareaField::new('reponse', 'Réponse')->hideOnIndex()->setNumOfRows(6);
        yield BooleanField::new('publiee', 'Visible sur le site');
    }
}
