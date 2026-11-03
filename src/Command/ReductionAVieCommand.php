<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Fidelite\ReductionAVie;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Statut VIP : nouvelles VIP au seuil, rappels 30 jours avant l'anniversaire,
 * puis bilan annuel (+5 % ou maintien). Idempotente, lancée chaque heure.
 */
#[AsCommand(name: 'app:fidelite:reduction-a-vie', description: 'Accorde, rappelle et fait progresser le statut VIP')]
final class ReductionAVieCommand extends Command
{
    public function __construct(private readonly ReductionAVie $reductionAVie)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->reductionAVie->traiter();
        (new SymfonyStyle($input, $output))->success(\sprintf(
            'Statut VIP : %d accordé(s), %d progression(s), %d maintien(s), %d rappel(s).',
            $stats['accordees'], $stats['progressions'], $stats['maintenues'], $stats['averties'],
        ));

        return Command::SUCCESS;
    }
}
