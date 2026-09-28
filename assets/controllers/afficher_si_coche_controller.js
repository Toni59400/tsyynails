import { Controller } from '@hotwired/stimulus';

/*
 * Affiche un bloc seulement quand la case est cochée (ex. : mot de passe du compte).
 * Sans JavaScript, le bloc reste visible : le formulaire fonctionne quand même.
 */
export default class extends Controller {
    static targets = ['case', 'bloc'];

    connect() {
        this.basculer();
    }

    basculer() {
        this.blocTargets.forEach((bloc) => {
            bloc.hidden = !this.caseTarget.checked;
        });
    }
}
