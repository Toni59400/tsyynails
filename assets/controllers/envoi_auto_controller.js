import { Controller } from '@hotwired/stimulus';

/*
 * Envoie le formulaire dès qu'un choix change (ex. : supplément du tunnel de réservation).
 * Sans JavaScript, le bouton reste visible et fait la même chose.
 */
export default class extends Controller {
    static targets = ['bouton'];

    connect() {
        if (this.hasBoutonTarget) {
            this.boutonTarget.hidden = true;
        }
    }

    envoyer() {
        this.element.requestSubmit();
    }
}
