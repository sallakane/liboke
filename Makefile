# Association LIBOKÉ — raccourcis de développement (CLAUDE.md §13).
# Tout passe par Docker : aucune commande n'est prévue pour tourner sur l'hôte.

DOCKER_COMP = docker compose
PHP_CONT    = $(DOCKER_COMP) exec php
PHP         = $(PHP_CONT) php
COMPOSER    = $(PHP_CONT) composer
CONSOLE     = $(PHP) bin/console

.DEFAULT_GOAL = help
.PHONY: help build up down logs sh cc test test-db lint fix assets db migration migrate worker stripe install admin-password

help: ## Liste les commandes disponibles
	@grep -E '(^[a-zA-Z_-]+:.*?##.*$$)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "  \033[32m%-12s\033[0m %s\n", $$1, $$2}'

## —— Docker ————————————————————————————————————————————————————————————
build: ## Construit les images sans cache
	@$(DOCKER_COMP) build --pull --no-cache

up: ## Construit si besoin et démarre la stack, puis suit les logs
	@$(DOCKER_COMP) up --build --detach
	@echo "→ https://localhost   (certificat auto-signé : accepter l'avertissement)"
	@echo "→ http://localhost:8025   Mailpit"

down: ## Arrête la stack (les volumes sont conservés)
	@$(DOCKER_COMP) down --remove-orphans

logs: ## Suit les logs du conteneur php
	@$(DOCKER_COMP) logs --tail=0 --follow php

sh: ## Ouvre un shell dans le conteneur php
	@$(PHP_CONT) sh

## —— Symfony ———————————————————————————————————————————————————————————
install: ## Installe les dépendances Composer
	@$(COMPOSER) install --prefer-dist

cc: ## Vide le cache Symfony
	@$(CONSOLE) cache:clear

assets: ## Compile la carte des assets (vérification, AssetMapper sert à la volée en dev)
	@$(CONSOLE) asset-map:compile

# --no-debug : en dev, Doctrine garde la trace de chaque requête pour le profiler ;
# le worker interrogeant la base chaque seconde saturait la mémoire en quelques minutes.
worker: ## Consomme la file Messenger (e-mails) et les tâches planifiées (purge RGPD)
	@$(CONSOLE) messenger:consume async scheduler_default -vv --no-debug --memory-limit=128M

admin-password: ## Génère le hash du mot de passe administrateur (ADMIN_PASSWORD_HASH)
	@$(CONSOLE) security:hash-password --empty-salt 'Symfony\Component\Security\Core\User\InMemoryUser'

## —— Base de données ———————————————————————————————————————————————————
db: ## Ouvre psql sur la base (non exposée sur l'hôte)
	@$(DOCKER_COMP) exec database psql -U $${POSTGRES_USER:-liboke} -d $${POSTGRES_DB:-liboke}

migration: ## Génère une migration à partir des entités
	@$(CONSOLE) doctrine:migrations:diff

migrate: ## Applique les migrations en attente
	@$(CONSOLE) doctrine:migrations:migrate --no-interaction --all-or-nothing

test-db: ## Crée la base de test et y applique les migrations
	@$(CONSOLE) doctrine:database:create --env=test --if-not-exists --quiet
	@$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction --all-or-nothing --quiet

## —— Qualité ———————————————————————————————————————————————————————————
test: test-db ## Lance PHPUnit (prépare d'abord la base de test)
	@$(PHP) bin/phpunit $(ARGS)

lint: ## PHP-CS-Fixer (dry-run) + PHPStan + lint Twig, YAML et conteneur
	@$(PHP_CONT) vendor/bin/php-cs-fixer check --diff
	@$(PHP_CONT) vendor/bin/phpstan analyse --memory-limit=-1
	@$(CONSOLE) lint:twig templates
	@$(CONSOLE) lint:yaml config
	@$(CONSOLE) lint:container

fix: ## Applique les corrections PHP-CS-Fixer
	@$(PHP_CONT) vendor/bin/php-cs-fixer fix

## —— Stripe (phase 6) ——————————————————————————————————————————————————
stripe: ## Relaie les webhooks Stripe vers l'app (nécessite STRIPE_API_KEY)
	@$(DOCKER_COMP) --profile stripe up --detach stripe-cli
	@$(DOCKER_COMP) logs --follow stripe-cli
