import { Controller } from '@hotwired/stimulus';

/*
 * Saisie de la carte avec Stripe Elements : la carte va directement chez Stripe,
 * jamais sur notre serveur. Stripe.js doit être chargé depuis js.stripe.com (exigence PCI DSS),
 * seule exception à la règle « aucun script tiers », autorisée dans la CSP.
 */
const URL_STRIPE_JS = 'https://js.stripe.com/v3/';

function chargerStripe() {
    if (window.Stripe) {
        return Promise.resolve(window.Stripe);
    }

    return new Promise((resoudre, rejeter) => {
        let script = document.querySelector(`script[src="${URL_STRIPE_JS}"]`);
        if (!script) {
            script = document.createElement('script');
            script.src = URL_STRIPE_JS;
            document.head.appendChild(script);
        }
        script.addEventListener('load', () => resoudre(window.Stripe));
        script.addEventListener('error', () => rejeter(new Error('Stripe.js indisponible')));
    });
}

export default class extends Controller {
    static targets = ['element', 'erreur', 'bouton'];
    static values = { clePublique: String, secretClient: String, urlRetour: String };

    async connect() {
        try {
            const Stripe = await chargerStripe();
            this.stripe = Stripe(this.clePubliqueValue);
            this.elements = this.stripe.elements({
                clientSecret: this.secretClientValue,
                locale: 'fr',
                appearance: { theme: 'stripe', variables: { colorPrimary: '#7a4747', borderRadius: '8px' } },
            });
            this.elementTarget.replaceChildren();
            this.elements.create('payment').mount(this.elementTarget);
            this.boutonTarget.disabled = false;
        } catch (erreur) {
            this.afficherErreur('Le paiement sécurisé ne peut pas se charger. Vérifiez votre connexion, puis rechargez la page.');
        }
    }

    async payer(evenement) {
        evenement.preventDefault();
        this.boutonTarget.disabled = true;
        this.erreurTarget.hidden = true;

        const { error } = await this.stripe.confirmPayment({
            elements: this.elements,
            confirmParams: { return_url: this.urlRetourValue },
        });

        // En cas de succès, Stripe redirige vers la page de suivi : on n'arrive ici qu'en cas d'erreur.
        this.afficherErreur(error.message);
        this.boutonTarget.disabled = false;
    }

    afficherErreur(message) {
        this.erreurTarget.textContent = message;
        this.erreurTarget.hidden = false;
    }
}
