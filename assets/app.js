/*
 * Point d'entrée JavaScript du site (Stimulus, Turbo).
 * Les feuilles de style sont chargées par des balises <link> dans les gabarits, pas importées ici :
 * AssetMapper transformerait l'import en module « data: », refusé par la CSP de production
 * (et tout le JavaScript s'arrêterait).
 */
import './stimulus_bootstrap.js';
