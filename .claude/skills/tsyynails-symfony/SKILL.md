---
name: tsyynails-symfony
description: Conventions backend Symfony du projet Tsyynails (site vitrine + réservation + fidélité QR + admin pour une prothésiste ongulaire). Utiliser pour toute génération ou modification de code PHP : entités Doctrine, migrations, repositories, services, contrôleurs, formulaires, commandes console, intégration Stripe, emails, EasyAdmin, tests. Déclencher dès qu'une tâche touche src/, config/, migrations/ ou tests/, même partiellement.
---

# Tsyynails — Backend Symfony

## Contexte à garder en tête

- Symfony **7.4 LTS**, PHP **8.2** (ne pas utiliser de syntaxe PHP 8.3+ : pas de constantes de classe typées, pas de `#[\Override]`).
- Production sur **OVH mutualisé** : pas de worker, pas de Messenger asynchrone, pas de Scheduler, pas de Node, pas de root.
  - Emails envoyés **de façon synchrone** (Mailer sans transport Messenger).
  - Tout traitement différé = une **commande console idempotente** lancée par la tâche planifiée OVH (1 fois par heure).
- Une seule praticienne, un seul agenda. Ne pas sur-architecturer pour du multi-praticiennes.
- Langue du métier : **français** pour les noms d'entités et de concepts métier (`Reservation`, `Prestation`, `CarteFidelite`), anglais pour le technique (`Repository`, `Controller`, `Service`).

## Organisation du code

```
src/
  Controller/          # fins : valident l'entrée, appellent un service, rendent une vue
    Admin/             # EasyAdmin (DashboardController + CrudControllers) et écrans sur mesure
  Entity/  Repository/
  Enum/                # enums PHP backed (string) pour les statuts
  Form/                # FormTypes
  Service/             # logique métier (Reservation/, Fidelite/, Paiement/, Planning/)
  Command/             # commandes cron : app:reservations:expirer, app:rappels:envoyer, app:rgpd:purger
  EventSubscriber/
  Security/            # voters, authenticators
```

- La logique métier vit dans `Service/`, jamais dans les contrôleurs ni les entités « anémiques » à rallonge.
- Injection par constructeur avec `private readonly`. Services `final` par défaut.
- `declare(strict_types=1);` en tête de chaque fichier PHP.

## Modèle de données (référence)

| Entité | Points clés |
| --- | --- |
| `Client` | nom, prénom, téléphone (E.164), email ; `User` optionnel (compte) ; notes santé **chiffrées** ; `consentementSanteAt` |
| `User` | email + mot de passe haché, rôles `ROLE_CLIENT` / `ROLE_ADMIN`, TOTP pour l'admin |
| `Prestation` | nom, description, `prixCentimes` (int), `dureeMinutes` (int), `points` (int), active, photo |
| `Reservation` | client, prestation, `debut`/`fin` (DateTimeImmutable), statut (enum), `stripePaymentIntentId`, `acompteCentimes`, `reductionCentimes`, `pointsUtilises` |
| `HoraireOuverture` | jour de semaine, heure début / fin (plusieurs plages par jour pour les pauses) |
| `Indisponibilite` | début / fin, motif (congés, fermeture) |
| `CarteFidelite` | `token` aléatoire unique (≥ 128 bits, base64url), client, date d'association |
| `MouvementPoints` | client, delta (+/-), motif, réservation liée, auteur, date — **journal en ajout seul** |
| `Photo` | fichier, légende, ordre, publiée |

- **Solde de points = somme des `MouvementPoints`**, jamais un champ modifiable directement. Une correction = un mouvement inverse.
- **Argent en centimes (int)**, jamais en float.
- **Dates** : `DateTimeImmutable` ; stockage et affichage en `Europe/Paris` (activité locale). Passer une `ClockInterface` aux services pour pouvoir tester.
- Statuts de réservation (`App\Enum\StatutReservation`) : `EN_ATTENTE`, `CONFIRMEE`, `REFUSEE`, `EXPIREE`, `ANNULEE`, `HONOREE`, `NON_HONOREE`. Les transitions passent par un seul service (`ReservationWorkflow`), qui refuse les transitions invalides.

## Règles métier

- **Créneaux** : calculés à partir des horaires − indisponibilités − réservations `EN_ATTENTE`/`CONFIRMEE`, par pas de 15 min, selon la durée de la prestation. Une demande `EN_ATTENTE` **bloque** le créneau.
- **Anti double réservation** : vérifier la disponibilité **dans une transaction** avec verrou (`SELECT … FOR UPDATE` ou verrou applicatif `symfony/lock` en store `flock`/PDO) au moment de créer la demande.
- **Acompte Stripe** : `PaymentIntent` avec `capture_method=manual`. Capture à la validation, annulation au refus. L'empreinte expire après 7 jours : la commande `app:reservations:expirer` annule les demandes non traitées avant ce délai.
- **Montants toujours recalculés côté serveur** (prix, réduction fidélité, acompte). Ne jamais faire confiance à un montant venant du navigateur.
- **Webhooks Stripe** : vérifier la signature (`Webhook::constructEvent`), traiter de façon **idempotente** (stocker l'id d'événement traité).
- **Fidélité** : points crédités au scan au salon ou au passage à `HONOREE` — une seule fois par réservation (contrainte d'unicité). Conversion points → € selon un paramètre réglable dans l'admin.

## Doctrine

- Attributs PHP pour le mapping, `#[ORM\Index]` sur les colonnes filtrées (dates de réservation, statut, token de carte).
- Chaque changement de schéma = une migration générée (`make:migration`), relue, commitée. Jamais `doctrine:schema:update --force`.
- Requêtes via QueryBuilder/DQL paramétrés ; aucune concaténation de valeurs dans une requête.
- Pas de `cascade: remove` sur les données comptables (réservations, mouvements de points).

## Contrôleurs et formulaires

- Validation via contraintes `#[Assert\…]` sur les DTO/entités + FormTypes ; CSRF actif sur tous les formulaires (défaut Symfony, ne pas le désactiver).
- Accès contrôlé par `#[IsGranted]` et des **Voters** (une cliente n'accède qu'à ses propres réservations).
- Routes en attributs, noms préfixés : `app_…` (public), `admin_…` (admin), `compte_…` (espace cliente).

## Commandes cron (OVH)

- Idempotentes, rapides (< 1 min), journalisées via Monolog.
- Exemple de ligne de tâche planifiée OVH : `php bin/console app:rappels:envoyer --env=prod`.

## Qualité — à lancer avant chaque commit

```bash
vendor/bin/php-cs-fixer fix
vendor/bin/phpstan analyse        # niveau 6 minimum
php bin/console lint:container && php bin/console lint:twig templates && php bin/console lint:yaml config
vendor/bin/phpunit
composer audit
```

- Tests unitaires pour les services métier (calcul de créneaux, workflow de réservation, solde de points).
- Tests fonctionnels (`WebTestCase`) pour les accès : une cliente ne voit pas les données d'une autre, l'admin exige `ROLE_ADMIN`.
- Stripe mocké dans les tests (interface `PaiementGateway` devant le SDK).

Pour tout ce qui touche aux données personnelles ou à la sécurité, appliquer aussi le skill `tsyynails-security-rgpd`.
