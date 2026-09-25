# Déploiement sur le VPS partagé — procédure

> Destinataire : la session Claude Code lancée **sur le VPS**, et le développeur.
> Préparé et répété en local le 2026-09-25 (même montage : Caddy devant, application sur un port local).
> Lire ce document **en entier** avant toute commande.

---

## 0. Règles absolues

Le VPS héberge d'autres projets en production. Rien de ce qui suit ne doit les interrompre.

1. **Ne jamais toucher aux autres projets** : `sunu-cagnotte` (Docker, port local 8080), `rapport-generator` (systemd, port local 8001), et tout autre projet découvert à l'état des lieux. Pas de `docker compose` dans leurs dossiers, pas d'arrêt de leurs conteneurs ou services.
2. **Ne jamais remplacer `/etc/caddy/Caddyfile`** : on y **ajoute** le bloc de liboke. Sauvegarder le fichier avant, et **valider avant de recharger** (§4.5). Un `reload` sur un fichier invalide est refusé par Caddy, mais une validation évite toute surprise.
   ⚠️ `rapport-generator/infra/Caddyfile.patch` se présente comme le « contenu COMPLET » du Caddyfile : le recopier plus tard effacerait liboke. Ne pas l'utiliser.
3. **Commandes Docker de liboke : toujours via `bin/prod`** (jamais `docker compose` nu). Il cible le projet Compose `liboke` et son fichier de secrets.
4. **Interdits** : `docker system prune`, `docker volume prune`, `docker network prune`, `bin/prod down -v` (le `-v` détruit la base des dons), toute commande `docker` globale qui toucherait les autres projets.
5. **Secrets** : ne jamais les afficher dans le terminal ni les recopier dans un message. Les écrire directement dans `.env.prod.local`.
6. **Demander au développeur avant** : toute modification DNS, le rechargement de Caddy, et toute action irréversible.

---

## 1. Architecture

```
Internet ──443──▶ Caddy de l'hôte (systemd, /etc/caddy/Caddyfile, certificats Let's Encrypt)
                   ├─ sunu-cagnotte.org        → 127.0.0.1:8080  (autre projet)
                   ├─ ag-rapport-generator.fr  → 127.0.0.1:8001  (autre projet)
                   └─ association-liboke.org   → 127.0.0.1:8090  ← liboke
                                                   │
                     projet Compose « liboke » (réseau privé liboke_default)
                     ├─ php       FrankenPHP, mode worker, HTTP simple sur :80,
                     │            publié sur 127.0.0.1:8090 seulement ; applique
                     │            les migrations au démarrage
                     ├─ worker    Messenger : e-mails + purge RGPD planifiée
                     └─ database  PostgreSQL 16, volume liboke_database_data,
                                  jamais exposé
```

- Le Caddy de l'hôte termine le HTTPS. Symfony croit ses en-têtes `X-Forwarded-*` (`config/packages/framework.yaml`, `when@prod`). Sans cela : redirection en boucle, `/admin` refusé, limiteurs de débit communs à tous.
- Mémoire bornée (php 512 Mo, worker 256 Mo, base 512 Mo) et journaux Docker tournants (3 × 10 Mo par conteneur).
- Code dans `/var/www/liboke`, secrets dans `/var/www/liboke/.env.prod.local` (modèle : `infra/prod.env.example`).

---

## 2. État des lieux (lecture seule)

À faire et à résumer au développeur **avant** toute modification :

```bash
whoami; id                                   # l'utilisateur doit pouvoir lancer docker
docker --version; docker compose version     # Compose v2 requis
systemctl is-active caddy
sudo caddy version
free -h; df -h /var/lib/docker /var/www      # RAM et disque disponibles
ss -ltnp | grep -E ':(8090) '                # le port 8090 doit être LIBRE
docker ps --format '{{.Names}}\t{{.Ports}}'  # projets en place (ne pas y toucher)
sudo cat /etc/caddy/Caddyfile                # sites déjà servis
dig +short association-liboke.org www.association-liboke.org
curl -s https://api.ipify.org; echo          # IP publique du VPS, à comparer au DNS
```

Si le port 8090 est pris, en choisir un autre libre : le reporter dans `LIBOKE_HTTP_PORT` **et** dans le bloc Caddy.

---

## 3. Questions à poser au développeur

1. **Domaine** : `association-liboke.org` (confirmé par le développeur). Redirection de `www.` vers le domaine nu, comme les autres projets du VPS : à confirmer.
   Si l'ancien site Drupal est encore servi sur ce domaine ailleurs, la bascule DNS le coupera : le faire confirmer avant.
2. **DNS** : le domaine pointe-t-il déjà vers ce VPS (§2) ? Sinon, c'est au développeur de modifier les enregistrements A/AAAA. Tant que le DNS ne pointe pas ici, ne pas ajouter le bloc Caddy : Caddy échouerait à obtenir le certificat (sans gêner les autres sites, mais en boucle dans ses journaux).
3. **Stripe** : clés de **test** pour ce premier déploiement (le compte de production de l'association n'est pas encore branché) ? Elles sont dans le `.env.local` de développement du développeur.
4. **E-mails** : prestataire SMTP choisi ? À défaut, `MAILER_DSN=null://null` : aucun envoi, mais les messages de contact restent lisibles dans `/admin`.
5. **Mot de passe admin** : le développeur le saisit lui-même lors de la génération du hash (§4.3).
6. **Sauvegardes hors serveur** : où copier les sauvegardes quotidiennes (autre serveur, stockage objet) ? Tant que ce n'est pas décidé, elles ne sont que sur le VPS : le signaler.

---

## 4. Premier déploiement

### 4.1 Code

```bash
cd /var/www/liboke && git pull   # ou, s'il n'existe pas encore :
# sudo mkdir -p /var/www/liboke && sudo chown "$USER": /var/www/liboke
# git clone git@github.com:sallakane/liboke.git /var/www/liboke
```

### 4.2 Secrets

```bash
cd /var/www/liboke
cp infra/prod.env.example .env.prod.local
chmod 600 .env.prod.local
```

Remplir chaque variable (le modèle commente chacune) :

| Variable | Valeur |
|---|---|
| `APP_SECRET` | `openssl rand -hex 32` |
| `POSTGRES_PASSWORD` | `openssl rand -base64 24 \| tr -d '/+='` (alphanumérique : il entre dans une URL) |
| `CANONICAL_URL` | `https://association-liboke.org` (réponse §3.1) |
| `SITE_INDEXABLE` | **`0`** : le contenu est encore provisoire |
| `LIBOKE_HTTP_PORT` | `8090` ou le port libre retenu (§2) |
| `MAILER_DSN` | réponse §3.4 |
| `STRIPE_*` | clés de test (§3.3) ; `STRIPE_WEBHOOK_SECRET` au §5 |
| `ADMIN_PASSWORD_HASH` | §4.3, entre apostrophes **simples** |

### 4.3 Construction et démarrage

```bash
bin/deploy
```

Construit l'image, démarre la base, l'application (qui crée le schéma par migration) et le worker, puis vérifie que l'accueil répond 200 sur `127.0.0.1:$LIBOKE_HTTP_PORT` comme le fera Caddy. Le premier build prend plusieurs minutes.

Hash du mot de passe admin (le développeur tape le mot de passe) :

```bash
docker run --rm -it --entrypoint php liboke-php-prod bin/console security:hash-password
```

Le reporter dans `.env.prod.local` (`ADMIN_PASSWORD_HASH='$2y$13$…'`), puis `bin/prod up -d` pour recharger les variables.

### 4.4 Vérification avant Caddy

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: association-liboke.org' -H 'X-Forwarded-Proto: https' http://127.0.0.1:8090/
# 200 attendu
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' -H 'Host: association-liboke.org' http://127.0.0.1:8090/
# 301 vers https://association-liboke.org/ attendu (en-têtes de proxy absents)
bin/prod ps        # php « healthy », worker et database « Up »
```

### 4.5 Caddy (avec l'accord du développeur)

```bash
sudo cp /etc/caddy/Caddyfile /etc/caddy/Caddyfile.avant-liboke-$(date +%F)
```

Ajouter **à la fin** de `/etc/caddy/Caddyfile` le contenu de `infra/caddy/liboke.caddy` (adapter le port s'il n'est pas 8090), puis :

```bash
sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile   # doit dire « Valid configuration »
sudo systemctl reload caddy                                             # rechargement à chaud, sans coupure
```

En cas de problème : remettre la copie `Caddyfile.avant-liboke-…` et recharger.

### 4.6 Vérifications finales

```bash
curl -sI https://association-liboke.org | head -5              # 200, certificat valide
curl -sI https://www.association-liboke.org | grep -i location   # 301 vers le domaine nu
curl -s https://association-liboke.org/robots.txt               # « Disallow: / » tant que SITE_INDEXABLE=0
curl -sI https://association-liboke.org/admin | grep -i location # vers /admin/connexion
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://association-liboke.org/stripe/webhook   # 400 (signature absente), surtout pas 301
# Les autres projets répondent toujours :
curl -sI https://sunu-cagnotte.org | head -1
curl -sI https://ag-rapport-generator.fr | head -1
```

Puis connexion à `https://association-liboke.org/admin` avec le compte du §4.3.

---

## 5. Webhook Stripe

Dans le tableau de bord Stripe (mode **test** pour l'instant) → Développeurs → Webhooks → Ajouter un endpoint :

- URL : `https://association-liboke.org/stripe/webhook`
- Événements : `checkout.session.completed`, `charge.refunded`

Copier son secret de signature (`whsec_…`) dans `STRIPE_WEBHOOK_SECRET`, puis `bin/prod up -d`. Faire un don de test (carte `4242 4242 4242 4242`) et vérifier qu'il passe à « Payé » dans `/admin/dons` (le webhook fait foi, pas la page de retour).

---

## 6. Sauvegardes

Le registre des dons n'est pas reconstituable : la sauvegarde n'est pas optionnelle.

```bash
sudo mkdir -p /var/backups/liboke && sudo chown "$USER": /var/backups/liboke
bin/backup-db                                              # sauvegarde relue avant d'être gardée
bin/restore-db "$(ls -t /var/backups/liboke/*.dump | head -1)"   # restauration de TEST dans une base temporaire
```

Planification quotidienne (3 h 05, avant la purge RGPD de 3 h 17), pour l'utilisateur qui lance Docker :

```bash
( crontab -l 2>/dev/null; echo '5 3 * * * cd /var/www/liboke && bin/backup-db >> /var/log/liboke-backup.log 2>&1' ) | crontab -
sudo touch /var/log/liboke-backup.log && sudo chown "$USER": /var/log/liboke-backup.log
```

Rétention : 14 jours (`BACKUP_RETENTION_DAYS`). **Copie hors serveur : à mettre en place selon la réponse §3.6.** Refaire un `bin/restore-db` de temps en temps.

### Restauration réelle (écrase la base de production)

Uniquement sur décision du développeur, après avoir mis de côté une sauvegarde de l'état actuel :

```bash
bin/backup-db                                   # état actuel, au cas où
bin/prod stop php worker
bin/prod exec -T database sh -c 'dropdb -U "$POSTGRES_USER" "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
bin/prod exec -T database sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error' < /var/backups/liboke/liboke-AAAAMMJJ-HHMMSS.dump
bin/prod up -d
```

---

## 7. Mises à jour

```bash
cd /var/www/liboke && git pull && bin/deploy
```

`bin/deploy` sauvegarde d'abord la base, reconstruit, redémarre (migrations comprises) et vérifie l'accueil. Coupure de quelques secondes le temps du redémarrage du conteneur php.

**Retour arrière** : `git log --oneline`, `git checkout <commit>`, `bin/deploy`. Si une migration a modifié le schéma entre-temps, restaurer aussi la sauvegarde prise par le déploiement fautif (§6).

---

## 8. Exploitation courante

```bash
bin/prod ps
bin/prod logs -f --tail=100 php          # journaux applicatifs (JSON, erreurs uniquement)
bin/prod logs -f --tail=100 worker       # e-mails, purge planifiée
bin/prod exec php bin/console debug:scheduler
bin/prod exec php bin/console messenger:failed:show   # e-mails en échec
bin/prod restart worker
```

Changer le mot de passe admin : nouveau hash (§4.3) dans `.env.prod.local`, puis `bin/prod up -d`. Les sessions ouvertes sont fermées.

---

## 9. Hors de ce déploiement (décisions ouvertes)

- `SITE_INDEXABLE=1` le jour où le contenu réel est en ligne (phase 8b).
- Clés Stripe **live** et endpoint de production : après accès au compte Stripe de l'association et validation des textes légaux.
- SMTP définitif, avec SPF, DKIM et DMARC sur le domaine.
- Redirections 301 des anciennes URLs Drupal (`config/redirects.yaml`) : liste des URLs indexées à obtenir.
- Copie des sauvegardes hors du VPS.
