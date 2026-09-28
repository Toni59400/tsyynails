<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Client;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\MouvementPointsRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ChargerDonneesDemoCommandTest extends KernelTestCase
{
    public function testRemplitLaBaseSansToucherAuxComptes(): void
    {
        $tester = $this->commande();
        $admin = new User('admin@example.com');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($admin);
        $entityManager->flush();

        $tester->execute(['--purger' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertCount(40, static::getContainer()->get(ClientRepository::class)->findAll());
        self::assertNotNull(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']));
    }

    public function testRefuseDeRemplirUneBaseDejaRemplieSansPurger(): void
    {
        $this->commande()->execute(['--purger' => true]);

        self::assertSame(Command::FAILURE, $this->commande()->execute([]));
    }

    public function testAucunSoldeDePointsNegatif(): void
    {
        $this->commande()->execute(['--purger' => true]);

        $mouvements = static::getContainer()->get(MouvementPointsRepository::class);
        foreach (static::getContainer()->get(ClientRepository::class)->findAll() as $cliente) {
            self::assertInstanceOf(Client::class, $cliente);
            self::assertGreaterThanOrEqual(0, $mouvements->soldePour($cliente), $cliente->getNomComplet());
        }
    }

    private function commande(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:demo:charger'));
    }
}
