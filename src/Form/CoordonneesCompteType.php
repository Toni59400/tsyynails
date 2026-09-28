<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Compte\CoordonneesCompte;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CoordonneesCompte>
 */
final class CoordonneesCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('telephone', TelType::class, ['label' => 'Téléphone', 'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CoordonneesCompte::class]);
    }
}
