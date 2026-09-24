# État d'avancement — site association LIBOKÉ

> Dernière mise à jour : **2026-09-20**.
> Documents liés : `CLAUDE.md` (cahier des charges, fait foi), `docs/theme-audit.md` (phase 0).

---

## 1. Reprendre le travail

```bash
cd ~/Projects/liboke
make up          # FrankenPHP + PostgreSQL + Mailpit
make test        # 89 tests, 498 assertions
make lint        # PHP-CS-Fixer + PHPStan 9 + Twig + YAML + conteneur
```

| Service | Adresse |
|---|---|
| Site | `https://localhost` (certificat auto-signé, accepter l'avertissement) |
| Mailpit | `http://localhost:8025` |
| Base | non exposée sur l'hôte → `make db` |

**Piège n° 1 en développement** : les courriels partent en asynchrone et **aucun worker ne tourne par défaut**. Un message envoyé reste dans `messenger_messages` tant qu'on ne lance pas `make worker` — Mailpit paraît vide alors que tout fonctionne.

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
| 6 | Dons Stripe : Checkout, webhook signé et idempotent, remerciements | **Livrée, non validée contre Stripe** |
| 7 | Espace admin, purge planifiée, pages légales | À faire |
| 8 | Performance, accessibilité, Lighthouse | À faire |
| 9 | Préparation production, sauvegardes | À faire |

**Volume actuel** : 41 classes PHP, 14 fichiers de test, 23 gabarits Twig, 3 migrations, 8 pages de contenu, 721 lignes de CSS.
**Tables** : `contact_message`, `donation`, `stripe_event`, `messenger_messages`, `doctrine_migration_versions`.

---

## 3. ⚠️ Point d'arrêt : valider Stripe avant toute clé live

C'est la première chose à faire en reprenant. CLAUDE.md §16 l'impose, et rien n'a encore joint Stripe.

1. Récupérer les accès du **compte Stripe existant de l'association** — il est en production, ses dons historiques y sont (clé `pk_live_` trouvée dans l'ancien thème, cf. audit §7). Ne pas en créer un nouveau.
2. Mettre les clés **de test** dans `.env.local`, jamais dans `.env` :
   ```
   STRIPE_SECRET_KEY=sk_test_…
   STRIPE_API_KEY=sk_test_…
   STRIPE_WEBHOOK_SECRET=whsec_…   # celui affiché par la CLI, ≠ celui de prod
   ```
3. `make stripe` relaie les webhooks vers `https://localhost/stripe/webhook`.
4. Parcourir un don avec une carte de test et vérifier : session créée, redirection, retour sur `/don/merci`, don passé à `paid` par le webhook, remerciement en file puis envoyé par `make worker`.

**Ce qui n'est pas vérifié à ce jour** : la création réelle de session, le rendu de la page Stripe, le format exact des webhooks réels. Tout le reste est testé contre un double (`tests/Double/FakeCheckoutSessionFactory`).

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

---

## 5. Pièges rencontrés, à ne pas redécouvrir

- **Protection CSRF sans état (Symfony 8)** — le champ caché contient le marqueur littéral `csrf-token`, la vraie valeur serait posée par un contrôleur Stimulus que nous ne chargeons pas. Symfony retombe alors sur la vérification d'`Origin`/`Referer`, ce qui **fonctionne sans JavaScript** (vérifié au navigateur). Conséquence pour les tests : un POST doit envoyer `_token = 'csrf-token'` **et** un en-tête `Referer`.
- **422, pas 200** — depuis Symfony 6.2, `render()` répond 422 quand un formulaire soumis est invalide.
- **`empty_data => ''`** obligatoire sur les champs texte mappés vers une propriété typée `string` : un champ vide arrive à `null`.
- **Assertions sur les courriels avant `followRedirect()`** — suivre une redirection redémarre le noyau et vide le journal du mailer.
- **Le client de test redémarre le noyau avant chaque requête** : un état d'instance dans un service (compteur, cache `ArrayAdapter`) repart de zéro. `ArrayAdapter` est en plus remis à zéro par le resetter de services. Pour le limiteur de débit, on garde le stockage de production et on le remet à zéro explicitement dans les tests.
- **Spécificité CSS** — `.champ input[type="text"]` l'emporte sur `.champ--erreur input`. Les sélecteurs d'état doivent être au moins aussi spécifiques.
- **Recette Flex et `CLAUDE.md`** — l'initialisation Symfony écrase `CLAUDE.md` par un pointeur vers `AGENTS.md`. Sauvegarder avant tout `composer create-project` ou recette.
- **SVG du thème** — logo et icônes sociales étaient exportés sur un plan de travail A4 ; sans recadrage de la `viewBox` ils s'affichent minuscules. `logo.svg` reste un faux vectoriel (bitmaps en base64, 69 Ko).

---

## 6. Décisions en attente

### Bloquantes pour la suite

| # | Sujet | Impact |
|---|---|---|
| 1 | **Google Analytics** | L'ancien site avait un tag GA4 (`G-BCGEBR2L65`) sans consentement. Le reprendre impose un bandeau cookies et annule le « aucun cookie » du §11. **Bloque la phase 7** (politique de confidentialité) |
| 2 | **Anciennes URLs indexées** | `config/redirects.yaml` ne couvre que le préfixe de langue `/fr/*` et `/accueil`. Sans la liste des URLs réellement indexées (Search Console, crawl, archive web), le capital SEO de l'ancien site est perdu |
| 3 | **Compte Stripe** | Voir §3 ci-dessus |

### À confirmer avec le client

- Éligibilité au **mécénat** et obligation déclarative des dons — conditionne tout le volet reçus fiscaux.
- **Plafond de don** : 5 000 € fixé par défaut dans `config/donations.yaml`.
- **Durée de conservation** des données de dons (celle des messages de contact est fixée à 12 mois).
- **Photographies** et contenus réels : toutes les pages sont en `[À COMPLÉTER]`. L'image d'accueil actuelle vient de l'ancien programme de bons et ne correspond plus au propos.
- **Menu** : les six entrées actuelles suivent CLAUDE.md §6, à valider.
- **Carrousel** d'images et page **Événements** : à conserver ou non.
- Coordonnées de l'association, mentions légales, hébergeur.

### À trancher côté technique

- **`git init`** : le projet n'est pas un dépôt. La racine git courante est `~/Projects`, où `liboke/` n'est qu'un dossier non suivi. Rien n'a été committé. Les Conventional Commits du §15 ne peuvent pas s'appliquer avant.
- **www / sans-www** avant de figer `CANONICAL_URL`.
- Remplacer `logo.svg` par un vrai vectoriel si le client peut en fournir un.

---

## 7. Prochaine étape

Après la validation Stripe (§3), **phase 7** : espace `/admin` en lecture seule (compte unique en variable d'environnement, aucune table `user`), planification de `app:purge-contact-messages`, et rédaction des pages Mentions légales et Politique de confidentialité — cette dernière dépend de la décision Google Analytics.
