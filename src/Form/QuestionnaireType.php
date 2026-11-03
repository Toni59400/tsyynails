<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RetourCliente;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Questionnaire de satisfaction de l'affiche QR code : note, recommandation, commentaire, email (pour les points).
 *
 * @extends AbstractType<array{note: int, recommande: string, commentaire: ?string, email: string}>
 */
final class QuestionnaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('note', ChoiceType::class, [
                'label' => 'Votre note globale',
                'expanded' => true,
                'choices' => ['5 — Excellent' => 5, '4 — Très bien' => 4, '3 — Bien' => 3, '2 — Moyen' => 2, '1 — Décevant' => 1],
                'constraints' => [new Assert\NotNull(message: 'Choisissez une note.')],
            ])
            ->add('recommande', ChoiceType::class, [
                'label' => 'Recommanderiez-vous le salon à une amie ?',
                'expanded' => true,
                'choices' => array_flip(RetourCliente::RECOMMANDATIONS),
                'constraints' => [new Assert\NotNull(message: 'Choisissez une réponse.')],
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'Un mot sur votre expérience ? (facultatif)',
                'required' => false,
                'help' => 'Ce qui vous a plu, ce que je pourrais améliorer… Lu uniquement par moi.',
                'attr' => ['rows' => 4, 'maxlength' => 1000],
                'constraints' => [new Assert\Length(max: 1000)],
            ])
            ->add('email', EmailType::class, [
                'empty_data' => '',
                'label' => 'Votre email',
                'help' => 'Celui donné lors de vos rendez-vous : il sert seulement à ajouter les points sur votre carte.',
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez votre adresse email.'), new Assert\Email(message: 'Adresse email invalide.')],
            ]);
    }
}
