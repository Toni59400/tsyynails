<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée (ou promeut) un compte administrateur.
 * Le mot de passe est demandé de façon masquée, jamais passé en argument
 * (il resterait dans l'historique du terminal). La saisie n'est visible que
 * lorsque les réponses arrivent d'un flux fourni par un test (CommandTester),
 * car la saisie masquée sous Windows attend toujours le vrai clavier.
 */
#[AsCommand(name: 'app:admin:creer', description: 'Crée un compte administrateur (la prothésiste)')]
final class CreerAdminCommand extends Command
{
    private const LONGUEUR_MIN = 12;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse email de connexion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));

        if (\count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) > 0) {
            $io->error('Adresse email invalide.');

            return Command::INVALID;
        }

        $masquer = !$input instanceof StreamableInputInterface || null === $input->getStream();

        $question = new Question(\sprintf('Mot de passe (%d caractères minimum)', self::LONGUEUR_MIN));
        $question->setHidden($masquer)->setValidator(function (?string $valeur): string {
            if (null === $valeur || mb_strlen($valeur) < self::LONGUEUR_MIN) {
                throw new \RuntimeException(\sprintf('Le mot de passe doit contenir au moins %d caractères.', self::LONGUEUR_MIN));
            }

            return $valeur;
        });
        $motDePasse = (string) $io->askQuestion($question);

        $confirmation = (new Question('Confirmez le mot de passe'))->setHidden($masquer);
        if ($motDePasse !== $io->askQuestion($confirmation)) {
            $io->error('Les deux mots de passe sont différents.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]) ?? new User($email);
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($this->hasher->hashPassword($user, $motDePasse));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Compte administrateur prêt : %s', $email));
        $io->note('À la première connexion sur /connexion, le site demandera d\'activer la double authentification avec une application (Google Authenticator, Microsoft Authenticator…).');

        return Command::SUCCESS;
    }
}
