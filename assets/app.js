/*
 * Point d'entrée JavaScript, chargé via importmap() dans base.html.twig.
 *
 * Règle du projet (CLAUDE.md §2) : le site doit fonctionner sans JavaScript.
 * Aujourd'hui aucun comportement n'en a besoin — le repli du menu mobile est
 * purement CSS — donc on ne charge pas Stimulus : ce serait 45 Ko de framework
 * pour zéro contrôleur, au détriment de la cible Lighthouse ≥ 95.
 *
 * Dès qu'un contrôleur sera nécessaire (phases 5 et 6), décommenter la ligne
 * ci-dessous ; le paquet est déjà installé et présent dans importmap.php.
 */
// import './stimulus_bootstrap.js';

import './styles/app.css';
