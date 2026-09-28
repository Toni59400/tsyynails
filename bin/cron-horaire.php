#!/usr/bin/env php
<?php

/*
 * Tâche planifiée OVH, toutes les heures.
 * Espace client OVH › Hébergements › Tâches planifiées (Cron) :
 *   commande : tsyynails/bin/cron-horaire.php   langage : PHP 8.2   fréquence : toutes les heures
 * Lance uniquement app:reservations:expirer (le planificateur OVH ne passe pas d'arguments de façon fiable).
 */

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

if (!is_dir(dirname(__DIR__).'/vendor')) {
    throw new LogicException('Dépendances manquantes : lancez "composer install".');
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    $application = new Application(new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']));
    $application->setDefaultCommand('app:reservations:expirer', true);

    return $application;
};
