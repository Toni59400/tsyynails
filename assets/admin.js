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

/*
 * Import de photos en lot : les fichiers partent un par un (l'hébergement limite le nombre
 * de fichiers par requête), avec la progression et le résultat de chaque photo.
 */
document.addEventListener('submit', async (evenement) => {
    const formulaire = evenement.target;
    if (!(formulaire instanceof HTMLFormElement) || !formulaire.matches('[data-import-photos]')) {
        return;
    }
    evenement.preventDefault();

    const fichiers = [...formulaire.querySelector('input[type="file"]').files];
    if (fichiers.length === 0) {
        return;
    }

    const bouton = formulaire.querySelector('[data-import-bouton]');
    const zone = formulaire.querySelector('[data-import-progression]');
    const resume = formulaire.querySelector('[data-import-resume]');
    const barre = formulaire.querySelector('[data-import-barre]');
    const liste = formulaire.querySelector('[data-import-liste]');

    bouton.disabled = true;
    zone.hidden = false;
    liste.replaceChildren();
    barre.max = fichiers.length;
    barre.value = 0;

    // Réglages communs à tout le lot (sans le champ fichiers).
    const reglages = new FormData(formulaire);
    reglages.delete('photos');

    let reussies = 0;
    for (const [index, fichier] of fichiers.entries()) {
        resume.textContent = `Envoi ${index + 1} sur ${fichiers.length}…`;
        const ligne = document.createElement('li');
        ligne.textContent = fichier.name;
        liste.appendChild(ligne);

        const donnees = new FormData();
        for (const [cle, valeur] of reglages.entries()) {
            donnees.append(cle, valeur);
        }
        donnees.append('photo', fichier);

        try {
            const reponse = await fetch(formulaire.dataset.url, { method: 'POST', body: donnees, credentials: 'same-origin' });
            const resultat = await reponse.json().catch(() => ({}));
            if (reponse.ok) {
                reussies++;
                ligne.classList.add('import-photos__ok');
                ligne.textContent = `${fichier.name} : importée`;
            } else {
                ligne.classList.add('import-photos__erreur');
                ligne.textContent = `${fichier.name} : ${resultat.erreur ?? 'refusée'}`;
            }
        } catch {
            ligne.classList.add('import-photos__erreur');
            ligne.textContent = `${fichier.name} : connexion interrompue, réessayez`;
        }
        barre.value = index + 1;
    }

    resume.textContent = `${reussies} photo(s) importée(s) sur ${fichiers.length}.`;
    if (reussies > 0) {
        const lien = document.createElement('a');
        lien.href = formulaire.dataset.urlPhotos;
        lien.textContent = ' Voir les photos et compléter les descriptions';
        resume.appendChild(lien);
    }
    formulaire.querySelector('input[type="file"]').value = '';
    bouton.disabled = false;
});
