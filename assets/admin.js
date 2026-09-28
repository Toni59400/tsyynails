/*
 * Point d'entrée de l'administration (EasyAdmin) : écrans sur mesure (tableau de bord, agenda, fiches).
 */
import './styles/admin.css';

// Actions importantes (débit, refus, annulation) : confirmation avant l'envoi.
// Pas d'attribut onsubmit en ligne, interdit par la politique de sécurité (CSP).
document.addEventListener('submit', (evenement) => {
    const message = evenement.target.dataset?.confirmation;
    if (message && !window.confirm(message)) {
        evenement.preventDefault();
    }
});
