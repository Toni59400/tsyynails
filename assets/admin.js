/*
 * Point d'entrée JavaScript de l'administration (EasyAdmin).
 * La feuille de style admin.css est ajoutée par DashboardController::configureAssets() (balise <link>) :
 * un import CSS ici deviendrait un module « data: », refusé par la CSP de production.
 */

// Actions importantes (débit, refus, annulation) : confirmation avant l'envoi.
// Pas d'attribut onsubmit en ligne, interdit par la politique de sécurité (CSP).
document.addEventListener('submit', (evenement) => {
    const message = evenement.target.dataset?.confirmation;
    if (message && !window.confirm(message)) {
        evenement.preventDefault();
    }
});
