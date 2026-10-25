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
 * Réduction à vie : nouvelles clientes au seuil, rappels 30 jours avant l'anniversaire,
 * puis révision annuelle (+5 % ou perte). Idempotente, lancée chaque heure.
 */
#[AsCommand(name: 'app:fidelite:reduction-a-vie', description: 'Accorde, rappelle et révise la réduction fidélité à vie')]
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
            'Réduction à vie : %d accordée(s), %d renouvelée(s), %d perdue(s), %d rappel(s).',
            $stats['accordees'], $stats['renouvelees'], $stats['perdues'], $stats['averties'],
        ));

        return Command::SUCCESS;
    }
}
