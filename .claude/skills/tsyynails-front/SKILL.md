---
name: tsyynails-front
description: Conventions frontend du projet Tsyynails (Twig, Stimulus, Turbo, AssetMapper, CSS). Utiliser pour toute création ou modification de templates, pages du site vitrine, tunnel de réservation, espace cliente, écrans admin sur mesure (agenda, scan QR), styles, contrôleurs Stimulus, SEO et accessibilité. Déclencher dès qu'une tâche touche templates/ ou assets/, même partiellement.
---

# Tsyynails — Frontend

## Pile

- **Twig** rendu serveur + **Stimulus** pour l'interactivité + **Turbo** pour la navigation fluide.
- **AssetMapper** + importmap : **aucun build Node**, aucun `npm`. Paquets JS ajoutés avec `php bin/console importmap:require <paquet>` (téléchargés dans `assets/vendor/`, jamais servis depuis un CDN).
- CSS natif (variables, nesting, `clamp()`), un fichier par zone : `assets/styles/app.css` (base + tokens), `components/`, `pages/`. Pas de framework CSS lourd.
- Scan QR : `html5-qrcode` via importmap, dans un contrôleur Stimulus `qr-scanner` ; fonctionne uniquement en HTTPS (caméra).

## Identité visuelle : « doux & élégant »

- Tons nude, rose poudré, beige ; typographies fines ; beaucoup d'espace ; les photos des réalisations sont les héroïnes.
- La charte définitive vient de la refonte (Claude Design). En attendant, **toutes** les couleurs, polices, rayons et espacements passent par des variables dans `assets/styles/app.css` :

```css
:root {
  --color-bg: #fbf7f4;
  --color-surface: #ffffff;
  --color-primary: #c98f8f;     /* rose poudré — à confirmer par la charte */
  --color-primary-ink: #7a4747; /* texte sur fond clair, contraste AA */
  --color-text: #3b3230;
  --color-muted: #7d716d;
  --font-heading: "…", Georgia, serif;
  --font-body: "…", system-ui, sans-serif;
  --radius: 12px;
  --space: 8px;
}
```

- Aucune couleur écrite en dur ailleurs que dans ces variables.
- Polices **auto-hébergées** (`assets/fonts/`, `font-display: swap`), jamais chargées depuis Google Fonts (RGPD + CSP).
- Les pastels manquent souvent de contraste : vérifier **4,5:1** pour le texte courant, 3:1 pour les gros titres et les bordures de champs.

## Règles de mise en page

- **Mobile d'abord** : la clientèle arrive depuis Instagram sur téléphone. Concevoir à 375 px, puis élargir.
- Cibles tactiles ≥ 44 × 44 px ; bouton « Réserver » visible sans défiler sur chaque page vitrine.
- Tunnel de réservation en étapes courtes : prestation → créneau → coordonnées (+ connexion facultative) → points fidélité → acompte → récapitulatif.
- Admin sur mesure (agenda, scan) pensé pour le téléphone de la prothésiste : une main, gros boutons, actions confirmées.

## Twig

- Un gabarit de base `base.html.twig`, puis `layout/public.html.twig`, `layout/compte.html.twig` ; l'admin EasyAdmin a son propre layout.
- Composants réutilisables via **Twig Components** (`templates/components/`) : `Button`, `PrestationCard`, `CreneauPicker`, `Flash`…
- Échappement automatique conservé ; **jamais** `|raw` sur une donnée saisie.
- Textes traduits via `translations/messages.fr.yaml` (clés `site.accueil.titre`…) pour faciliter la relecture du contenu.
- Scripts inline interdits hors nonce CSP : `<script nonce="{{ csp_nonce('script') }}">`. Préférer un contrôleur Stimulus.

## Stimulus / Turbo

- Un contrôleur = une responsabilité (`creneaux`, `qr-scanner`, `stripe-payment`, `galerie`), dans `assets/controllers/`.
- Toute requête `fetch` qui modifie des données envoie le jeton CSRF.
- Stripe Elements monté dans un contrôleur `stripe-payment` ; la clé publique passe par une valeur Stimulus (`data-stripe-payment-public-key-value`).
- Turbo Frames pour recharger le sélecteur de créneaux sans recharger la page.

## Accessibilité (RGAA / WCAG AA)

- HTML sémantique : un seul `h1` par page, titres dans l'ordre, `<nav>`, `<main>`, `<footer>`.
- Chaque champ a un `<label>` ; erreurs de formulaire annoncées (`aria-describedby`) et pas seulement en couleur.
- Images : `alt` descriptif pour les réalisations (« French rose poudré sur ongles courts »), `alt=""` pour les images décoratives.
- Navigation complète au clavier, focus visible, respect de `prefers-reduced-motion`.
- `lang="fr"` sur `<html>`.

## SEO local et performance

- Balises `<title>` et `meta description` propres à chaque page (bloc `meta` du gabarit de base).
- Données structurées JSON-LD `NailSalon` / `LocalBusiness` (adresse, horaires, téléphone) sur l'accueil, avec nonce CSP.
- URLs lisibles en français (`/prestations`, `/reservation`, `/galerie`), `sitemap.xml`, `robots.txt`.
- Images en WebP/AVIF, dimensions `width`/`height` renseignées, `loading="lazy"` hors premier écran.
- Objectif Lighthouse mobile ≥ 90 sur performance, accessibilité, bonnes pratiques et SEO.

Pour les données clientes et la sécurité (CSP, formulaires, cookies), appliquer aussi le skill `tsyynails-security-rgpd`.
