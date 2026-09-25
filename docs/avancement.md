# État d'avancement — site association LIBOKÉ

> Dernière mise à jour : **2026-09-24**.
> Dépôt : `git@github.com:sallakane/liboke.git`, branche `main`.
> Documents liés : `CLAUDE.md` (cahier des charges, fait foi), `docs/theme-audit.md` (phase 0).

---

## 1. Reprendre le travail

```bash
cd ~/Projects/liboke
make up          # FrankenPHP + PostgreSQL + Mailpit
make test        # 115 tests, 585 assertions
make lint        # PHP-CS-Fixer + PHPStan 9 + Twig + YAML + conteneur
```

| Service | Adresse |
|---|---|
| Site | `https://localhost` (certificat auto-signé, accepter l'avertissement) |
| Mailpit | `http://localhost:8025` |
| Base | non exposée sur l'hôte → `make db` |

**Piège n° 1 en développement** : les courriels partent en asynchrone et **aucun worker ne tourne par défaut**. Un message envoyé reste dans `messenger_messages` tant qu'on ne lance pas `make worker` — Mailpit paraît vide alors que tout fonctionne. Le même worker déclenche les tâches planifiées (purge RGPD).

**Espace `/admin`** : personne ne peut s'y connecter tant que le compte n'est pas déclaré. `make admin-password` produit le hash, à placer dans `.env.local` :

```
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH='$2y$13$…'   # apostrophes simples : le hash contient des « $ »
```

**Ports déjà pris** : si un autre projet occupe 80/443 (cas d'un Traefik local), démarrer avec `HTTP_PORT=8080 HTTPS_PORT=8443 HTTP3_PORT=8443 make up`. L'hôte canonique restant `https://localhost`, les requêtes directes sur `:8443` doivent porter l'en-tête `Host: localhost`, sinon la redirection canonique renvoie vers le port 443.

---

## 2. Où on en est

| Phase | Objet | État |
|---|---|---|
| 0 | Audit du thème Drupal | **Livrée** → `docs/theme-audit.md` |
| 1 | Symfony 8.1.7 + FrankenPHP + PostgreSQL + Mailpit, Makefile, qualité | **Livrée** |
| 2 | Assets du thème, layout fidèle, CSS moderne | **Livrée** |
| 3 | Couche contenu (Markdown + front matter), menu dynamique | **Livrée** |
| 4 | SEO complet : canoniques, JSON-LD, sitemap, robots, 301, pages d'erreur | **Livrée** |
| 5 | Formulaire de contact, anti-spam, purge RGPD | **Livrée** |
| 6 | Dons Stripe : Checkout, webhook signé et idempotent, remerciements | **Livrée, validée en mode test** (remboursement partiel à traiter) |
| 7 | Espace admin, purge planifiée, pages légales | **Livrée**, textes légaux à compléter par l'association (`[À COMPLÉTER]`) |
| 8a | Performance et accessibilité, partie structurelle | **Livrée** : 99-100 partout au Lighthouse mobile, sur build de prod |
| 8b | Intégration du contenu réel, audit final | En attente des textes et photos de l'association |
| 9 | Préparation production, sauvegardes | À faire |

**Volume actuel** : 52 classes PHP, 21 fichiers de test (134 tests), 28 gabarits Twig, 3 migrations, 8 pages de contenu, 922 lignes de CSS.
**Tables** : `contact_message`, `donation`, `stripe_event`, `messenger_messages`, `doctrine_migration_versions`.

---

## 3. Validation Stripe en mode test — faite le 2026-09-24

Parcours réel de bout en bout, clés de test dans `.env.local`, relais `make stripe` :

| Étape | Résultat |
|---|---|
| `POST /don/checkout` | Session réelle créée, 303 vers `checkout.stripe.com`, don `pending` en base |
| Paiement carte `4242…` sur la page Stripe | Retour sur `/don/merci` |
| Webhook `checkout.session.completed` | 200, don passé à `paid` avec nom, e-mail, adresse complète et `payment_intent` |
| Remerciement | Mis en file par le webhook, envoyé par le worker, reçu dans Mailpit |
| Rejeu du même événement (`stripe events resend`) | 200, aucun doublon : 1 événement, 1 don payé, 1 seul e-mail |
| Remboursement complet (`stripe refunds create`) | `charge.refunded` reçu (200), don passé à `refunded`, rejeu sans effet |
| Espace `/admin` | Connexion, liste, filtres, export CSV vérifiés par le développeur |

Le 2026-09-25, le paiement est passé en **Embedded Checkout** : don de 10 € payé dans le formulaire intégré par le développeur, webhook reçu (200), don passé à `paid`, remerciement envoyé. Le webhook et l'idempotence sont inchangés.

**Constats :**
- Le compte de test est en **API Stripe 2020-03-02** (très ancienne). Les événements arrivent dans ce format, et le gestionnaire les lit correctement, adresse comprise. Le compte de production aura sa propre version : à vérifier au branchement de l'endpoint, qui peut être créé avec une version d'API explicite.
- `stripe_customer_id` reste vide : en mode `payment`, Checkout ne crée pas de client Stripe. Sans conséquence au lancement ; à revoir avec le don mensuel (Customer Portal).

**Reste à faire avant toute clé live :**
1. Récupérer les accès du **compte Stripe existant de l'association** — il est en production, ses dons historiques y sont (clé `pk_live_` trouvée dans l'ancien thème, cf. audit §7). Ne pas en créer un nouveau. Vérifier que les clés de test utilisées appartiennent bien à ce compte.
2. **Remboursement partiel** : `charge.refunded` est aussi émis pour un remboursement partiel, et le code passe alors le don entier en `refunded`. Décider du traitement (ignorer tant que `charge.refunded` ne vaut pas `true`, ou stocker le montant remboursé — nouvelle colonne et migration).
3. En production : déclarer l'endpoint `https://<hôte>/stripe/webhook` dans le tableau de bord (événements `checkout.session.completed` et `charge.refunded`) et placer son `whsec_` dans l'environnement du serveur.

---

## 4. Décisions structurantes déjà prises

| Sujet | Décision | Pourquoi |
|---|---|---|
| **Bootstrap 3** | Abandonné, CSS moderne écrit à la main | Il n'était pas dans le dépôt (chargé par CDN), en fin de vie, et imposait jQuery. Audit §2 et §9 |
| **Base de données** | Cantonnée au transactionnel | Le contenu éditorial reste en Markdown versionné |
| **Comptes utilisateurs** | Aucun | Le Customer Portal de Stripe couvrira la gestion du don mensuel |
| **Stripe Checkout** | **Intégré à la page** (`ui_mode: embedded_page`) depuis le 2026-09-25, plus la page hébergée | Choix du développeur : le donateur ne quitte pas le site. Reste SAQ-A (iframe Stripe). Stripe.js chargé à la demande depuis `js.stripe.com`. Pas de page d'annulation (`/don/annule` supprimée) |
| **JavaScript** | Autorisé pour le fonctionnement et l'ergonomie | La règle « le site fonctionne sans JS » a été levée par le développeur le 2026-09-25. Le contenu reste rendu côté serveur |
| **Données du donateur** | Collectées par Stripe, pas par notre formulaire | Moins de données personnelles chez nous, adresse disponible pour le futur reçu |
| **Menu principal** | Piloté par le front matter des pages (`menu:`) | Le pied de page, lui, est listé dans `content/site.yaml` |
| **Indexation** | `SITE_INDEXABLE` + `CANONICAL_URL`, pas `APP_ENV` | Une recette tourne en `prod` sans devoir être indexée |
| **Cache du contenu** | Court-circuité quand `kernel.debug` | Sinon modifier un `.md` n'aurait aucun effet visible |
| **Stimulus** | Chargé ; contrôleurs en `stimulusFetch: 'lazy'` | Le contrôleur `don` n'est téléchargé que sur `/nous-soutenir`. Active aussi `csrf_protection_controller.js` (jeton CSRF en double soumission) |
| **Reçus fiscaux** | Champs réservés, génération non implémentée | Interdiction explicite d'en promettre un, verrouillée par un test |
| **Compte admin** | Fournisseur maison `App\Security\AdminUserProvider`, pas le fournisseur `memory` | `memory` n'accepte pas une variable d'environnement comme identifiant. Variables vides = connexion impossible |
| **Pare-feu** | Limité à `^/admin` | Le reste du site n'ouvre aucune session : pages cachables, webhook hors pare-feu (§10 règle 5) |
| **Admin en lecture seule** | Aucune route d'écriture, verrouillé par un test (405) | Une correction de don se fait dans Stripe, qui fait foi, et revient par webhook |
| **Export CSV** | `;`, virgule décimale, BOM UTF-8, cellules « formule » neutralisées | Ouverture directe dans Excel ; le nom du donateur est saisi par un inconnu chez Stripe |
| **Planification** | `symfony/scheduler` (`src/Schedule.php`), pas de crontab | Versionnée et testée ; tourne dans le worker déjà nécessaire aux courriels. Intervalle quotidien plutôt que cron, pour éviter `dragonmantank/cron-expression` |
| **Politique de confidentialité** | Rédigée sur l'hypothèse « sans Google Analytics » | C'est la règle par défaut de CLAUDE.md §11. Voir décision en attente n° 1 |

---

## 4 bis. Phase 8a — ce qui est en place

**Audit Lighthouse mobile sur build de production (2026-09-25)** — `make audit` :

| Page | Perf | A11y | Prat. | SEO | LCP | CLS |
|---|---|---|---|---|---|---|
| `/` | 99 | 100 | 100 | 100 | 2,0 s | 0 |
| pages internes (7) | 99-100 | 100 | 100 | 100 | 1,7-1,9 s | 0 |

**Images** — `make images` génère, pour chaque JPG/PNG de `assets/images/`, des variantes WebP + format d'origine à 480/800/1200/1600 px (jamais agrandies) dans `assets/images/variantes/`, **versionnées** (la prod n'a pas besoin de GD). Dans les gabarits : `{{ image('photo.jpg', 'Texte alternatif', {sizes: '…', prioritaire: true}) }}`. Dans le Markdown : `![Texte alternatif](images/contenu/photo.jpg)` devient automatiquement un `<picture>` responsive. Un test échoue si une image est ajoutée ou modifiée sans relancer `make images`.

**Autres réglages** :
- Cache HTTP `public, max-age=600, s-maxage=3600` sur l'accueil, les pages de contenu, le sitemap et robots.txt (`App\Http\ContentCache`) ; jamais sur les 404, les pages à formulaire ni `/admin`. Assets : `immutable`, un an, uniquement sur les réponses 2xx (Caddyfile).
- Menu mobile : vrai bouton de divulgation (`aria-expanded`, Échap) au lieu de la case à cocher ; replié avant le premier affichage grâce à la classe `js` posée dans le `<head>` (pas de CLS).
- Contraste AA : le doré du thème ne passait ni sur blanc (baseline d'accueil, 1,6:1) ni sur le vert du pied (4,2:1). Nouvelles teintes `--or-sur-blanc: #9b6908` et `--or-sur-vert: #f9d081`.
- Les deux graisses de police sont préchargées (le gras arrivait tard et décalait le texte), ainsi que le fond de l'en-tête (LCP des pages internes).

**Bugs de production trouvés en construisant l'image `frankenphp_prod`, corrigés** :
- `.dockerignore` excluait `**/*.md`, donc **tout le contenu** : le site de prod aurait été vide.
- `symfony/monolog-bundle` était en `require-dev` alors que le bundle est activé partout : l'image de prod ne démarrait pas.
- `worker.Caddyfile` réclamait `Runtime\FrankenPhpSymfony\Runtime`, non installé et inutile depuis Symfony 7.4 : toutes les requêtes échouaient en mode worker.
- `asset-map:compile` absent du build : CSS et JS auraient répondu 404.

## 5. Pièges rencontrés, à ne pas redécouvrir

- **Protection CSRF sans état (Symfony 8)** — le champ caché contient le marqueur littéral `csrf-token`, la vraie valeur serait posée par un contrôleur Stimulus que nous ne chargeons pas. Symfony retombe alors sur la vérification d'`Origin`/`Referer`, ce qui **fonctionne sans JavaScript** (vérifié au navigateur). Conséquence pour les tests : un POST doit envoyer `_token = 'csrf-token'` **et** un en-tête `Referer`.
- **422, pas 200** — depuis Symfony 6.2, `render()` répond 422 quand un formulaire soumis est invalide.
- **`empty_data => ''`** obligatoire sur les champs texte mappés vers une propriété typée `string` : un champ vide arrive à `null`.
- **Assertions sur les courriels avant `followRedirect()`** — suivre une redirection redémarre le noyau et vide le journal du mailer.
- **Le client de test redémarre le noyau avant chaque requête** : un état d'instance dans un service (compteur, cache `ArrayAdapter`) repart de zéro. `ArrayAdapter` est en plus remis à zéro par le resetter de services. Pour le limiteur de débit, on garde le stockage de production et on le remet à zéro explicitement dans les tests.
- **Spécificité CSS** — `.champ input[type="text"]` l'emporte sur `.champ--erreur input`. Les sélecteurs d'état doivent être au moins aussi spécifiques.
- **Recette Flex et `CLAUDE.md`** — l'initialisation Symfony écrase `CLAUDE.md` par un pointeur vers `AGENTS.md`. Sauvegarder avant tout `composer create-project` ou recette.
- **`composer require` dans le conteneur** crée les nouveaux fichiers (recettes) en `root`. Les rendre à l'utilisateur : `docker compose exec php chown 1000:1000 <fichier>`.
- **Pas de filtre `trans`** — `symfony/translation` n'est pas installé : les messages d'erreur de connexion sont construits dans `AdminController`, pas traduits dans le gabarit.
- **Expressions cron** — `RecurringMessage::cron()` exige `dragonmantank/cron-expression`. On utilise `every('1 day', …, from: '03:17')`.
- **`InputBag` est invariant pour PHPStan** — `DonationFilter::fromQuery()` attend un `InputBag<string>` ; les tests le construisent via un assistant typé.
- **Limiteurs de débit en test** — leurs compteurs vivent dans le cache de fichiers et survivent d'une exécution à l'autre : chaque test qui en dépend doit les remettre à zéro (sinon test instable après quelques lancements rapprochés).
- **Caddyfile intégré à l'image** — il n'est pas monté en volume en dev : toute modification demande `docker compose build php`.
- **`#[Cache]` s'applique aussi à la 404** rendue pour le même contrôleur : d'où `ContentCache::apply()` sur la réponse réussie.
- **Lighthouse local** — Node 18.19 suffit pour `lighthouse@12`. Auditer le build de prod, jamais le dev (profiler, rechargement à chaud).
- **Worker en dev** — sans `--no-debug`, il plante en ~7 min (mémoire épuisée par les traces Doctrine du profiler). `make worker` le passe désormais.
- **Stripe renomme ses paramètres** — l'Embedded Checkout s'appelle désormais `ui_mode: 'embedded_page'` (et non `embedded`), le script est `js.stripe.com/dahlia/stripe.js` et la fonction `createEmbeddedCheckoutPage()`. Vérifier la doc Stripe courante plutôt que sa mémoire.
- **Stripe CLI** — les versions récentes exigent `--events` ; et le relais doit viser `http://php`, pas `https://php` : dans le réseau Docker, FrankenPHP ne sert l'hôte `php` qu'en HTTP (échec TLS « internal error » sinon).
- **`docker compose run`** recrée les services dont il dépend (ici `php`) avec les variables du shell courant : sans `HTTP_PORT=8080 …`, le conteneur repart sur 80/443. Préférer `docker compose exec stripe-cli stripe …`.
- **Connexion dans les tests** — `loginUser()` doit recevoir l'utilisateur du vrai fournisseur : Symfony compare le hash à chaque requête et déconnecte si un utilisateur « nu » est passé.
- **SVG du thème** — logo et icônes sociales étaient exportés sur un plan de travail A4 ; sans recadrage de la `viewBox` ils s'affichent minuscules. `logo.svg` reste un faux vectoriel (bitmaps en base64, 69 Ko).

---

## 6. Décisions en attente

### Bloquantes pour la suite

| # | Sujet | Impact |
|---|---|---|
| 1 | **Google Analytics** | L'ancien site avait un tag GA4 (`G-BCGEBR2L65`) sans consentement. La politique de confidentialité est rédigée **sans** mesure d'audience (règle par défaut du §11). Reprendre GA imposerait un bandeau cookies et la réécriture de sa section « Cookies » |
| 2 | **Anciennes URLs indexées** | `config/redirects.yaml` ne couvre que le préfixe de langue `/fr/*` et `/accueil`. Sans la liste des URLs réellement indexées (Search Console, crawl, archive web), le capital SEO de l'ancien site est perdu |
| 3 | **Compte Stripe** | Voir §3 ci-dessus |

### À confirmer avec le client

- Éligibilité au **mécénat** et obligation déclarative des dons — conditionne tout le volet reçus fiscaux.
- **Plafond de don** : 5 000 € fixé par défaut dans `config/donations.yaml`.
- **Durée de conservation** des données de dons (celle des messages de contact est fixée à 12 mois).
- **Photographies** et contenus réels : toutes les pages sont en `[À COMPLÉTER]`. L'image d'accueil actuelle vient de l'ancien programme de bons et ne correspond plus au propos.
- **Menu** : les six entrées actuelles suivent CLAUDE.md §6, à valider.
- **Carrousel** d'images et page **Événements** : à conserver ou non.
- Coordonnées de l'association, mentions légales, hébergeur : toutes les lignes `[À COMPLÉTER]` de `mentions-legales.md` et `politique-de-confidentialite.md` (forme juridique, RNA, siège, directeur de la publication, hébergeur, prestataire d'envoi des courriels, adresse de contact RGPD).
- Relire la mention de Stripe dans la politique de confidentialité (entité Stripe Payments Europe, transfert vers Stripe, Inc. sous *Data Privacy Framework*) avec la documentation légale Stripe en vigueur.

### À trancher côté technique

- **Compte admin de production** : `ADMIN_USERNAME` et `ADMIN_PASSWORD_HASH` à injecter dans l'environnement du serveur (`compose.prod.yaml` les attend ; doubler les `$` du hash dans un fichier lu par Compose).
- **www / sans-www** avant de figer `CANONICAL_URL`.
- Remplacer `logo.svg` par un vrai vectoriel si le client peut en fournir un.

---

## 7. Prochaine étape

1. **Stripe** (§3) : parcours de don et remboursement complet validés en mode test ; restent le remboursement partiel et le branchement du compte de production.
2. **Faire compléter les textes légaux** par l'association : ils doivent être définitifs **avant** la mise en ligne des dons (CLAUDE.md §11).
3. **Phase 8b**, dès réception du contenu : déposer les photos dans `assets/images/` (ou `assets/images/contenu/` pour le Markdown), `make images`, écrire les textes et les `alt`, puis `make audit`. Remplacer `logo.svg` (69 Ko de bitmaps) si l'association fournit un vrai vectoriel.
4. **Phase 9** : préparation de la production (compose, secrets, sauvegardes) sur instruction du développeur. L'image `frankenphp_prod` se construit et sert le site depuis la phase 8a.
