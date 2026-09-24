# Audit du thème Drupal `libokev2`

> Phase 0 du plan de travail (CLAUDE.md §4 et §16).
> Source auditée : `../associationliboke.com/themes/custom/libokev2/` — **lecture seule, rien n'a été modifié.**
> Date : 2026-09-20.

---

## 1. Synthèse

`libokev2` est un **sous-thème de Drupal Bootstrap 3** (`themes/contrib/bootstrap`, version 8.x-3.20). Il ne contient pas un design complet : c'est une **surcouche de 1 155 lignes de CSS** posée sur le framework Bootstrap 3, plus une quinzaine de templates réellement personnalisés sur les 64 présents.

Trois constats déterminent la suite du projet :

1. **Bootstrap 3 n'est pas dans le dépôt.** Le thème de base le chargeait depuis un CDN. Toute la grille et tous les composants sur lesquels le CSS s'appuie sont donc absents (§2).
2. **L'ancien site n'était pas un site vitrine.** C'était une plateforme de commande de bons (pharmacie, alimentaire, transport) avec comptes, panier, paiement Stripe et rôles partenaires. L'essentiel du CSS et du JS concerne des écrans qui n'existeront pas dans le nouveau site (§7).
3. **Le design réutilisable tient en peu de choses** : une charte (couleurs, police Saira Semi Condensed), un en-tête, une barre de navigation, un bandeau de trois images et une page d'accueil. C'est tout, et c'est suffisant.

**Recommandation : ne pas porter Bootstrap 3.** Réécrire la mise en page en CSS moderne (Grid/Flexbox) en conservant à l'identique la charte et la structure visuelle. Justification détaillée en §9.

---

## 2. Constat bloquant — Bootstrap 3 est absent

`libokev2.libraries.yml` étend la bibliothèque `bootstrap/framework`. Or, dans le thème de base :

```yaml
# themes/contrib/bootstrap/bootstrap.libraries.yml
framework:
  css: {}
  js: {}
```

avec ce commentaire : *« This is automatically extended with JavaScript and CSS for CDN based themes. »*

Le fichier `libokev2/config/install/libokev2.settings.yml` contient la ligne suivante, **commentée** :

```yaml
# Disable the CDN provider so compiled source files can be used.
#cdn_provider: ''
```

Le fournisseur CDN n'a donc pas été désactivé à l'installation. Aucun `bootstrap.css` ni `bootstrap.js` n'existe dans l'arborescence du thème ou du thème de base.

**Conséquences :**

- Les classes `col-sm-*`, `row`, `navbar`, `btn`, `carousel`, `list-group`, `glyphicon` n'ont aucune définition disponible localement.
- Le CSS de `libokev2` est **inexploitable seul** : il ne fait que surcharger Bootstrap.
- Le CSS ne contient **qu'une seule media query** (`max-width: 575px`). Tout le responsive venait de la grille Bootstrap.

**Limite de l'audit :** les réglages effectifs du thème (version exacte de Bootstrap, fournisseur CDN) étaient stockés en base de données, qui n'est pas récupérable. La version ne peut pas être confirmée ; les classes et le rendu du `screenshot.png` sont cohérents avec Bootstrap 3.3/3.4.

---

## 3. `libokev2.info.yml`

| Clé | Valeur |
|---|---|
| Thème de base | `bootstrap` (Drupal Bootstrap 8.x-3.20, *Bootstrap 3*) |
| Nom | Liboke V2 Theme |
| Régions | `navigation`, `navigation_collapsible`, `header`, `highlighted`, `help`, `content`, `sidebar_first`, `sidebar_second`, `footer`, `page_top`, `page_bottom` |

Les régions sont celles héritées du thème de base, **non personnalisées**. En pratique, `page.html.twig` n'en utilise que quelques-unes, et une bonne partie de l'en-tête est **écrite en dur dans le template**, pas alimentée par des régions (§5).

Correspondance retenue pour Symfony :

| Région Drupal | Destination Symfony |
|---|---|
| `navigation`, `navigation_collapsible` | `templates/layout/_header.html.twig` (barre noire + menu) |
| `header` | non utilisée de façon significative → abandonnée |
| `content` | bloc `{% block content %}` de `base.html.twig` |
| `sidebar_first`, `sidebar_second` | **abandonnées** — jamais utilisées dans le design |
| `footer` | `templates/layout/_footer.html.twig` |
| `highlighted`, `help`, `page_top`, `page_bottom` | **abandonnées** (plomberie Drupal) |

---

## 4. `libokev2.libraries.yml`

```yaml
framework:
  css:
    theme:
      css/style.css: {}
      //maxcdn.bootstrapcdn.com/font-awesome/4.1.0/css/font-awesome.min.css: {}
  js:
    js/global.js: {}
  dependencies:
    - core/jquery
    - core/drupal.ajax
```

| Ressource | Verdict |
|---|---|
| `css/style.css` | **Unique CSS du projet.** À exploiter comme référence de charte, pas à reprendre tel quel (§9) |
| Font Awesome 4.1.0 via `maxcdn.bootstrapcdn.com` | **À supprimer.** CDN externe (interdit par CLAUDE.md §2), version de 2014, et MaxCDN n'existe plus — cette feuille ne se chargeait probablement déjà plus |
| `js/global.js` | **À jeter** (§6) |
| `core/jquery`, `core/drupal.ajax` | Sans objet hors Drupal |

`js/scripts.js` est présent sur le disque mais **n'est déclaré dans aucune bibliothèque** : il était chargé autrement (module ou template d'une page de paiement), ou plus chargé du tout.

---

## 5. Breakpoints

**`libokev2.breakpoints.yml` n'existe pas** (CLAUDE.md §4 le supposait). Les points de rupture venaient de `bootstrap.breakpoints.yml` du thème de base, c'est-à-dire de Bootstrap 3 :

| Nom | Media query |
|---|---|
| `screen-xs-max` | `max-width: 767px` |
| `screen-sm-min` | `min-width: 768px` |
| `screen-sm-max` | `max-width: 991px` |
| `screen-md-min` | `min-width: 992px` |
| `screen-md-max` | `max-width: 1199px` |
| `screen-lg-min` | `min-width: 1200px` |

Le CSS custom n'ajoute qu'un seul palier, `max-width: 575px`, qui ne correspond à **aucun** des breakpoints Bootstrap 3 — c'est une valeur Bootstrap **4**, probablement copiée par erreur. Elle crée une zone morte entre 576px et 767px.

**Proposition pour le nouveau site :** trois paliers, `640px` / `900px` / `1200px`, en `min-width` (mobile-first). Le design n'a pas de complexité qui justifie davantage.

---

## 6. Inventaire des templates

64 fichiers dans `templates/`, dont la grande majorité provient du starterkit Bootstrap et n'a jamais été touchée.

### 6.1 Templates réellement personnalisés — à porter

| Fichier | Rôle | Portage |
|---|---|---|
| `system/html.html.twig` | Structure HTML, classes `<body>`, lien d'évitement | `templates/base.html.twig` |
| `system/page.html.twig` | **Le cœur du design** : en-tête, réseaux sociaux, navbar, bandeau 3 images, grille de contenu, footer | `templates/layout/` |
| `system/page-title.html.twig` | Titre de page | `templates/components/` — **à corriger**, voir §8 |
| `menu/menu.html.twig` + `menu/menu--main.html.twig` | Menu principal, macro récursive | `templates/components/_menu.html.twig` |
| `field/field--node--field-images--page.html.twig` | Carrousel Bootstrap d'images de page | Contrôleur Stimulus **si un carrousel est confirmé** (§11) |
| `node/node--6.html.twig` | Encart « Commander votre coupon » | **Hors périmètre** — utile seulement comme modèle visuel de bloc en deux colonnes |
| `views/views-view-fields--evenement.html.twig` | Carte d'événement (titre, date, lieu, bouton) | À réutiliser **si** une page Événements est retenue (§11) |
| `system/breadcrumb.html.twig` | Fil d'Ariane | Repris, mais il était **masqué en CSS** (§8) |

### 6.2 Templates de plomberie Drupal — à ignorer

`block/*` (9 fichiers), `bootstrap/*` (6), `input/*` (10), `file/*` (3), `filter/*` (2), `system/*` restants (13), `views/*` restants (4), `field/field.html.twig`, `field-multiple-value-form.html.twig`, `menu-local-task*`, `node/node.html.twig`.

Ces fichiers adaptent le rendu des formulaires, tableaux, messages et onglets de Drupal à Bootstrap. Symfony Form et nos propres templates remplacent tout cela. **Aucun n'est à porter.**

### 6.3 Structure de l'en-tête (extraite de `page.html.twig`)

L'en-tête est **codé en dur**, pas assemblé depuis des régions :

```
.container-fluid
└── .head.row
    ├── .col-sm-3            → <a href="/{langue}"><img class="logo" src="images/logo.jpg"></a>
    └── .col-sm-9            → fond images/bandeau-vert.png
        ├── .social          → « Suivez nous » + Facebook, Twitter, Instagram, YouTube
        └── drupal_block('menu_block')   → bouton « SE CONNECTER »
└── header#navbar.navbar.navbar-default  → barre noire, menu principal aligné à droite
```

Puis, **uniquement hors page d'accueil** (`{% if is_front != 1 %}`), le bandeau de trois photos :

```
.container-fluid > .row.header-other-page
├── .col-sm-4.bg-1 → images/bg-1.png
├── .col-sm-4.bg-2 → images/bg-2.png
└── .col-sm-4.bg-3 → images/bg-3.png
```

Ce bandeau est `display: none` en dessous de 575px.

---

## 7. `libokev2.theme` — logique preprocess

Cinq fonctions. **Aucune n'est à reproduire**, mais leur lecture est la découverte la plus importante de cet audit.

| Fonction | Ce qu'elle fait | Suite |
|---|---|---|
| `libokev2_preprocess_page_title()` | Masque le titre sur `/accueil`, `/commande/panier`, `/commande/identite`, `/commande/paiement` | Remplacé par un `{% block %}` Twig |
| `libokev2_preprocess_menu()` | Ajoute à chaque `<li>` du menu principal une classe dérivée du libellé (`nosactions`, `contact`…) et la classe `my-main-menu` au `<ul>` | Classes écrites directement dans le template |
| `libokev2_preprocess_page()` | Injecte `language`, `current_path`, et surtout **`class_css`** : le chemin de la page débarrassé de `/`, `-` et espaces, posé comme classe sur `.main-container`. Calcule aussi les drapeaux de rôles (`menu_partenaire`, `menu_maternite`, `menu_client`, `panier`) | `class_css` **est à reproduire** : du CSS cible ces classes (`.achatvip`, `.cartesgratter`, `.stockdesbons`…). Le reste est hors périmètre |
| `libokev2_preprocess_node()` / `_html()` | Injectent l'utilisateur courant et ses rôles en classe sur `<body>` | Sans objet — pas de comptes (CLAUDE.md §1) |
| `libokev2_preprocess_field()` | Construit les URL d'images pour le carrousel `field_images` | Sans objet — les images viendront du front matter |

### L'ancien site n'était pas un site vitrine

Le preprocess révèle une application métier complète : rôles `pharmacie`, `alimentaire`, `transport`, `maternite`, `teleconseiller` ; un panier en `private_tempstore` (`nb_bon_pharmacie`, `nb_bon_alimentaire`, `nb_bon_transport`) ; un tunnel `/commande/panier` → `/commande/identite` → `/commande/paiement`. Le template `node--6.html.twig` décrit le parcours « Commander votre coupon en quelques clics ».

**Conséquence directe pour le chiffrage :** une grande partie de `style.css` (`.panier`, `.content-paiement`, `.identite`, `.espace-client`, `.card-form`, `.recap`, `.view-user-partenaire`, `.recherche_commande`, `.stockdesbons`…) stylise des écrans qui **n'existeront pas**. À vue de nez, **moins d'un tiers du CSS est réutilisable**.

### `js/scripts.js` — intégration Stripe existante

Ce fichier contient une intégration **Stripe Elements** avec une **clé publiable `pk_live_…` en dur**. Une clé publiable est publique par conception (elle figure dans le code source de toute page de paiement) : ce n'est pas une fuite de secret. Trois points en découlent :

1. **L'association possède déjà un compte Stripe en production.** À récupérer plutôt qu'à recréer — les dons historiques y sont.
2. Ce fichier **ne doit pas être copié** dans le nouveau projet. CLAUDE.md §2 écarte Elements au profit de Checkout hébergé.
3. La clé présente ici ne doit pas être réutilisée telle quelle : demander au client l'accès au compte et repartir des clés de test.

### Google Analytics en dur

`html.html.twig` injecte un tag **GA4 (`G-BCGEBR2L65`)** directement dans le template, sans consentement ni bandeau cookies.

**À arbitrer** : CLAUDE.md §11 prévoit qu'aucun cookie hors session et CSRF ne soit posé, donc pas de bandeau. Reprendre ce tag imposerait un bandeau de consentement conforme. Voir §11 ci-dessous.

---

## 8. Dettes du thème à ne pas reproduire

Points relevés qui vont à l'encontre des exigences SEO, accessibilité et performance de CLAUDE.md §7 et §8.

| Problème | Détail | Correction |
|---|---|---|
| **Pas de `<h1>` de page** | `page-title.html.twig` rend `<div class="title-page">`. Seule la page d'accueil a un vrai `<h1>` (`.homepage .col-sm-3 h1`) | `<h1>` obligatoire et unique sur chaque page |
| **Fil d'Ariane masqué** | `.breadcrumb { display: none }` | Le rétablir : il porte le `BreadcrumbList` JSON-LD (CLAUDE.md §7) |
| **Images sans `alt`** | `alt=""` systématique sur le logo, les icônes sociales et les trois images de bandeau | `alt` décrivant, ou `alt=""` **assumé** pour les décoratives (bg-1/2/3) |
| **Polices en `.ttf` uniquement** | 9 fichiers, 804 Ko, sans `font-display` ni préchargement | Convertir en **woff2**, ne garder que Regular et Bold, `font-display: swap`, précharger la Regular |
| **Polices fantômes** | `font-family: Oswald` et `Poppins` utilisées sans `@font-face` ni import | Supprimer, ou charger réellement si le rendu l'exige |
| **Images très lourdes** | `accueil.png` **4,4 Mo** en 1823×1230, `bon_pharmacie.png` 974 Ko, `logo.jpg` 144 Ko pour une vignette | AVIF/WebP, redimensionnement, `width`/`height`, `fetchpriority="high"` sur la LCP |
| **Icônes sociales en PNG** | `facebook.png` 455×455, `twitter.png`, `instagram.png` pour un rendu à ~50px | Les **SVG existent déjà** : `icone_Facebook.svg`, `icone_Twitter.svg`, `icone_Instagram.svg`. Les utiliser |
| **Chemins cassés** | `node--6.html.twig` et `global.js` pointent vers `/themes/custom/liboke/` — le thème **v1**, pas v2 | Sans objet après portage |
| **Analytics sans consentement** | Tag GA4 en dur dans `html.html.twig` | Voir §11 |
| **Grille non sémantique** | `<div class="col-sm-3">` partout, `role="heading"` sans niveau sur un `<div>` | `<header>`, `<nav>`, `<main>`, `<footer>`, `<article>` (CLAUDE.md §7) |
| **Zone morte responsive** | Unique palier à 575px (valeur Bootstrap 4) alors que la grille bascule à 768px | Paliers cohérents (§5) |

---

## 9. Décision proposée — abandonner Bootstrap 3

### Le constat

Le CSS custom ne peut pas fonctionner sans Bootstrap 3, et Bootstrap 3 n'est pas dans le dépôt. Il faut donc trancher.

### Options

| Option | Coût | Conséquence |
|---|---|---|
| Récupérer Bootstrap 3.4.1 et le figer dans AssetMapper | Faible à court terme | ~120 Ko de CSS dont on utilise 5 %, framework en **fin de vie depuis 2019**, jQuery requis pour le carrousel et le menu mobile. Incompatible avec la cible Lighthouse ≥ 95 et avec l'interdit « pas de dépendance CDN » de l'esprit du cahier des charges |
| **Réécrire la mise en page en CSS moderne** *(recommandé)* | Quelques jours | Grid/Flexbox natifs, aucun JS pour la mise en page, CSS final estimé à **moins de 15 Ko**. Le design est simple : un en-tête, un menu, un bandeau, une grille de contenu |
| Adopter Bootstrap 5 | Moyen | Remplace un framework lourd par un autre, oblige à réécrire toutes les classes de toute façon. Aucun gain |

### Ce que « fidèle au thème » veut dire concrètement

CLAUDE.md §5 exige un **rendu visuel identique**. Comme le CSS n'est pas réutilisable en l'état, la fidélité porte sur la **charte et la structure**, pas sur les classes :

**Couleurs** (relevées dans `style.css`)

| Rôle | Valeur | Usage |
|---|---|---|
| Vert LIBOKÉ | `#006952` | Bandeau d'en-tête, titres, encart d'accueil |
| Or | `#ffc24d` | Accents, survol |
| Or secondaire | `#f7c563` | Élément de menu actif, sous-titres |
| Noir | `#000` | Barre de navigation |
| Gris clair | `#f7f7f7` | Fonds de section |
| Rouge erreur | `#a51b00` | Messages d'erreur |

**Typographie** : Saira Semi Condensed (Regular + Bold, licence OFL fournie), repli `Helvetica, Arial, sans-serif`. Menu en **majuscules**, `font-weight: bold`, `font-size: 1.5em`, séparateurs verticaux `1px solid #666`, élément actif en `#f7c563`.

**Structure de l'en-tête** : logo sur fond blanc à gauche (~25 %), bandeau vert texturé (`bandeau-vert.png`) à droite avec les réseaux sociaux, puis barre noire avec ombre intérieure (`box-shadow: 0 8px 5px -5px #666 inset`) et menu aligné à droite. Bandeau de trois photos sur les pages internes uniquement.

Le `screenshot.png` fait foi comme référence visuelle.

---

## 10. Assets à copier dans le nouveau projet

À copier depuis `../associationliboke.com/themes/custom/libokev2/` (jamais de symlink, CLAUDE.md §3) :

| Source | Destination | Traitement |
|---|---|---|
| `Saira_Semi_Condensed/SairaSemiCondensed-{Regular,Bold}.ttf` | `assets/fonts/` | Conversion **woff2**, les 7 autres graisses écartées |
| `Saira_Semi_Condensed/OFL.txt` | `assets/fonts/` | **Obligatoire** — la licence OFL impose de la conserver |
| `images/logo.svg` | `assets/images/` | Format vectoriel à privilégier sur `logo.jpg` / `logo.png` |
| `images/bandeau-vert.png` | `assets/images/` | Texture d'en-tête → WebP |
| `images/bg-1.png`, `bg-2.png`, `bg-3.png` | `assets/images/` | Bandeau interne → AVIF/WebP, `srcset` |
| `images/icone_{Facebook,Twitter,Instagram}.svg` | `assets/images/` | À préférer aux PNG (455×455 pour un rendu à ~50px) |
| `images/youtube-2.svg` | `assets/images/` | Icône YouTube, déjà en SVG |
| `images/accueil.png` | `assets/images/` | **4,4 Mo** — à recompresser avant tout usage, candidate LCP |
| `favicon.ico` | `public/` | Compléter avec un PNG 512×512 pour le manifest |
| `screenshot.png` | `docs/reference/` | Référence visuelle, hors assets servis |

**À ne pas copier :** `images/old/` (32 fichiers, **16 Mo**, dont des photos brutes `DSC_*.JPG` et des GIF de chargement), `js/global.js`, `js/scripts.js`, `css/style.css` (référence de charte uniquement), tous les fichiers `.DS_Store`.

Les photographies du site (visages, terrain) ne sont **pas** dans le thème : elles étaient en base et dans `sites/default/files`. **À demander au client** (§11).

---

## 11. Points nécessitant une décision

| # | Sujet | Question |
|---|---|---|
| 1 | **Bootstrap** | Valides-tu l'abandon de Bootstrap 3 au profit d'un CSS moderne écrit à la main (§9) ? C'est la décision qui conditionne la phase 2 |
| 2 | **Google Analytics** | On reprend le tag GA4 `G-BCGEBR2L65` — et il faut alors un bandeau de consentement conforme — ou on part sans mesure d'audience, ou sur une solution sans cookie ? CLAUDE.md §11 part actuellement sur **aucun cookie, donc aucun bandeau** |
| 3 | **Compte Stripe** | Le compte de production existe déjà (clé `pk_live_` trouvée dans `scripts.js`). Récupérer les accès, ou ouvrir un nouveau compte ? |
| 4 | **Photographies** | Les visuels du site (hors thème) étaient en base. Le client peut-il fournir les originaux ? Sinon le bandeau de trois images et la page d'accueil resteront en `[À COMPLÉTER]` |
| 5 | **Menu** | Le menu historique était : L'ASSOCIATION · NOS ACTIONS · NOUS SOUTENIR · COMMANDER · CONTACT, plus une recherche et un bouton SE CONNECTER. « Commander », la recherche et la connexion disparaissent. Confirmer le menu cible (CLAUDE.md §6 prévoit Accueil, L'association, Nos actions, Partenaires, Nous soutenir, Contact) |
| 6 | **Carrousel** | Les pages Drupal avaient un carrousel d'images (`field_images`). On le conserve — contrôleur Stimulus, quelques dizaines de lignes — ou on passe à une galerie statique, meilleure pour le SEO et la performance ? |
| 7 | **Événements** | Une vue « événements » (titre, date, lieu) existait. Page Événements à prévoir, ou hors périmètre ? |
| 8 | **Anciennes URLs** | Les alias Drupal sont perdus avec la base. Peut-on récupérer une liste d'URLs indexées (Search Console, export d'un crawl, archive web) pour alimenter les redirections 301 de `config/redirects.yaml` (CLAUDE.md §7) ? Sans cela, on perd le capital SEO de l'ancien site |

---

## 12. Plan de la phase 2

Sous réserve de la validation du point 1 :

1. Copier les assets retenus (§10), convertir les polices en woff2, recompresser les images.
2. Écrire `base.html.twig` : `<html lang="fr">`, `<head>` SEO complet, lien d'évitement, `{{ importmap('app') }}`.
3. Écrire `layout/_header.html.twig` : logo, bandeau vert, réseaux sociaux (SVG), barre noire, menu.
4. Écrire `layout/_footer.html.twig` — **le thème n'en fournit aucun**, la région `footer` était alimentée par des blocs perdus avec la base. Contenu à définir avec le client.
5. Menu mobile en CSS et `<details>`, ou un contrôleur Stimulus de quelques lignes — sans jQuery ni Bootstrap.
6. Fichier de tokens CSS (`assets/styles/_tokens.css`) reprenant la palette et la typographie du §9.
7. Comparer le rendu avec `screenshot.png` à 375px, 768px et 1440px.

---

*Fin de l'audit.*

---

## Suites données (mise à jour 2026-09-20)

- **Point 1, Bootstrap** : abandon validé par le client. Le layout a été réécrit en CSS moderne en phase 2 (`assets/styles/app.css`), sans jQuery ni framework.
- **Points 2 à 8** : toujours ouverts. L'état à jour de ces décisions est suivi dans **`docs/avancement.md` §6**, qui fait désormais foi.
- Les recommandations d'assets du §10 ont été appliquées en phase 2 : polices converties en woff2 et sous-ensemblées, images en WebP avec repli, `viewBox` des SVG recadrées, `images/old/` non importé.
