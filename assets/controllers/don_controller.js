import { Controller } from '@hotwired/stimulus';
import { removeCsrfToken } from './csrf_protection_controller.js';

/*
 * Don en ligne : choix du montant, puis paiement Stripe intégré à la page
 * (Embedded Checkout), sans quitter le site.
 *
 * Stripe impose de charger Stripe.js depuis js.stripe.com (conformité PCI),
 * jamais depuis nos assets. On ne le charge qu'au moment où le donateur
 * passe au paiement : les autres visiteurs de la page n'en paient ni le
 * poids ni les cookies antifraude de Stripe.
 *
 * Le montant n'est jamais décidé ici : le serveur le valide et crée la
 * session ; ce contrôleur ne fait qu'afficher ce que le serveur renvoie.
 */

const STRIPE_JS = 'https://js.stripe.com/dahlia/stripe.js';

let chargementStripe = null;

function chargerStripe() {
    if (window.Stripe) {
        return Promise.resolve(window.Stripe);
    }

    chargementStripe ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = STRIPE_JS;
        script.async = true;
        script.onload = () => (window.Stripe ? resolve(window.Stripe) : reject(new Error('Stripe.js absent')));
        script.onerror = () => {
            // Permet une nouvelle tentative après une coupure réseau.
            chargementStripe = null;
            script.remove();
            reject(new Error('Stripe.js injoignable'));
        };
        document.head.append(script);
    });

    return chargementStripe;
}

const MESSAGE_INDISPONIBLE = 'Le paiement en ligne est momentanément indisponible. Merci de réessayer dans quelques instants.';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['formulaire', 'paiement', 'etat', 'widget'];

    static values = {
        clePublique: String,
        secret: String,
    };

    connect() {
        this.messageChargement = this.etatTarget.textContent;

        if (this.secretValue) {
            this.afficherPaiement(this.secretValue);
        }
    }

    disconnect() {
        this.detruire();
    }

    async envoyer(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const bouton = form.querySelector('[type="submit"]');
        bouton.disabled = true;

        try {
            const reponse = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            removeCsrfToken(form);

            const donnees = await reponse.json();

            if (reponse.ok && donnees.clientSecret) {
                await this.afficherPaiement(donnees.clientSecret);

                return;
            }

            // Fragment rendu et échappé par le serveur (Twig).
            this.formulaireTarget.innerHTML = donnees.form;
            this.formulaireTarget.querySelector('[aria-invalid="true"], [role="alert"]')?.focus();
        } catch {
            this.signalerErreur(form);
        } finally {
            bouton.disabled = false;
        }
    }

    async afficherPaiement(secret) {
        this.formulaireTarget.hidden = true;
        this.paiementTarget.hidden = false;
        this.etatTarget.textContent = this.messageChargement;
        this.etatTarget.hidden = false;
        this.paiementTarget.focus();

        try {
            const Stripe = await chargerStripe();
            this.stripe ??= Stripe(this.clePubliqueValue);

            // Stripe n'autorise qu'un formulaire intégré à la fois.
            this.detruire();
            this.checkout = await this.stripe.createEmbeddedCheckoutPage({
                fetchClientSecret: async () => secret,
            });
            this.checkout.mount(this.widgetTarget);
            this.etatTarget.hidden = true;
        } catch {
            this.etatTarget.textContent = MESSAGE_INDISPONIBLE;
        }
    }

    modifier() {
        this.detruire();
        this.paiementTarget.hidden = true;
        this.formulaireTarget.hidden = false;
        this.formulaireTarget.querySelector('input:checked, input')?.focus();
    }

    detruire() {
        this.checkout?.destroy();
        this.checkout = null;
    }

    signalerErreur(form) {
        form.querySelector('.alerte--reseau')?.remove();

        const alerte = document.createElement('p');
        alerte.className = 'alerte alerte--erreur alerte--reseau';
        alerte.setAttribute('role', 'alert');
        alerte.textContent = MESSAGE_INDISPONIBLE;
        form.prepend(alerte);
    }
}
