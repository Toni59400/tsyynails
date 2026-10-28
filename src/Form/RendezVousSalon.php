<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Prestation;
use App\Entity\Supplement;
use App\Service\Reservation\CoordonneesCliente;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Rendez-vous saisi par la prothésiste (téléphone, au salon) : cliente retrouvée par son téléphone ou créée.
 */
final class RendezVousSalon
{
    #[Assert\NotBlank(message: 'Indiquez le prénom.')]
    #[Assert\Length(max: 100)]
    public string $prenom = '';

    #[Assert\NotBlank(message: 'Indiquez le nom.')]
    #[Assert\Length(max: 100)]
    public string $nom = '';

    #[Assert\NotBlank(message: 'Indiquez le téléphone.')]
    #[Assert\Callback([CoordonneesCliente::class, 'validerTelephone'])]
    public string $telephone = '';

    /** Obligatoire : confirmation, rappel de la veille et suivi du rendez-vous sont envoyés par email. */
    #[Assert\NotBlank(message: 'Indiquez l\'email de la cliente.')]
    #[Assert\Email(message: 'Adresse email invalide.')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotNull(message: 'Choisissez la prestation.')]
    public ?Prestation $prestation = null;

    public ?Supplement $supplement = null;

    #[Assert\NotNull(message: 'Choisissez la date.')]
    public ?\DateTimeImmutable $date = null;

    #[Assert\NotNull(message: 'Choisissez l\'heure.')]
    public ?\DateTimeImmutable $heure = null;

    public bool $duo = false;

    public ?Prestation $prestationDuo = null;

    /** Réduction totale appliquée au duo, en % (pré-remplie avec le réglage du RDV duo). */
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Entre {{ min }} et {{ max }} %.')]
    public ?int $remiseDuo = null;

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

    public function coordonnees(): CoordonneesCliente
    {
        $coordonnees = new CoordonneesCliente();
        $coordonnees->prenom = $this->prenom;
        $coordonnees->nom = $this->nom;
        $coordonnees->telephone = $this->telephone;
        $coordonnees->email = (string) $this->email;

        return $coordonnees;
    }
}
