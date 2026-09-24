# CLAUDE.md — Site vitrine association LIBOKÉ

> Document de référence pour Claude Code. À lire intégralement avant toute action.
> Domaine cible : **association-liboke.com**
>
> `AGENTS.md` (généré par la recette Symfony Flex) donne les conventions Symfony génériques.
> **En cas de divergence, ce document prime.**

---

## 1. Contexte et objectif

Refonte du site de l'association LIBOKÉ, initialement développé sous Drupal (version aujourd'hui obsolète, base de données non récupérable en l'état).

Le nouveau site est un **site vitrine à contenu statique**, enrichi d'un **module de dons en ligne (Stripe)** : quelques pages de présentation, un formulaire de contact, un parcours de don, un rendu professionnel et un **référencement naturel (SEO) prioritaire**. Il doit être crédible pour une démarche auprès des Nations Unies : sobre, rapide, accessible, sans erreur.

**Contraintes clés :**

- Le contenu éditorial est versionné dans le dépôt (Markdown + front matter YAML), **jamais en base**.
- La base de données ne sert **qu'aux données transactionnelles** : dons, événements Stripe, messages de contact.
- **Aucun compte utilisateur, aucune table `user`.** Un unique compte administrateur défini en variable d'environnement donne accès à une page de consultation des dons et des messages.
- Le donateur qui souhaitera gérer un don mensuel passera par le **Customer Portal de Stripe**, pas par un espace authentifié maison.
- Le **design reprend fidèlement le thème Drupal existant `libokev2`** (seuls les fichiers du thème sont disponibles).
- Tout tourne dans **Docker**, en local comme en production (VPS).

---

## 2. Stack technique

| Couche | Choix | Justification |
|---|---|---|
| Framework | **Symfony dernière version stable** (8.x) | Vérifier la version courante avec `symfony new` / Packagist, ne pas figer une version mineure de mémoire |
| PHP | Version minimale exigée par cette version de Symfony (8.4+) | |
| Serveur | **FrankenPHP** (image basée sur `dunglas/symfony-docker`) | HTTPS automatique, HTTP/2-3, worker mode en prod, même image dev/prod |
| Rendu | **Twig côté serveur (SSR)** | HTML complet dès la première réponse = SEO optimal. Drupal utilise aussi Twig : les templates du thème se portent quasi directement |
| Assets | **AssetMapper** (+ `symfonycasts/sass-bundle` si le thème utilise du SCSS) | Pas de Node, pas de bundler, assets versionnés et cachables |
| Interactivité | **Stimulus** (Symfony UX), Turbo optionnel | JS minimal, amélioration progressive, le site fonctionne sans JS |
| Contenu | Markdown + front matter (`league/commonmark`, `symfony/yaml`) | Contenu éditorial versionné, hors base |
| Formulaire | Symfony Form + Mailer + RateLimiter + honeypot | Anti-spam sans captcha tiers |
| Base de données | **PostgreSQL 16 + Doctrine ORM** | Uniquement les données transactionnelles (§10). Le contenu éditorial reste en fichiers |
| Paiement | **Stripe Checkout** (`stripe/stripe-php`) + webhooks signés | Page hébergée par Stripe : aucun JS de paiement sur nos pages (cible Lighthouse préservée), conformité PCI réduite au SAQ-A |
| Asynchrone | **Messenger**, transport `doctrine` | Emails hors du cycle requête ; webhook Stripe qui répond vite |
| Qualité | PHPUnit, PHPStan (niveau max raisonnable), PHP-CS-Fixer | |

**Interdits :** SPA (React/Vue/Next), rendu côté client du contenu, EasyAdmin, dépendances CDN externes pour les assets critiques, **table `user` / comptes donateurs**, et surtout **tout transit ou stockage d'un numéro de carte bancaire sur nos serveurs** (Stripe Elements côté client est écarté au profit de Checkout hébergé).

> Doctrine n'est plus interdit, mais reste **cantonné au transactionnel**. Si une idée implique de mettre du contenu éditorial en base, c'est qu'elle sort du périmètre : en discuter avant.

---

## 3. Arborescence locale et accès au thème Drupal

Les deux projets sont côte à côte :

```
<workspace>/
├── associationliboke.com/                 # Ancien Drupal (LECTURE SEULE)
│   └── themes/custom/libokev2/            # Thème de référence du design
└── <ce-projet>/                           # Nouveau projet Symfony (dossier courant)
```

Depuis la racine de ce projet, le thème est accessible via :

```bash
cd ../associationliboke.com/themes/custom/libokev2
```

### Règles impératives sur le thème

1. **Ne jamais modifier, déplacer ou supprimer** quoi que ce soit dans `../associationliboke.com/`. C'est une source de référence en lecture seule.
2. Les fichiers utiles (CSS/SCSS, JS, images, polices, icônes) sont **copiés** dans ce projet, jamais liés par symlink (le build Docker ne doit pas dépendre d'un dossier externe).
3. Dans Docker (dev uniquement), le thème est monté en lecture seule sur `/drupal-theme` pour consultation :

```yaml
# compose.override.yaml (dev)
services:
  php:
    volumes:
      - ../associationliboke.com/themes/custom/libokev2:/drupal-theme:ro
```

---

## 4. Phase 0 — Audit du thème (à faire AVANT tout développement)

Produire `docs/theme-audit.md` contenant :

- **`libokev2.info.yml`** : thème de base éventuel (ex. Bootstrap, Classy, Stable), liste des **régions** (header, primary_menu, content, footer…).
- **`libokev2.libraries.yml`** : fichiers CSS/JS chargés, dépendances (jQuery, Bootstrap, librairies tierces). Identifier ce qui est réellement nécessaire.
- **`libokev2.breakpoints.yml`** : points de rupture responsive.
- **`templates/`** : inventaire des templates (`html.html.twig`, `page.html.twig`, `page--front.html.twig`, `node--*.html.twig`, `block--*.html.twig`, `menu--*.html.twig`, `region--*.html.twig`, `field--*.html.twig`…) et leur rôle.
- **Assets** : SCSS ou CSS compilé ? Présence d'un `package.json`/gulpfile ? Polices, images, favicon, logo.
- **`*.theme`** (preprocess PHP) : logique à reproduire côté Symfony (variables injectées, classes ajoutées).
- **`screenshot.png`** et tout visuel de référence.
- Liste des dépendances jQuery/Bootstrap JS et proposition de remplacement par Stimulus ou JS natif si c'est simple.

**Attendre la validation de l'audit avant de passer à la phase 1.**

---

## 5. Portage Drupal Twig → Symfony Twig

Correspondances à appliquer :

| Drupal | Symfony |
|---|---|
| `html.html.twig` | `templates/base.html.twig` (head, meta SEO, assets) |
| `page.html.twig` + régions | `templates/layout/` + `{% block %}` / `{% include %}` par région |
| `node--page.html.twig` | `templates/page/default.html.twig` |
| `block--*.html.twig`, `menu--*.html.twig` | `templates/components/` (Twig Components ou partials) |
| `{{ attributes }}` / `attributes.addClass()` | Classes HTML écrites en dur ou passées en variables |
| `{{ 'Texte'|t }}` | Texte direct (ou `|trans` si multilingue confirmé) |
| `{{ attach_library('libokev2/xxx') }}` | `{{ importmap('app') }}` + imports dans `assets/app.js` |
| `{{ url('<front>') }}`, `path('entity.node.canonical')` | `{{ path('app_home') }}`, `{{ path('app_page', {slug}) }}` |
| `{{ content.field_xxx }}` | `{{ page.xxx }}` (front matter) ou `{{ page.html|raw }}` |
| `drupalSettings`, `Drupal.behaviors` | Contrôleurs Stimulus |

Objectif : **rendu visuel identique** au thème (structure HTML, classes CSS conservées autant que possible pour réutiliser le CSS sans le réécrire). Nettoyer le balisage Drupal superflu (wrappers `field--`, `views-`, attributs `data-drupal-*`) tant que le CSS n'en dépend pas.

---

## 6. Architecture applicative

```
.
├── assets/
│   ├── app.js
│   ├── controllers/            # Stimulus
│   ├── styles/                 # CSS/SCSS issus du thème libokev2
│   ├── images/
│   └── fonts/
├── config/
├── content/
│   ├── pages/                  # 1 fichier .md par page
│   └── site.yaml               # Nom, baseline, coordonnées, réseaux, menu, footer
├── docs/
│   └── theme-audit.md
├── public/
│   ├── robots.txt              # ou généré
│   └── favicon.ico
├── migrations/                 # Doctrine
├── src/
│   ├── Content/
│   │   ├── Page.php            # DTO readonly
│   │   ├── PageRepository.php  # Lit content/pages, parse front matter + Markdown, cache
│   │   └── SiteConfig.php
│   ├── Controller/
│   │   ├── HomeController.php
│   │   ├── PageController.php  # /{slug}
│   │   ├── ContactController.php
│   │   ├── DonationController.php       # /nous-soutenir, /don/checkout, /don/merci
│   │   ├── StripeWebhookController.php  # /stripe/webhook
│   │   ├── AdminController.php          # /admin : dons, messages, export CSV
│   │   └── SeoController.php   # sitemap.xml, robots.txt
│   ├── Entity/
│   │   ├── Donation.php
│   │   ├── StripeEvent.php     # idempotence des webhooks
│   │   └── ContactMessage.php
│   ├── Repository/
│   ├── Stripe/
│   │   ├── CheckoutSessionFactory.php
│   │   └── WebhookHandler.php
│   ├── Message/                # Messenger : emails asynchrones
│   ├── Command/                # app:purge-contact-messages
│   ├── Form/
│   │   ├── ContactType.php
│   │   └── DonationType.php
│   └── Twig/                   # Extensions / Components
├── templates/
│   ├── base.html.twig
│   ├── layout/                 # header, nav, footer (régions du thème)
│   ├── components/
│   ├── page/
│   ├── home/
│   ├── contact/
│   ├── donation/
│   ├── admin/
│   ├── emails/
│   └── bundles/TwigBundle/Exception/   # 404 / 500 personnalisées
├── tests/
├── compose.yaml
├── compose.override.yaml       # dev
├── compose.prod.yaml           # prod (à compléter plus tard)
├── Dockerfile
├── Makefile
└── CLAUDE.md
```

### Format d'une page

```markdown
---
title: "L'association"
slug: association
menu: { label: "L'association", weight: 10 }
seo:
  title: "L'association LIBOKÉ — Mission et valeurs"
  description: "150 à 160 caractères décrivant la page."
  og_image: images/og/association.jpg
  noindex: false
template: default          # page/default.html.twig par défaut
updated: 2026-09-20
---

Contenu en **Markdown**.
```

- `PageRepository` met en cache le parsing (cache Symfony, invalidé par `cache:clear` au déploiement).
- Slug inconnu → vraie **404** (pas de redirection vers l'accueil).
- Le Markdown est rendu en HTML sûr (CommonMark, HTML brut désactivé sauf besoin explicite).

### Pages prévues (à confirmer avec le client)

Accueil · L'association (histoire, mission, équipe) · Nos actions / projets · Partenaires · Nous soutenir · Contact · Mentions légales · Politique de confidentialité.

Le contenu réel sera fourni plus tard : créer des pages avec un **contenu provisoire clairement marqué** (`[À COMPLÉTER]`), jamais de faux texte présenté comme définitif.

---

## 7. Exigences SEO (priorité absolue)

- HTML sémantique : un seul `<h1>` par page, hiérarchie de titres respectée, `<header>`, `<nav>`, `<main>`, `<footer>`, `<article>`.
- `<html lang="fr">`.
- Par page : `<title>` unique, `meta description`, **URL canonique absolue**, Open Graph + Twitter Card.
- URLs propres, en minuscules, sans extension, sans slash final (redirection 301 si slash final).
- **JSON-LD** : `NGO` / `Organization` (nom, logo, url, adresse, contact, `sameAs` réseaux) sur l'accueil, `BreadcrumbList` sur les pages internes, `WebSite` sur l'accueil.
- `/sitemap.xml` généré depuis `PageRepository` (exclut les pages `noindex`), avec `lastmod`.
- `/robots.txt` dynamique : `Disallow: /` hors environnement `prod`, référence au sitemap en prod.
- En dev/staging : en-tête `X-Robots-Tag: noindex`.
- Redirection 301 vers l'hôte canonique (`https://association-liboke.com`, choix www/non-www à fixer en prod).
- Si les anciennes URLs Drupal sont connues (ex. `/node/12`, alias), prévoir une table de redirections 301 dans `config/redirects.yaml`.
- Pages 404/500 personnalisées, au design du site, avec le bon code HTTP.
- `/nous-soutenir` est une page de contenu **indexable** ; `/don/merci`, `/don/annule` et tout `/admin` sont en `noindex` et exclus du sitemap.
- **`/stripe/webhook` doit être exclu de la redirection 301 vers l'hôte canonique.** Un 301 casse la vérification de signature. Même exclusion pour le CSRF et pour le firewall de sécurité.

## 8. Performance et accessibilité

Cibles Lighthouse (mobile) : **≥ 95** en Performance, SEO, Accessibilité et Bonnes pratiques.

- Images : WebP/AVIF avec fallback, `width`/`height` explicites, `loading="lazy"` sauf image LCP (`fetchpriority="high"`), `srcset` pour les visuels principaux.
- Polices locales (pas de Google Fonts distant), `font-display: swap`, préchargement de la police principale.
- CSS critique léger ; supprimer le CSS/JS du thème inutilisé (Bootstrap JS, jQuery si évitable).
- Cache HTTP : `Cache-Control: public, s-maxage` sur les pages, assets versionnés en cache long (immutable). Compression gérée par FrankenPHP/Caddy.
- Accessibilité : contrastes AA, focus visible, liens d'évitement, `alt` sur toutes les images, formulaire avec labels et messages d'erreur associés, navigation mobile au clavier.

## 9. Formulaire de contact

- Champs : nom, email, objet, message, case de consentement RGPD.
- Anti-spam : honeypot + délai minimum de soumission + `RateLimiter` par IP.
- Envoi via Symfony Mailer, `MAILER_DSN` en variable d'environnement. En dev : **Mailpit** dans le compose.
- Le message est **également persisté** (`ContactMessage`) : un email perdu ou classé en spam ne doit pas faire perdre un contact. La persistance est synchrone, l'envoi passe par Messenger — si le mail échoue, le message reste consultable dans `/admin`.
- Champs stockés : nom, email, objet, message, consentement, horodatage. **Pas d'adresse IP en base** (le `RateLimiter` travaille en cache).
- Purge automatique à **12 mois** (`app:purge-contact-messages`, voir §11).
- Pattern POST → redirect → GET avec message flash.

## 10. Dons en ligne (Stripe)

### Principe

**Stripe est le système de référence du paiement. La base locale est un registre, pas une caisse.**
On utilise **Stripe Checkout** : le donateur est redirigé vers une page hébergée par Stripe, saisit sa carte chez Stripe, puis revient. Aucune donnée bancaire ne touche nos serveurs, et aucun JS de paiement n'alourdit nos pages.

### Parcours

| Étape | Route | Détail |
|---|---|---|
| Présentation | `GET /nous-soutenir` | Page de contenu Markdown + formulaire de don. **Indexable.** |
| Création de session | `POST /don/checkout` | Valide le montant côté serveur, crée la session Stripe, redirige (303) vers l'URL Stripe |
| Retour succès | `GET /don/merci` | `noindex`. Affiche un remerciement **sans jamais affirmer que le paiement est confirmé** : seul le webhook fait foi |
| Retour annulation | `GET /don/annule` | `noindex`. Message neutre, lien de retour |
| Webhook | `POST /stripe/webhook` | Seule source de vérité pour marquer un don comme payé |

### Règles non négociables

1. **Le montant ne vient jamais du client sans validation serveur.** Montants suggérés définis dans `config/donations.yaml`, montant libre borné (min 1 €, max à fixer). Stockage en **centimes entiers** (`int`), jamais en `float`.
2. **Le webhook vérifie la signature** (`Stripe\Webhook::constructEvent`) sur le **corps brut** de la requête, avec `STRIPE_WEBHOOK_SECRET`.
3. **Idempotence obligatoire.** Stripe réémet ses événements. Avant traitement, insérer l'`event.id` dans `StripeEvent` (index unique) ; une violation de contrainte = événement déjà traité → répondre 200 sans rien refaire. Traitement et insertion dans **la même transaction**.
4. Le webhook **répond vite** : aucun envoi d'email synchrone, tout passe par Messenger.
5. La route du webhook est **exclue du CSRF, du firewall de sécurité, de la redirection 301 canonique et du `X-Robots-Tag`** (voir §7).
6. Les clés Stripe vivent **uniquement en variables d'environnement** (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`). Jamais dans le dépôt, jamais dans un template, jamais dans un log.
7. `RateLimiter` sur `POST /don/checkout` (création de sessions en masse = coût et bruit).

### Événements traités

- `checkout.session.completed` → passe la `Donation` en `paid`, enregistre les informations du donateur, déclenche l'email de remerciement.
- `charge.refunded` → passe la `Donation` en `refunded`.
- *(Phase ultérieure, don mensuel)* `invoice.paid`, `invoice.payment_failed`, `customer.subscription.deleted`.

### Entités

**`Donation`**

| Champ | Type | Note |
|---|---|---|
| `id` | UUID | |
| `stripeSessionId` | string, **unique** | |
| `stripePaymentIntentId` | string, nullable | |
| `stripeCustomerId` | string, nullable | nécessaire au Customer Portal (don mensuel) |
| `stripeSubscriptionId` | string, nullable | **réservé au don mensuel, non utilisé au lancement** |
| `type` | enum `one_time` \| `monthly` | `one_time` au lancement |
| `amountCents` | int | |
| `currency` | string(3) | `EUR` |
| `status` | enum `pending` \| `paid` \| `refunded` \| `failed` | |
| `donorName`, `donorEmail` | string | |
| `donorAddress*`, `isCompany` | nullable | collectés en vue du reçu fiscal (§ ci-dessous) |
| `receiptNumber` | string, nullable, **unique** | réservé, non alimenté au lancement |
| `receiptIssuedAt` | datetime, nullable | |
| `createdAt`, `paidAt` | datetime | |

**`StripeEvent`** : `eventId` (unique), `type`, `processedAt`.

### Reçus fiscaux — décision

Le modèle de données **prévoit** le reçu dès maintenant : champs donateur (y compris adresse postale), `receiptNumber` et `receiptIssuedAt`. **La génération du PDF CERFA 11580 n'est pas implémentée au lancement.**

- Numérotation **séquentielle et sans trou**, format `LIBOKE-{année}-{séquence}`, attribuée en base sous verrou — à implémenter en même temps que le PDF, pas avant.
- L'association étant potentiellement éligible au mécénat (réduction de 66 % pour les particuliers, art. 200 CGI ; 60 % pour les entreprises, art. 238 bis), l'émission de reçus entraîne une **obligation déclarative annuelle** : à confirmer avec le client et son comptable avant la mise en production.
- **Tant que le reçu n'est pas implémenté, aucun email ni aucune page ne doit promettre un reçu fiscal.**

### Don mensuel — décision

Non implémenté au lancement, **mais anticipé dans le modèle** (`type`, `stripeCustomerId`, `stripeSubscriptionId`). Quand il arrivera : mode `subscription` de Checkout, et gestion (modification, résiliation) déléguée au **Customer Portal de Stripe** via un lien signé envoyé par email. **Pas d'espace donateur authentifié maison.**

### Espace administrateur

- Route `/admin`, `noindex`, HTTPS uniquement.
- Authentification par **un seul compte** déclaré en variables d'environnement (`security.providers.users_in_memory`, mot de passe hashé). **Aucune table `user`, aucune inscription, aucune réinitialisation de mot de passe.**
- Fonctions : liste des dons (filtres date/statut), liste des messages de contact, export CSV. **Lecture seule** — aucune modification de donnée financière depuis l'interface.

---

## 11. Données personnelles et RGPD

L'ajout d'une base de données transforme le projet en responsable de traitement. Conséquences à tenir :

- **Données collectées** : messages de contact (nom, email, objet, message, consentement, horodatage) ; dons (identité, email, adresse si reçu fiscal, montants, identifiants Stripe). **Aucune donnée bancaire.**
- **Pas d'adresse IP en base.** Le `RateLimiter` travaille en cache, pas en stockage durable.
- **Durées de conservation** : messages de contact purgés à **12 mois** via `app:purge-contact-messages` (tâche planifiée) ; données de dons conservées selon les obligations comptables de l'association — durée à confirmer avec le client, ne pas l'inventer.
- **Sous-traitant** : Stripe doit être nommé dans la politique de confidentialité, avec la mention du transfert hors UE et de sa base légale.
- **Droits d'accès et d'effacement** : procédure manuelle documentée, adresse de contact publiée. L'effacement d'un don reste soumis aux obligations comptables.
- **Cookies** : au lancement, aucun cookie hors session PHP et jeton CSRF → **pas de bandeau cookies nécessaire**. Toute analytics tierce remettrait cela en cause : si un besoin de mesure apparaît, privilégier une solution sans cookie (Matomo auto-hébergé, Plausible) et en discuter avant.
- Les pages **Mentions légales** et **Politique de confidentialité** doivent être à jour **avant** la mise en ligne du module de dons, pas après.

---

## 12. Docker

Base : structure de **`dunglas/symfony-docker`** (FrankenPHP).

**Dev (`compose.yaml` + `compose.override.yaml`)** :

- Service `php` (FrankenPHP) avec Xdebug désactivé par défaut, activable par variable.
- Service `database` (**PostgreSQL 16-alpine**), volume nommé `database_data`, **non exposé sur l'hôte** (accès via `make db`).
- Service `mailer` (Mailpit).
- **Stripe CLI** pour relayer les webhooks en local : `stripe listen --forward-to https://localhost/stripe/webhook`. Le `STRIPE_WEBHOOK_SECRET` de dev est celui affiché par la CLI, différent de celui de prod.
- Montage en lecture seule du thème Drupal sur `/drupal-theme` (voir §3).
- Accès : `https://localhost`.

**Prod (`compose.prod.yaml`)** — *à compléter ultérieurement par le développeur avant le déploiement sur le VPS.* Pour l'instant :

- Prévoir l'image multi-stage (`frankenphp_prod`) avec `APP_ENV=prod`, `composer install --no-dev --optimize-autoloader`, `asset-map:compile`, `cache:warmup`, worker mode.
- Prévoir `doctrine:migrations:migrate --no-interaction` à l'étape de déploiement, et un worker Messenger (`messenger:consume async`) supervisé.
- **Sauvegarde de la base à prévoir** (`pg_dump` quotidien, rétention, restauration testée) : le registre des dons n'est pas reconstituable depuis le site. À cadrer avec le développeur.
- **Variables à positionner au déploiement** : `CANONICAL_URL` (hôte réel, choix www / sans-www à arrêter) et `SITE_INDEXABLE=1`. Elles pilotent les URLs canoniques, le sitemap, `robots.txt`, l'en-tête `X-Robots-Tag` et la redirection 301 vers l'hôte canonique. Une recette reste en `SITE_INDEXABLE=0`.
- Ne pas écrire de configuration VPS (reverse proxy, DNS, certificats, CI/CD) sans instruction explicite.

## 13. Makefile (commandes attendues)

```
make up          # build + démarrage
make down
make sh          # shell dans le conteneur php
make cc          # cache:clear
make test        # PHPUnit
make lint        # PHP-CS-Fixer (dry-run) + PHPStan + lint:twig + lint:yaml
make fix         # PHP-CS-Fixer
make assets      # compilation sass si utilisée
make db          # psql dans le conteneur database
make migration   # doctrine:migrations:diff
make migrate     # doctrine:migrations:migrate
make stripe      # stripe listen --forward-to https://localhost/stripe/webhook
make worker      # messenger:consume async -vv
```

## 14. Tests

- Test fonctionnel qui parcourt **toutes les pages du contenu** : statut 200, un seul `<h1>`, `<title>` et `meta description` présents et non vides, canonical présente.
- 404 sur un slug inconnu.
- `sitemap.xml` valide et contenant toutes les pages indexables.
- `robots.txt` différent selon l'environnement.
- Formulaire de contact : validation, honeypot, email envoyé (`assertEmailCount`), message bien persisté.
- **Don — création de session** : montant hors bornes, montant non entier ou négatif → rejet ; le montant transmis à Stripe est bien celui validé côté serveur, pas celui posté.
- **Don — webhook** : signature absente ou invalide → 400 et aucune écriture ; signature valide → 200 et `Donation` passée à `paid`.
- **Don — idempotence** : le même `event.id` rejoué deux fois ne crée qu'une donation et n'envoie qu'un seul email.
- **Don — page de retour** : `/don/merci` n'affirme pas qu'un paiement est confirmé tant que le webhook n'est pas passé.
- `app:purge-contact-messages` supprime bien les messages de plus de 12 mois et **uniquement** ceux-là.
- `/admin` renvoie une redirection vers l'authentification pour un visiteur anonyme.

## 15. Conventions

- Code, noms de classes et commits en anglais ; contenu du site et documentation en français.
- `declare(strict_types=1);`, classes `final` et `readonly` quand pertinent, injection par constructeur, attributs PHP pour le routing.
- Commits atomiques (Conventional Commits).
- Pas de nouvelle dépendance Composer sans justification courte dans le message de commit.

## 16. Plan de travail

> **L'état d'avancement détaillé est dans `docs/avancement.md`** : ce qui est fait,
> ce qui reste à décider, les pièges rencontrés et le point de reprise.
> Le tableau ci-dessous n'en donne que le résumé.

1. ✅ **Phase 0** — Audit du thème → `docs/theme-audit.md`. *Stop, validation.*
2. ✅ **Phase 1** — Initialisation Symfony + Docker (FrankenPHP, PostgreSQL, Mailpit), Makefile, outils qualité. Page d'accueil « hello » servie en HTTPS local.
3. ✅ **Phase 2** — Import des assets du thème (CSS/SCSS, polices, images) dans AssetMapper ; layout `base.html.twig` + régions (header, menu, footer) fidèles au thème.
4. ✅ **Phase 3** — Couche contenu (`PageRepository`, Markdown, `site.yaml`), pages et menu dynamiques.
5. ✅ **Phase 4** — SEO complet (meta, JSON-LD, sitemap, robots, redirections, pages d'erreur).
6. ✅ **Phase 5** — Formulaire de contact (envoi + persistance).
7. ⚠️ **Phase 6** — Dons Stripe : page « Nous soutenir », Checkout, webhook signé et idempotent, emails de remerciement. *Code livré et testé contre un double, **validation avec des clés de test encore à faire** avant toute clé live (`docs/avancement.md` §3).*
8. ✅ **Phase 7** — Espace admin minimal (lecture seule, compte unique en env), purge RGPD, pages Mentions légales et Politique de confidentialité. *Textes légaux livrés avec des `[À COMPLÉTER]` à faire remplir par l'association.*
9. **Phase 8** — Optimisations performance/accessibilité, audit Lighthouse, tests.
10. **Phase 9** — Préparation prod Docker, sauvegardes base (en attente des instructions du développeur).

*Phases ultérieures non planifiées :* génération des reçus fiscaux CERFA, don mensuel récurrent. Le modèle de données les anticipe (§10), le code non.

À chaque fin de phase : résumer ce qui a été fait, ce qui reste en suspens, et les points nécessitant une décision.
