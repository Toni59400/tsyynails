import { Controller } from '@hotwired/stimulus';
import { COOKIE_CONSENTEMENT, VERSION_CONSENTEMENT, choixConsentement, envoyerEvenementsEnAttente } from '../mesure.js';

/*
 * Consentement aux cookies de mesure d'audience (recommandations CNIL) :
 * - aucun script Google n'est chargé avant l'accord (Google Tag Manager est injecté seulement après « Accepter ») ;
 * - refuser est aussi simple qu'accepter, et le choix est modifiable à tout moment (lien « Gérer les cookies ») ;
 * - le choix, accord comme refus, est conservé 6 mois, puis redemandé ;
 * - retirer son accord supprime les cookies Google Analytics déjà déposés.
 * Mode de consentement Google v2 : publicité toujours refusée, mesure d'audience selon le choix.
 */
const DUREE_SECONDES = 6 * 30 * 24 * 3600;
const COOKIES_GOOGLE = /^(_ga|_ga_.+|_gid|_gat.*|_gcl_.+)$/;

export default class extends Controller {
    static targets = ['bandeau', 'details', 'statistiques', 'titre'];
    static values = { gtm: String };

    connect() {
        const choix = choixConsentement();
        if (choix === null) {
            this.bandeauTarget.hidden = false;
        } else if (choix.statistiques) {
            this.chargerGtm();
        }
    }

    accepter() {
        this.enregistrer(true);
    }

    refuser() {
        this.enregistrer(false);
    }

    enregistrerDetails() {
        this.enregistrer(this.statistiquesTarget.checked);
    }

    personnaliser() {
        this.detailsTarget.hidden = false;
        this.statistiquesTarget.focus();
    }

    /** Lien « Gérer les cookies » du pied de page : rouvre le bandeau avec le choix actuel. */
    ouvrir(evenement) {
        evenement.preventDefault();
        this.statistiquesTarget.checked = choixConsentement()?.statistiques === true;
        this.detailsTarget.hidden = false;
        this.bandeauTarget.hidden = false;
        this.titreTarget.focus();
    }

    enregistrer(statistiques) {
        const valeur = encodeURIComponent(JSON.stringify({ v: VERSION_CONSENTEMENT, statistiques, date: new Date().toISOString() }));
        document.cookie = `${COOKIE_CONSENTEMENT}=${valeur}; Max-Age=${DUREE_SECONDES}; Path=/; SameSite=Lax${location.protocol === 'https:' ? '; Secure' : ''}`;

        if (statistiques) {
            this.chargerGtm();
        } else {
            retirerStatistiques();
        }
        this.bandeauTarget.hidden = true;
        this.detailsTarget.hidden = true;
    }

    chargerGtm() {
        const identifiant = this.gtmValue;
        if (!/^GTM-[A-Z0-9]+$/.test(identifiant)) {
            return;
        }
        consentementGoogle('update', { analytics_storage: 'granted' });
        // Une seule fois par visite : Turbo conserve la page et ses scripts d'une navigation à l'autre.
        if (window.tsyynailsGtmCharge) {
            envoyerEvenementsEnAttente();

            return;
        }
        window.tsyynailsGtmCharge = true;
        window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
        const script = document.createElement('script');
        script.async = true;
        script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(identifiant)}`;
        document.head.appendChild(script);
        envoyerEvenementsEnAttente();
    }
}

/** Consentement par défaut (tout refusé), puis mise à jour selon le choix. */
function consentementGoogle(action, reglages) {
    window.dataLayer = window.dataLayer || [];
    if (!window.tsyynailsConsentementInitialise) {
        window.tsyynailsConsentementInitialise = true;
        gtag('consent', 'default', {
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
            analytics_storage: 'denied',
        });
    }
    gtag('consent', action, reglages);
}

// Google Tag Manager attend l'objet « arguments », pas un tableau.
function gtag() {
    window.dataLayer.push(arguments); // eslint-disable-line prefer-rest-params
}

function retirerStatistiques() {
    if (window.tsyynailsConsentementInitialise) {
        consentementGoogle('update', { analytics_storage: 'denied' });
    }
    // Les cookies Google sont posés sur le domaine principal (.tsyyart.fr) : on les efface partout.
    const domaines = ['', location.hostname, `.${location.hostname.replace(/^www\./, '')}`];
    document.cookie.split(';').map((c) => c.split('=')[0].trim()).filter((nom) => COOKIES_GOOGLE.test(nom)).forEach((nom) => {
        domaines.forEach((domaine) => {
            document.cookie = `${nom}=; Max-Age=0; Path=/${domaine ? `; Domain=${domaine}` : ''}`;
        });
    });
}
