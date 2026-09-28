<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\QuestionFrequenteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Question de la FAQ publique (balisée FAQPage pour Google et les moteurs de réponse).
 */
#[ORM\Entity(repositoryClass: QuestionFrequenteRepository::class)]
class QuestionFrequente
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $question;

    /** Réponse courte et factuelle d'abord, détail ensuite. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    private string $reponse;

    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column]
    private bool $publiee = true;

    public function __construct(string $question, string $reponse)
    {
        $this->question = $question;
        $this->reponse = $reponse;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getReponse(): string
    {
        return $this->reponse;
    }

    public function setReponse(string $reponse): static
    {
        $this->reponse = $reponse;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }

    public function isPubliee(): bool
    {
        return $this->publiee;
    }

    public function setPubliee(bool $publiee): static
    {
        $this->publiee = $publiee;

        return $this;
    }
}
