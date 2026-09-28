<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** Activité locale : les dates sont stockées et affichées à l'heure de Paris. */
    public const FUSEAU = 'Europe/Paris';

    public function boot(): void
    {
        date_default_timezone_set(self::FUSEAU);

        parent::boot();
    }
}
