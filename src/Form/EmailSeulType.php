<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une adresse email (mot de passe oublié, renvoi du lien de confirmation).
 *
 * @extends AbstractType<array{email: string}>
 */
final class EmailSeulType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse email',
            'attr' => ['autocomplete' => 'email'],
            'constraints' => [new Assert\NotBlank(message: 'Indiquez votre adresse email.'), new Assert\Email(message: 'Adresse email invalide.')],
        ]);
    }
}
