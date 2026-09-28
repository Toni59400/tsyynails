<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RecompenseFidelite;
use App\Service\Reservation\CoordonneesCliente;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Étape « coordonnées » du tunnel.
 * - connectee : coordonnées déjà connues, choix facultatif d'une récompense fidélité ;
 * - avec_compte : visiteuse non connectée, peut créer son compte en même temps.
 *
 * @extends AbstractType<CoordonneesCliente>
 */
final class CoordonneesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['connectee']) {
            /** @var list<RecompenseFidelite> $recompenses */
            $recompenses = $options['recompenses'];
            $builder->add('recompense', ChoiceType::class, [
                'label' => 'Utiliser mes points',
                'mapped' => false,
                'required' => false,
                'expanded' => true,
                'choices' => $recompenses,
                'choice_value' => static fn (?RecompenseFidelite $r): string => null === $r ? '' : (string) $r->getId(),
                'choice_label' => static fn (RecompenseFidelite $r): string => \sprintf('%s — %d points', $r->getNom(), $r->getSeuilPoints()),
                'placeholder' => 'Garder mes points pour plus tard',
            ]);
        } else {
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
                ]);

            if ($options['avec_compte']) {
                $builder
                    ->add('creerCompte', CheckboxType::class, [
                        'label' => 'Créer mon compte pour cumuler des points de fidélité',
                        'required' => false,
                        'attr' => ['data-action' => 'afficher-si-coche#basculer', 'data-afficher-si-coche-target' => 'case'],
                    ])
                    ->add('motDePasse', PasswordType::class, [
                        'label' => 'Mot de passe du compte',
                        'required' => false,
                        'help' => '12 caractères minimum.',
                        'attr' => ['autocomplete' => 'new-password'],
                    ]);
            }
        }

        $builder->add('accepteConditions', CheckboxType::class, [
            'label' => 'J\'accepte les conditions de réservation (acompte, annulation).',
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CoordonneesCliente::class,
            'connectee' => false,
            'avec_compte' => true,
            'recompenses' => [],
        ]);
        $resolver->setAllowedTypes('connectee', 'bool');
        $resolver->setAllowedTypes('avec_compte', 'bool');
        $resolver->setAllowedTypes('recompenses', 'array');
    }
}
