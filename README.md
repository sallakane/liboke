# Association LIBOKÉ — site vitrine

Refonte du site [association-liboke.com](https://association-liboke.com), qui remplace l'ancien Drupal.
Site vitrine à contenu statique (Markdown versionné), formulaire de contact et dons en ligne via Stripe Checkout.

> **État :** phases 0 à 8a livrées, production préparée (phase 9). Le parcours de don a été validé de bout en bout
> en mode test Stripe ; le branchement du compte de production reste à faire.
> Détails dans [`docs/avancement.md`](docs/avancement.md).

## Stack

- **Symfony 8.1**, PHP ≥ 8.4, servi par **FrankenPHP** (base `dunglas/symfony-docker`)
- Rendu **Twig côté serveur**, assets via **AssetMapper** (ni Node ni bundler)
- Contenu éditorial en **Markdown + front matter** dans `content/`, jamais en base
- **PostgreSQL 16 + Doctrine**, réservés aux données transactionnelles (dons, événements Stripe, messages de contact)
- **Stripe Embedded Checkout** (formulaire de paiement Stripe intégré à la page) + webhooks signés et idempotents
- **Stimulus** pour l'interactivité, contrôleurs chargés à la demande
- **Messenger** (transport `doctrine`) pour les emails asynchrones
- PHPUnit, PHPStan, PHP-CS-Fixer

## Démarrage

Prérequis : Docker avec Compose v2. Tout tourne dans les conteneurs, rien n'est à installer sur l'hôte.

```bash
make up        # build + démarrage (FrankenPHP, PostgreSQL, Mailpit)
make migrate   # applique les migrations
```

| Service | Adresse |
|---|---|
| Site | https://localhost (certificat auto-signé : accepter l'avertissement) |
| Mailpit | http://localhost:8025 |
| Base | non exposée sur l'hôte → `make db` |

> Les emails partent en asynchrone : lancer `make worker` pour qu'ils arrivent dans Mailpit.
> Le même worker exécute les tâches planifiées (purge des messages de contact de plus de 12 mois).

Les secrets locaux (clés Stripe de test, etc.) vont dans `.env.local`, qui n'est pas versionné.
**Aucune clé live dans un fichier du dépôt.**

## Commandes

`make help` liste toutes les commandes. Les principales :

| Commande | Rôle |
|---|---|
| `make up` / `make down` | Démarrer / arrêter la stack |
| `make sh` | Shell dans le conteneur `php` |
| `make cc` | Vider le cache Symfony |
| `make test` | PHPUnit (prépare la base de test) |
| `make lint` | PHP-CS-Fixer (dry-run), PHPStan, lint Twig / YAML / conteneur |
| `make fix` | Appliquer PHP-CS-Fixer |
| `make migration` / `make migrate` | Générer / appliquer les migrations Doctrine |
| `make worker` | Consommer la file Messenger et les tâches planifiées |
| `make admin-password` | Générer le hash du mot de passe de l'espace `/admin` |
| `make images` | Générer les variantes WebP et responsives des images (à committer) |
| `make audit` | Audit Lighthouse mobile sur un build de production local (Node.js et Chrome requis) |
| `make stripe` | Relayer les webhooks Stripe en local (nécessite `STRIPE_API_KEY`) |

## Espace d'administration

`/admin` donne accès, en lecture seule, aux dons (filtres, totaux, export CSV) et aux messages de contact.
Un seul compte, déclaré par variables d'environnement, sans aucune table utilisateur :

```bash
make admin-password   # affiche le hash du mot de passe choisi
```

puis dans `.env.local` (ou l'environnement du serveur en production) :

```
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH='$2y$13$…'
```

## Modifier le contenu

Une page = un fichier dans `content/pages/`. Le front matter définit le titre, le slug, l'entrée de menu et les métadonnées SEO :

```markdown
---
title: "L'association"
slug: l-association
menu: { label: "L'association", weight: 20 }
seo:
  title: "L'association LIBOKÉ — Histoire, mission et équipe"
  description: "150 à 160 caractères décrivant la page."
template: default
updated: "2026-09-20"
---

Contenu en **Markdown**.
```

Pour une photo dans une page : la déposer dans `assets/images/contenu/`, lancer `make images`, puis l'insérer avec
`![Description de la photo](images/contenu/photo.jpg)`. Elle est servie en WebP, à la bonne taille selon l'écran.

Les informations globales (coordonnées, réseaux sociaux, pied de page) sont dans `content/site.yaml`.
Les textes encore provisoires sont marqués `[À COMPLÉTER]`.

## Production

VPS partagé, derrière le Caddy de l'hôte. Procédure complète : [`docs/deploiement-vps.md`](docs/deploiement-vps.md).

```bash
git pull && bin/deploy     # sauvegarde, build, migrations, vérification
bin/prod ps                # toutes les commandes Compose de prod passent par bin/prod
bin/backup-db              # sauvegarde (planifiée chaque nuit par cron)
```

## Documentation

- [`CLAUDE.md`](CLAUDE.md) — cahier des charges : il fait foi
- [`docs/avancement.md`](docs/avancement.md) — état d'avancement, décisions prises, pièges, point de reprise
- [`docs/deploiement-vps.md`](docs/deploiement-vps.md) — déploiement et exploitation sur le VPS
- [`docs/theme-audit.md`](docs/theme-audit.md) — audit du thème Drupal `libokev2` d'origine
