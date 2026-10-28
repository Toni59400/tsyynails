<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RecompenseFidelite;
use App\Entity\Reservation;
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
            // Fiche incomplète (prénom, nom ou téléphone manquant ou invalide) : la cliente la complète ici,
            // sinon la validation échouerait sur des champs qu'elle ne voit pas.
            if ($options['completer_fiche']) {
                $builder
                    ->add('prenom', TextType::class, ['empty_data' => '', 'label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
                    ->add('nom', TextType::class, ['empty_data' => '', 'label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
                    ->add('telephone', TelType::class, ['empty_data' => '',
                        'label' => 'Téléphone',
                        'help' => 'Pour vous prévenir en cas d\'imprévu.',
                        'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '06 12 34 56 78'],
                    ]);
            }
        } else {
            $builder
                ->add('prenom', TextType::class, ['empty_data' => '', 'label' => 'Prénom', 'attr' => ['autocomplete' => 'given-name']])
                ->add('nom', TextType::class, ['empty_data' => '', 'label' => 'Nom', 'attr' => ['autocomplete' => 'family-name']])
                ->add('telephone', TelType::class, ['empty_data' => '',
                    'label' => 'Téléphone',
                    'help' => 'Pour vous prévenir en cas d\'imprévu.',
                    'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel', 'placeholder' => '06 12 34 56 78'],
                ])
                ->add('email', EmailType::class, ['empty_data' => '',
                    'label' => 'Email',
                    'help' => 'Vous y recevrez la confirmation et le rappel.',
                    'attr' => ['autocomplete' => 'email'],
                ]);

            if ($options['avec_compte']) {
                $builder
                    ->add('creerCompte', CheckboxType::class, [
                        'label' => 'Créer mon compte gratuit : points de fidélité et réduction à vie',
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

        // Choix du paiement seulement si la carte et les espèces sont proposées toutes les deux (réglages).
        /** @var list<string> $modes */
        $modes = $options['modes_paiement'];
        if (\count($modes) > 1) {
            $libelles = [
                Reservation::MODE_CARTE => \sprintf('Acompte de %s par carte maintenant (bloqué, débité à la validation)', $options['acompte_texte']),
                Reservation::MODE_ESPECES => 'Tout en espèces le jour du rendez-vous, sans acompte',
            ];
            $builder->add('modePaiement', ChoiceType::class, [
                'label' => 'Paiement',
                'expanded' => true,
                'choices' => array_flip(array_intersect_key($libelles, array_flip($modes))),
            ]);
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
            'modes_paiement' => [Reservation::MODE_CARTE],
            'acompte_texte' => '',
            'completer_fiche' => false,
        ]);
        $resolver->setAllowedTypes('completer_fiche', 'bool');
        $resolver->setAllowedTypes('modes_paiement', 'array');
        $resolver->setAllowedTypes('acompte_texte', 'string');
        $resolver->setAllowedTypes('connectee', 'bool');
        $resolver->setAllowedTypes('avec_compte', 'bool');
        $resolver->setAllowedTypes('recompenses', 'array');
    }
}
