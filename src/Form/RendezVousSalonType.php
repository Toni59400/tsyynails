<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Prestation;
use App\Entity\Supplement;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Nouveau rendez-vous saisi dans l'admin (téléphone, au salon, RDV duo).
 *
 * @extends AbstractType<RendezVousSalon>
 */
final class RendezVousSalonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $actives = static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('p')->andWhere('p.active = true')->orderBy('p.ordre', 'ASC');

        $builder
            ->add('telephone', TelType::class, ['empty_data' => '', 'label' => 'Téléphone', 'help' => 'Une cliente déjà connue est retrouvée avec son numéro (sa fiche n\'est pas modifiée).'])
            ->add('prenom', TextType::class, ['empty_data' => '', 'label' => 'Prénom'])
            ->add('nom', TextType::class, ['empty_data' => '', 'label' => 'Nom'])
            ->add('email', EmailType::class, ['label' => 'Email', 'help' => 'Pour lui envoyer la confirmation et le rappel de la veille.'])
            ->add('prestation', EntityType::class, ['class' => Prestation::class, 'query_builder' => $actives, 'placeholder' => 'Choisir', 'choice_label' => 'nom'])
            ->add('supplement', EntityType::class, [
                'label' => 'Supplément',
                'class' => Supplement::class,
                'required' => false,
                'placeholder' => 'Aucun',
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('s')->andWhere('s.active = true')->orderBy('s.ordre', 'ASC'),
                'choice_label' => 'nom',
            ])
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('heure', TimeType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'attr' => ['step' => 900],
                'help' => 'Les horaires d\'ouverture ne s\'appliquent pas ici (rendez-vous du soir possibles) : seul un chevauchement est refusé.'])
            ->add('duo', CheckboxType::class, ['label' => 'RDV duo ?', 'required' => false,
                'attr' => ['data-afficher-si-coche-target' => 'case', 'data-action' => 'afficher-si-coche#basculer']])
            ->add('prestationDuo', EntityType::class, ['label' => 'Prestation de la 2e personne', 'class' => Prestation::class, 'required' => false,
                'query_builder' => $actives, 'placeholder' => 'Choisir', 'choice_label' => 'nom', 'row_attr' => ['data-afficher-si-coche-target' => 'bloc']])
            ->add('remiseDuo', IntegerType::class, ['label' => 'Réduction totale appliquée (%)', 'required' => false,
                'row_attr' => ['data-afficher-si-coche-target' => 'bloc'], 'attr' => ['min' => 0, 'max' => 100],
                'help' => 'Sur le prix des deux prestations (et du supplément), hors majoration du soir.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RendezVousSalon::class]);
    }
}
