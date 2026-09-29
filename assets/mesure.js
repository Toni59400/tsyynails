/*
 * Mesure d'audience : événements envoyés à Google Tag Manager (dataLayer), uniquement si la visiteuse
 * a accepté les statistiques. Jamais de donnée personnelle (nom, email, téléphone, jeton de suivi).
 *
 * - Clics : téléphone, Instagram, itinéraire, bouton « Réserver » (détectés d'après le lien).
 * - Actions côté serveur (demande de rendez-vous, inscription) : balises <template data-mesure="{…}">
 *   rendues par Twig, envoyées par le contrôleur « consentement » une fois GTM chargé (à l'affichage ou à l'accord).
 */
export const COOKIE_CONSENTEMENT = 'tsyynails_consentement';
export const VERSION_CONSENTEMENT = 1; // à augmenter si les finalités changent : le choix est alors redemandé

/** Choix enregistré ({v, statistiques, date}) ou null s'il faut (re)demander. */
export function choixConsentement() {
    const brut = document.cookie.split('; ').find((c) => c.startsWith(`${COOKIE_CONSENTEMENT}=`));
    if (!brut) {
        return null;
    }
    try {
        const choix = JSON.parse(decodeURIComponent(brut.slice(COOKIE_CONSENTEMENT.length + 1)));
        return choix.v === VERSION_CONSENTEMENT && typeof choix.statistiques === 'boolean' ? choix : null;
    } catch {
        return null;
    }
}

export function statistiquesAcceptees() {
    return choixConsentement()?.statistiques === true;
}

export function suivre(evenement, parametres = {}) {
    // GTM chargé par le contrôleur « consentement » : le consentement est ainsi toujours transmis avant les événements.
    if (!statistiquesAcceptees() || !window.tsyynailsGtmCharge) {
        return;
    }
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: evenement, ...parametres });
}

/** Envoie les événements préparés par le serveur, une seule fois chacun. */
export function envoyerEvenementsEnAttente() {
    if (!statistiquesAcceptees() || !window.tsyynailsGtmCharge) {
        return;
    }
    document.querySelectorAll('template[data-mesure]').forEach((balise) => {
        try {
            const { event, ...parametres } = JSON.parse(balise.dataset.mesure);
            suivre(event, parametres);
        } catch {
            // Balise illisible : ignorée.
        }
        balise.remove();
    });
    // Retour de Stripe : l'adresse porte « redirect_status » ; on le retire pour ne pas recompter au rechargement.
    const url = new URL(window.location.href);
    if (url.searchParams.has('redirect_status')) {
        ['redirect_status', 'payment_intent', 'payment_intent_client_secret'].forEach((p) => url.searchParams.delete(p));
        window.history.replaceState(window.history.state, '', url);
    }
}

function evenementDuLien(lien) {
    const href = lien.getAttribute('href') ?? '';
    if (href.startsWith('tel:')) {
        return 'clic_telephone';
    }
    if (href.startsWith('mailto:')) {
        return 'clic_email';
    }
    if (/instagram\.com/i.test(href)) {
        return 'clic_instagram';
    }
    if (/google\.[a-z.]+\/maps|maps\.google|maps\.apple\.com|waze\.com/i.test(href)) {
        return 'clic_itineraire';
    }
    if (lien.pathname === '/reservation' && lien.origin === window.location.origin && window.location.pathname !== '/reservation') {
        return 'clic_reserver';
    }

    return null;
}

document.addEventListener('click', (evenement) => {
    const lien = evenement.target instanceof Element ? evenement.target.closest('a[href]') : null;
    const nom = lien ? evenementDuLien(lien) : null;
    if (nom) {
        suivre(nom, { lien_texte: lien.textContent.trim().slice(0, 60) });
    }
});
