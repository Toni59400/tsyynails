<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Prestation;
use App\Entity\Reservation;
use App\Entity\Supplement;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Modification d'un rendez-vous par la prothésiste : horaire, prestations, supplément, remise.
 */
final class ModificationRendezVous
{
    #[Assert\NotNull(message: 'Choisissez la prestation.')]
    public ?Prestation $prestation = null;

    public ?Supplement $supplement = null;

    #[Assert\NotNull(message: 'Choisissez la date.')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\NotNull(message: 'Choisissez l\'heure.')]
    public ?\DateTimeImmutable $heure = null;

    public bool $duo = false;

    public ?Prestation $prestationDuo = null;

    #[Assert\NotNull(message: 'Indiquez la réduction (0 si aucune).')]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Entre {{ min }} et {{ max }} %.')]
    public ?int $remise = 0;

    /** Envoyer le récapitulatif à jour à la cliente. */
    public bool $prevenir = true;

    public static function depuis(Reservation $reservation): self
    {
        $modification = new self();
        $modification->prestation = $reservation->getPrestation();
        $modification->supplement = $reservation->getSupplement();
        $modification->date = $reservation->getDebut()->setTime(0, 0);
        $modification->heure = $reservation->getDebut();
        $modification->duo = $reservation->estDuo();
        $modification->prestationDuo = $reservation->getPrestationDuo();
        $modification->remise = $reservation->getRemisePourcentage();

        return $modification;
    }

    #[Assert\Callback]
    public function validerOptions(ExecutionContextInterface $contexte): void
    {
        if ($this->duo && null === $this->prestationDuo) {
            $contexte->buildViolation('Choisissez la prestation de la deuxième personne.')->atPath('prestationDuo')->addViolation();
        }
        if (null !== $this->supplement && null !== $this->prestation && !$this->supplement->estProposePour($this->prestation)) {
            $contexte->buildViolation('Ce supplément n\'est pas proposé pour cette prestation (ou il est désactivé).')->atPath('supplement')->addViolation();
        }
    }

    public function debut(): \DateTimeImmutable
    {
        return ($this->date ?? new \DateTimeImmutable('today'))->setTime((int) $this->heure?->format('G'), (int) $this->heure?->format('i'));
    }
}
