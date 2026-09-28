<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Compte\InscriptionClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<InscriptionClient>
 */
final class InscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, ['label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
            ->add('nom', TextType::class, ['label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '06 12 34 56 78'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'Utilisez l\'adresse donnée lors de vos rendez-vous pour retrouver vos points.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => 'Mot de passe',
                'help' => '12 caractères minimum. Une phrase facile à retenir fonctionne très bien.',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => 12],
            ])
            ->add('codeParrainage', TextType::class, [
                'label' => 'Code de parrainage (facultatif)',
                'required' => false,
                'help' => 'Une cliente vous a recommandé le salon ? Vous recevrez toutes les deux des points à votre premier rendez-vous.',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 12, 'autocapitalize' => 'characters'],
            ])
            ->add('accepteConditions', CheckboxType::class, [
                'label' => 'J\'accepte le règlement du programme de fidélité.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InscriptionClient::class]);
    }
}
