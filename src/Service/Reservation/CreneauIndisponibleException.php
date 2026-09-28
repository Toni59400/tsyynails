<?php

declare(strict_types=1);

namespace App\Service\Reservation;

final class CreneauIndisponibleException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Ce créneau vient d\'être pris. Choisissez-en un autre.');
    }
}
