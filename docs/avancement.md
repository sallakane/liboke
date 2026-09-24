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
| 6 | Dons Stripe : Checkout, webhook signé et idempotent, remerciements | **Livrée, validée en mode test** (remboursement non joué) |
| 7 | Espace admin, purge planifiée, pages légales | **Livrée**, textes légaux à compléter par l'association (`[À COMPLÉTER]`) |
| 8 | Performance, accessibilité, Lighthouse | À faire |
| 9 | Préparation production, sauvegardes | À faire |

**Volume actuel** : 47 classes PHP, 18 fichiers de test, 28 gabarits Twig, 3 migrations, 8 pages de contenu, 888 lignes de CSS.
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

**Constats :**
- Le compte de test est en **API Stripe 2020-03-02** (très ancienne). Les événements arrivent dans ce format, et le gestionnaire les lit correctement, adresse comprise. Le compte de production aura sa propre version : à vérifier au branchement de l'endpoint, qui peut être créé avec une version d'API explicite.
- `stripe_customer_id` reste vide : en mode `payment`, Checkout ne crée pas de client Stripe. Sans conséquence au lancement ; à revoir avec le don mensuel (Customer Portal).

**Reste à faire avant toute clé live :**
1. Récupérer les accès du **compte Stripe existant de l'association** — il est en production, ses dons historiques y sont (clé `pk_live_` trouvée dans l'ancien thème, cf. audit §7). Ne pas en créer un nouveau. Vérifier que les clés de test utilisées appartiennent bien à ce compte.
2. Tester un **remboursement** (`charge.refunded`), non encore joué en réel.
3. En production : déclarer l'endpoint `https://<hôte>/stripe/webhook` dans le tableau de bord (événements `checkout.session.completed` et `charge.refunded`) et placer son `whsec_` dans l'environnement du serveur.

---

## 4. Décisions structurantes déjà prises

| Sujet | Décision | Pourquoi |
|---|---|---|
| **Bootstrap 3** | Abandonné, CSS moderne écrit à la main | Il n'était pas dans le dépôt (chargé par CDN), en fin de vie, et imposait jQuery. Audit §2 et §9 |
| **Base de données** | Cantonnée au transactionnel | Le contenu éditorial reste en Markdown versionné |
| **Comptes utilisateurs** | Aucun | Le Customer Portal de Stripe couvrira la gestion du don mensuel |
| **Stripe Checkout** | Page hébergée, pas Elements | Aucun JS de paiement sur nos pages, conformité PCI réduite au SAQ-A |
| **Données du donateur** | Collectées par Stripe, pas par notre formulaire | Moins de données personnelles chez nous, adresse disponible pour le futur reçu |
| **Menu principal** | Piloté par le front matter des pages (`menu:`) | Le pied de page, lui, est listé dans `content/site.yaml` |
| **Indexation** | `SITE_INDEXABLE` + `CANONICAL_URL`, pas `APP_ENV` | Une recette tourne en `prod` sans devoir être indexée |
| **Cache du contenu** | Court-circuité quand `kernel.debug` | Sinon modifier un `.md` n'aurait aucun effet visible |
| **Stimulus** | Installé mais **pas chargé** | 45 Ko pour zéro contrôleur. Décommenter l'import dans `assets/app.js` au premier besoin |
| **Reçus fiscaux** | Champs réservés, génération non implémentée | Interdiction explicite d'en promettre un, verrouillée par un test |
| **Compte admin** | Fournisseur maison `App\Security\AdminUserProvider`, pas le fournisseur `memory` | `memory` n'accepte pas une variable d'environnement comme identifiant. Variables vides = connexion impossible |
| **Pare-feu** | Limité à `^/admin` | Le reste du site n'ouvre aucune session : pages cachables, webhook hors pare-feu (§10 règle 5) |
| **Admin en lecture seule** | Aucune route d'écriture, verrouillé par un test (405) | Une correction de don se fait dans Stripe, qui fait foi, et revient par webhook |
| **Export CSV** | `;`, virgule décimale, BOM UTF-8, cellules « formule » neutralisées | Ouverture directe dans Excel ; le nom du donateur est saisi par un inconnu chez Stripe |
| **Planification** | `symfony/scheduler` (`src/Schedule.php`), pas de crontab | Versionnée et testée ; tourne dans le worker déjà nécessaire aux courriels. Intervalle quotidien plutôt que cron, pour éviter `dragonmantank/cron-expression` |
| **Politique de confidentialité** | Rédigée sur l'hypothèse « sans Google Analytics » | C'est la règle par défaut de CLAUDE.md §11. Voir décision en attente n° 1 |

---

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

1. **Stripe** (§3) : parcours de don validé en mode test ; restent le remboursement et le branchement du compte de production.
2. **Faire compléter les textes légaux** par l'association : ils doivent être définitifs **avant** la mise en ligne des dons (CLAUDE.md §11).
3. **Phase 8** : performance et accessibilité, audit Lighthouse (cibles ≥ 95), y compris sur les pages `/admin` pour l'accessibilité.
