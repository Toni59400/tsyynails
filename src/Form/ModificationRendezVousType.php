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
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Modification d'un rendez-vous dans l'admin. Option prestations_figees : acompte par carte,
 * la prestation (et celle du duo) ne peut plus changer.
 *
 * @extends AbstractType<ModificationRendezVous>
 */
final class ModificationRendezVousType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $figees = $options['prestations_figees'];
        $aideFigee = 'Acompte réglé par carte pour cette prestation : elle ne peut plus être changée.';

        $builder
            ->add('date', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable'])
            ->add('heure', TimeType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'attr' => ['step' => 900],
                'help' => 'Les horaires d\'ouverture ne s\'appliquent pas ici : seul un chevauchement est refusé. La majoration du soir est recalculée.'])
            ->add('prestation', EntityType::class, [
                'class' => Prestation::class,
                'choice_label' => 'nom',
                'disabled' => $figees,
                'help' => $figees ? $aideFigee : null,
                // Prestation actuelle proposée même si elle a été désactivée depuis.
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('p')->orderBy('p.ordre', 'ASC'),
            ])
            ->add('supplement', EntityType::class, [
                'label' => 'Supplément',
                'class' => Supplement::class,
                'required' => false,
                'placeholder' => 'Aucun',
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('s')->orderBy('s.ordre', 'ASC'),
                'choice_label' => static fn (Supplement $s): string => $s->getNom().($s->isActive() ? '' : ' (désactivé)'),
            ])
            ->add('duo', CheckboxType::class, ['label' => 'RDV duo ?', 'required' => false, 'disabled' => $figees,
                'attr' => ['data-afficher-si-coche-target' => 'case', 'data-action' => 'afficher-si-coche#basculer']])
            ->add('prestationDuo', EntityType::class, ['label' => 'Prestation de la 2e personne', 'class' => Prestation::class, 'required' => false,
                'disabled' => $figees, 'placeholder' => 'Choisir', 'choice_label' => 'nom', 'row_attr' => ['data-afficher-si-coche-target' => 'bloc'],
                'query_builder' => static fn (EntityRepository $r): QueryBuilder => $r->createQueryBuilder('p')->orderBy('p.ordre', 'ASC')])
            ->add('remise', IntegerType::class, ['label' => 'Réduction totale appliquée (%)', 'attr' => ['min' => 0, 'max' => 100],
                'help' => 'Sur les prestations et le supplément, hors majoration du soir. 0 = aucune.'])
            ->add('prevenir', CheckboxType::class, ['label' => 'Envoyer le récapitulatif à jour à la cliente par email', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ModificationRendezVous::class, 'prestations_figees' => false]);
        $resolver->setAllowedTypes('prestations_figees', 'bool');
    }
}
