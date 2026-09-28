<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreerAdminCommandTest extends KernelTestCase
{
    public function testCreeUnAdminAvecUnMotDePasseHache(): void
    {
        $tester = $this->commande();
        $tester->setInputs(['un-mot-de-passe-solide', 'un-mot-de-passe-solide']);

        $tester->execute(['email' => 'Proth@Example.com']);

        $tester->assertCommandIsSuccessful();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'proth@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isAdmin());
        self::assertNotSame('un-mot-de-passe-solide', $user->getPassword());
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testRefuseDeuxMotsDePasseDifferents(): void
    {
        $tester = $this->commande();
        $tester->setInputs(['un-mot-de-passe-solide', 'un-autre-mot-de-passe']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => 'proth@example.com']));
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'proth@example.com']));
    }

    public function testRefuseUnEmailInvalide(): void
    {
        self::assertSame(Command::INVALID, $this->commande()->execute(['email' => 'pas-un-email']));
    }

    private function commande(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:admin:creer'));
    }
}
