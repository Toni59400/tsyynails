# tsyynails

Site vitrine, réservation en ligne et programme de fidélité pour Tsyynails, prothésiste ongulaire.

## Prérequis

- PHP 8.2+ avec les extensions `intl`, `pdo_mysql`, `sodium`
- Composer 2
- MySQL 8
- [Symfony CLI](https://symfony.com/download) (recommandé en local)

## Installation locale

```bash
composer install
cp .env .env.local        # puis renseigner DATABASE_URL, APP_SECRET, clés Stripe de test
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
symfony serve -d
```

## Données de démonstration

```bash
php bin/console app:demo:charger            # base vide
php bin/console app:demo:charger --purger   # remplace les données existantes
```

Prestations, horaires, congés, 40 clientes fictives, environ 400 réservations sur 10 semaines passées et 4 à venir, points de fidélité, cartes et photos de galerie illustrées. Les comptes utilisateurs ne sont jamais touchés. Commande refusée en production.

## Compte administrateur

```bash
php bin/console app:admin:creer adresse@example.com   # le mot de passe est demandé (saisie masquée)
```

Se connecter ensuite sur `/connexion`. À la première connexion, l'admin doit activer la double authentification en scannant un QR code avec une application (Google Authenticator, Microsoft Authenticator…). Le code est ensuite demandé à chaque connexion.

## Qualité

```bash
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse
vendor/bin/phpunit
composer audit
```
