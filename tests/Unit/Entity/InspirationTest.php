<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Inspiration;
use PHPUnit\Framework\TestCase;

final class InspirationTest extends TestCase
{
    public function testLeSlugSuitLeNom(): void
    {
        $inspiration = new Inspiration('  Inspiration Été ');

        self::assertSame('Inspiration Été', $inspiration->getNom());
        self::assertSame('inspiration-ete', $inspiration->getSlug());

        $inspiration->setNom('Noël & fêtes');
        self::assertSame('noel-fetes', $inspiration->getSlug());
    }
}
