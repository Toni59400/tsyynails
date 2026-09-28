<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Reservation\CoordonneesCliente;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CoordonneesCliente>
 */
final class CoordonneesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'help' => 'Pour vous prévenir en cas d\'imprévu.',
                'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '06 12 34 56 78'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Vous y recevrez la confirmation et le rappel.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('accepteConditions', CheckboxType::class, [
                'label' => 'J\'accepte les conditions de réservation (acompte, annulation).',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CoordonneesCliente::class,
        ]);
    }
}
