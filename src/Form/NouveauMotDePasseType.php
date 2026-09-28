<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{motDePasse: string}>
 */
final class NouveauMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motDePasse', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe sont différents.',
            'first_options' => ['label' => 'Nouveau mot de passe', 'help' => '12 caractères minimum.', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new Assert\NotBlank(message: 'Choisissez un mot de passe.'),
                new Assert\Length(min: 12, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
                new Assert\NotCompromisedPassword(message: 'Ce mot de passe a fuité lors d\'un piratage connu : choisissez-en un autre.', skipOnError: true),
            ],
        ]);
    }
}
