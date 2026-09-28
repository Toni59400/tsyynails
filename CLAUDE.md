# Tsyynails

Site d'une prothésiste ongulaire (seule praticienne, salon fixe) : vitrine, réservation en ligne avec acompte Stripe et validation manuelle, programme de fidélité par carte QR, administration utilisable sur téléphone.

Recueil des besoins : https://claude.ai/code/artifact/4481a534-da45-4320-9e53-4eb8d83e2b17

## Pile

- Symfony 7.4 LTS, PHP 8.2, MySQL 8 (Doctrine + migrations)
- Twig + Stimulus/Turbo via AssetMapper (pas de Node)
- EasyAdmin 5 pour l'admin, scheb/2fa pour la double authentification
- Stripe (PaymentIntent en capture manuelle), Symfony Mailer
- Hébergement OVH mutualisé : pas de worker ni de Messenger asynchrone ; les traitements différés sont des commandes console lancées par la tâche planifiée horaire

## Skills du projet — à appliquer

- `tsyynails-symfony` : tout code PHP (entités, services, contrôleurs, Stripe, commandes, tests)
- `tsyynails-front` : templates Twig, Stimulus, CSS, accessibilité, SEO
- `tsyynails-security-rgpd` : dès qu'une donnée cliente, l'authentification, le paiement ou la sécurité est concerné

## Commandes

```bash
symfony serve -d                       # serveur local
php bin/console make:migration         # après modification d'entité
php bin/console doctrine:migrations:migrate
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse
vendor/bin/phpunit
composer audit
```

## À ne pas faire

- Committer un secret (`.env.local`, clés Stripe, clé de chiffrement)
- Ajouter une dépendance qui exige un worker, Node ou PHP > 8.2
- Utiliser `doctrine:schema:update --force` au lieu d'une migration
- Charger un script, une police ou une image depuis un CDN tiers
