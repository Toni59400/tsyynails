---
name: tsyynails-security-rgpd
description: Exigences RGPD et cybersécurité du projet Tsyynails. Utiliser pour toute fonctionnalité qui manipule des données clientes (fiche, notes santé/allergies, téléphone, email), l'authentification, les comptes, l'admin, le paiement Stripe, la carte fidélité QR, les emails, les logs, l'upload de photos, les en-têtes HTTP, le déploiement ou les secrets. Déclencher dès qu'une tâche touche des données personnelles ou la sécurité, même partiellement.
---

# Tsyynails — RGPD et cybersécurité

## RGPD

### Données collectées (minimisation)

- Réservation : prénom, nom, téléphone, email. Rien d'autre sans besoin justifié.
- **Notes santé (allergies, contre-indications) = donnée de santé, catégorie sensible (art. 9 RGPD)** :
  - consentement explicite, distinct, horodaté (`consentementSanteAt`), retirable ;
  - champ **chiffré en base** (libsodium `sodium_crypto_secretbox`, clé dans le coffre de secrets Symfony, jamais dans le dépôt) ;
  - visible uniquement par `ROLE_ADMIN`, jamais dans un email, un log ou un export CSV non demandé.
- Pas de date de naissance, d'adresse postale ni de photo de cliente sans besoin nouveau validé.

### Durées de conservation

| Donnée | Durée | Mise en œuvre |
| --- | --- | --- |
| Fiche cliente inactive | 3 ans après le dernier rendez-vous | `app:rgpd:purger` : anonymisation (nom → « Cliente supprimée », téléphone/email vidés, notes supprimées) |
| Données de facturation / réservations payées | 10 ans (obligation comptable) | conservées, mais anonymisées côté identité |
| Logs techniques | 6 mois à 1 an | rotation Monolog |
| Compte sans activité | 3 ans | email d'avertissement puis suppression |

### Droits des personnes

- Espace compte : consulter, modifier, **exporter (JSON/CSV)** et **supprimer** ses données.
- Sans compte : demande par email, traitée depuis l'admin (bouton « Exporter » / « Anonymiser »).
- La suppression = anonymisation lorsque des obligations comptables imposent de garder la réservation.

### Transparence et documents

- Case de consentement **non pré-cochée** pour les notes santé et pour toute newsletter (les rappels de rendez-vous sont transactionnels, sans consentement).
- Pages obligatoires : mentions légales, politique de confidentialité (finalités, bases légales, durées, droits, contact, sous-traitants), CGV/conditions de réservation (acompte, annulation).
- Sous-traitants à lister : OVH (hébergement, emails), Stripe (paiement), prestataire email éventuel (Brevo).
- **Cookies** : cookies strictement nécessaires (session, CSRF, Stripe, choix de consentement) sans bandeau. Mesure d’audience : Google Tag Manager/Analytics (`GOOGLE_TAG_MANAGER_ID`), injecté par le contrôleur Stimulus `consentement` **seulement après accord** (jamais de balise GTM ni d’iframe noscript dans le HTML) ; « Tout refuser » aussi visible que « Tout accepter », case non pré-cochée, choix conservé 6 mois, lien « Gérer les cookies » en pied de page, retrait = suppression des cookies `_ga`. Tout nouveau traceur (pixel Meta…) = nouvelle finalité dans le bandeau + `VERSION` augmentée + politique de confidentialité + CSP.
- Polices et scripts **auto-hébergés** (AssetMapper), pas de CDN tiers.

## Cybersécurité

### Authentification et accès

- Mots de passe : hasher `auto` (bcrypt/argon2), 12 caractères minimum, contrainte `#[Assert\NotCompromisedPassword]`.
- `login_throttling` activé sur chaque pare-feu (5 tentatives / 15 min).
- **Admin : double authentification TOTP obligatoire** (scheb/2fa-totp), session courte, pas de « se souvenir de moi ».
- Contrôle d'accès : `access_control` pour `/admin` (`ROLE_ADMIN`) et `/compte` (`ROLE_CLIENT`) **et** Voters sur chaque ressource (une cliente ne voit que ses réservations et son solde).
- Réinitialisation de mot de passe : jeton à usage unique, expiration courte (symfonycasts/reset-password-bundle), message identique que l'email existe ou non.
- Régénération de l'identifiant de session à la connexion (défaut Symfony).

### Carte fidélité QR

- Le QR code contient **uniquement un jeton aléatoire opaque** (`random_bytes(16)` minimum, base64url), jamais le nom, le téléphone ni un identifiant séquentiel.
- Scanner une carte ne révèle rien sans être connectée en admin ; seule l'admin peut créditer ou débiter des points.
- Une carte perdue peut être désactivée et remplacée ; le jeton ne se réutilise pas.

### Entrées, sorties, requêtes

- Toute entrée validée côté serveur (contraintes Validator), même si le formulaire valide déjà côté navigateur.
- Échappement Twig automatique : **jamais** de filtre `|raw` sur une donnée saisie par une personne.
- Requêtes Doctrine paramétrées uniquement.
- CSRF actif sur tous les formulaires et toutes les actions qui modifient (POST/DELETE), y compris dans l'admin et les actions Stimulus/fetch.
- Upload de photos (admin) : types MIME vérifiés (`#[Assert\Image]`), taille max, nom de fichier régénéré, stockage hors exécution PHP, métadonnées EXIF supprimées.

### Paiement Stripe

- Aucune donnée de carte ne transite par le serveur : Stripe Elements / Checkout uniquement (périmètre PCI SAQ A).
- Montants calculés côté serveur ; webhook avec **vérification de signature** et traitement idempotent.
- Clés Stripe uniquement dans `.env.local` (dev) et le coffre de secrets Symfony (prod).

### En-têtes et transport

- HTTPS forcé + HSTS, CSP stricte avec nonces, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy` — configurés dans `config/packages/nelmio_security.yaml`. Toute nouvelle source externe (script, iframe, image) doit y être ajoutée explicitement et justifiée.
- Cookies de session `Secure`, `HttpOnly`, `SameSite=Lax`.

### Secrets, logs, exploitation

- Aucun secret dans le dépôt : `.env` ne contient que des valeurs d'exemple ; valeurs réelles dans `.env.local` (non commité) ou `php bin/console secrets:set` (prod).
- Production : `APP_ENV=prod`, `APP_DEBUG=0`, pas de profiler, `composer install --no-dev --optimize-autoloader`, `composer dump-env prod`.
- Journaliser : connexions (réussies/échouées), actions admin (validation, refus, points, suppression de données). **Ne jamais journaliser** mot de passe, jeton, note santé, numéro de carte, contenu d'email.
- `composer audit` avant chaque mise en production ; mises à jour de sécurité Symfony appliquées rapidement.
- Sauvegarde quotidienne de la base (OVH + export hors site chiffré) et test de restauration régulier.

## Checklist avant de livrer une fonctionnalité

- [ ] Les données collectées sont-elles toutes nécessaires ?
- [ ] Une cliente peut-elle accéder aux données d'une autre (tester en changeant un identifiant dans l'URL) ?
- [ ] Les formulaires ont-ils CSRF + validation serveur ?
- [ ] Rien de sensible dans les logs, emails ou messages d'erreur ?
- [ ] Nouvelle donnée personnelle → politique de confidentialité, registre et purge mis à jour ?
- [ ] Nouvelle ressource externe → CSP mise à jour et sous-traitant listé ?
