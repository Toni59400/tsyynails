# SEO local et GEO — état et plan d'action

Objectif : apparaître quand on cherche « prothésiste ongulaire Arras », « pose gel Arras », « ongles Arras »…
sur Google (recherche et Maps) et dans les réponses des moteurs génératifs (ChatGPT, Perplexity,
Google AI Overviews, Gemini, Copilot).

Légende : **[toi]** action dans un service en ligne, **[code]** développement sur le site.

## Déjà en place (28/09/2026)

- `<title>` et meta description propres à chaque page, URL canonique
- `sitemap.xml` (pages publiques) et `robots.txt` (admin exclue)
- Données structurées JSON-LD `NailSalon` sur l'accueil : nom, adresse, téléphone, horaires
- HTML sémantique (un seul `h1`, `lang="fr"`), textes alternatifs des photos, mobile d'abord
- HTTPS imposé, domaine unique sans `www`, URL lisibles en français

## Priorité 1 — Fondations locales (le plus fort impact, peu de code)

- [ ] **[toi] Fiche Google Business Profile** : catégorie principale « Prothésiste ongulaire »
  (ou « Salon de manucure »), adresse, téléphone, horaires **identiques au site**, lien vers
  `https://tsyyart.fr/reservation`, 10 à 20 photos de réalisations, description avec les prestations.
  C'est ce qui fait apparaître le salon dans Google Maps et le « pack local ».
- [ ] **[toi] Avis Google** : demander un avis après chaque rendez-vous honoré (lien court de la fiche).
  Répondre à chaque avis. *Idée [code] : lien vers la fiche dans l'email de confirmation.*
- [ ] **[toi] Google Search Console** : valider `tsyyart.fr` (enregistrement TXT dans la zone DNS OVH)
  et soumettre `https://tsyyart.fr/sitemap.xml`.
- [ ] **[toi] Bing Webmaster Tools** : importer depuis Search Console. Bing alimente ChatGPT Search et Copilot.
- [ ] **[toi] Cohérence nom-adresse-téléphone partout** : Instagram (bio + lien vers le site),
  PagesJaunes, annuaires beauté et locaux. Exactement « Tsyynails, 16 rue du Roussillon, 62000 Arras, 06 22 17 60 77 ».
- [ ] **[toi + code] Pages légales définitives** : SIRET, relecture, retrait du bandeau « provisoire »
  (signal de confiance pour Google comme pour les IA).

## Priorité 2 — Technique

- [ ] **[code] Aperçus de partage** Open Graph / Twitter : titre, description, image par page
  (liens partagés sur Instagram, WhatsApp, Facebook).
- [ ] **[code] Favicon**, `apple-touch-icon`, manifeste web.
- [ ] **[code] JSON-LD enrichi** : `image`, `priceRange`, `geo` (latitude/longitude), `sameAs` (Instagram),
  `areaServed` (Arras et communes voisines), `hasOfferCatalog` avec chaque prestation (`Service` + `Offer` :
  prix, durée) ; `BreadcrumbList` sur les pages internes.
- [ ] **[code] Une page par prestation** (`/prestations/pose-complete-gel`) : description détaillée
  (300 à 500 mots), prix, durée, déroulé, entretien, photos liées, bouton Réserver, questions fréquentes.
  Cible les recherches précises (« remplissage gel Arras »). Nécessite un slug sur `Prestation`.
- [ ] **[code] Pages de thèmes indexables** : `/galerie/inspiration-ete` au lieu de `?theme=`, avec texte
  d'introduction ; canonical sur la galerie filtrée.
- [ ] **[code] Sitemap complet** : `lastmod`, pages prestations et thèmes, sitemap d'images.
- [ ] **[code] Images** : conversion WebP, tailles adaptées (`srcset`), vraies dimensions `width`/`height`.
  Vérifier que l'extension GD ou Imagick est disponible sur l'hébergement Pro.
- [ ] **[code] Performance** : cache long des fichiers versionnés (actuellement 15 min, passer à 1 an
  `immutable` via `.htaccess`), polices auto-hébergées, objectif Lighthouse mobile ≥ 90 partout.
- [ ] **[code] Page 404** aux couleurs du site avec liens utiles.

## Priorité 3 — Contenu (la matière que Google et les IA citent)

- [ ] **[toi + code] Page « À propos »** : parcours, formations et certifications, engagement hygiène,
  produits utilisés, photo du salon. Renforce la confiance (critères E-E-A-T de Google) et donne aux IA
  une entité claire à citer.
- [ ] **[toi + code] FAQ** avec données structurées `FAQPage` : tenue d'une pose gel, gel ou semi-permanent,
  fréquence du remplissage, allergies et contre-indications, dépose, prix, acompte et annulation, parking.
  Réponses courtes et factuelles en tête, détail ensuite.
- [ ] **[toi] Photos régulières** dans la galerie et sur la fiche Google (au moins 2 par mois).
- [ ] **[toi + code] Conseils saisonniers** (1 article par mois, facultatif) liés aux thèmes d'inspiration :
  « Tendances ongles automne 2026 », « Préparer ses ongles pour un mariage »…
- [ ] **[code] Communes voisines** : mentionner la zone desservie (Arras, Saint-Laurent-Blangy, Achicourt,
  Dainville, Beaurains…) de façon naturelle, sans pages en double.

## GEO — Moteurs de réponse génératifs

Les IA citent des sources **cohérentes, factuelles et faciles à extraire**, et s'appuient sur des index
existants (Google, Bing) et sur les avis.

- [ ] **[code] Décider de l'accès des robots d'IA** dans `robots.txt` (GPTBot, OAI-SearchBot, PerplexityBot,
  ClaudeBot, Google-Extended…). Recommandation : les autoriser, le site n'a rien à protéger et y gagne en visibilité.
- [ ] **[code] `llms.txt`** à la racine : présentation courte du salon, prestations et prix, zone, liens vers
  les pages clés (format émergent, coût quasi nul).
- [ ] **[code] Informations clés en texte** et non seulement en image : prix, durées, adresse, horaires,
  modalités d'acompte, déjà en grande partie le cas.
- [ ] **[toi] Entité cohérente** : même nom, adresse, téléphone et description sur le site, la fiche Google,
  Instagram et les annuaires ; `sameAs` dans le JSON-LD.
- [ ] **[toi] Être cité ailleurs** : avis Google, annuaires beauté, presse ou blogs locaux (Arras),
  partenariats (esthéticienne, coiffeur, photographe de mariage).
- [ ] **[toi] Test mensuel** : poser « meilleure prothésiste ongulaire à Arras ? » à ChatGPT, Perplexity et
  Gemini, noter si Tsyynails apparaît et quelles sources sont citées.

## Mesure

- [ ] **[toi]** Search Console : impressions, clics et positions sur les requêtes locales, chaque mois.
- [ ] **[toi]** Statistiques de la fiche Google : appels, itinéraires, clics vers le site.
- [ ] **[code]** Mesure d'audience **sans bandeau cookies** : Matomo configuré selon la CNIL (exemption),
  hébergé chez soi ou chez un prestataire européen. Pas de Google Analytics (consentement obligatoire).
- [ ] **[code]** Suivre dans l'admin l'origine des réservations (champ « Comment nous avez-vous connue ? » facultatif).

## Ordre conseillé

1. Fiche Google Business Profile + Search Console + Bing (dans la semaine du lancement)
2. Saisie du contenu réel (prestations, horaires, photos) puis pages légales définitives
3. Aperçus de partage, favicon, JSON-LD enrichi, cache des fichiers
4. Pages par prestation + FAQ + page « À propos »
5. `llms.txt`, robots d'IA, pages de thèmes
6. Collecte d'avis en continu, conseils saisonniers, suivi mensuel
