import { Controller } from '@hotwired/stimulus';

/*
 * Bouton de divulgation du menu principal sur mobile.
 *
 * Non chargé à la demande (pas de « stimulusFetch: 'lazy' ») : le menu est
 * présent sur toutes les pages, en haut, il doit répondre dès le premier
 * toucher.
 */
export default class extends Controller {
    static targets = ['bouton'];

    get ouvert() {
        return this.boutonTarget.getAttribute('aria-expanded') === 'true';
    }

    basculer() {
        this.definir(!this.ouvert);
    }

    fermer(event) {
        if (event.key === 'Escape' && this.ouvert) {
            this.definir(false);
            this.boutonTarget.focus();
        }
    }

    definir(ouvert) {
        this.boutonTarget.setAttribute('aria-expanded', String(ouvert));
        this.element.classList.toggle('navigation--ouverte', ouvert);
    }
}
