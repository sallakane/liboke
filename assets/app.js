/*
 * Point d'entrée JavaScript, chargé via importmap() dans base.html.twig.
 *
 * Le JavaScript est permis quand il sert le fonctionnement ou l'ergonomie
 * (CLAUDE.md §2), via des contrôleurs Stimulus. Les contrôleurs marqués
 * « stimulusFetch: 'lazy' » ne sont téléchargés que sur les pages qui les
 * utilisent.
 */
import './stimulus_bootstrap.js';

import './styles/app.css';
