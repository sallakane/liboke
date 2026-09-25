#!/bin/sh
set -e

if [ "$1" = 'frankenphp' ] || [ "$1" = 'php' ] || [ "$1" = 'bin/console' ]; then
	if [ "$APP_ENV" != 'prod' ]; then
		composer install --prefer-dist --no-progress --no-interaction
	fi

	if grep -q '^DATABASE_URL=' .env 2>/dev/null; then
		echo 'Attente de la base de données…'
		ATTEMPTS_LEFT=60
		until [ "$ATTEMPTS_LEFT" -eq 0 ] || DATABASE_ERROR=$(php bin/console dbal:run-sql -q "SELECT 1" 2>&1); do
			if [ $? -eq 255 ]; then
				# bin/console est cassé : inutile d'insister
				break
			fi
			sleep 1
			ATTEMPTS_LEFT=$((ATTEMPTS_LEFT - 1))
		done

		if [ "$ATTEMPTS_LEFT" -eq 0 ]; then
			echo "$DATABASE_ERROR" >&2
			echo 'Base de données injoignable.' >&2
			echo 'Sous NordVPN, autoriser 172.16.0.0/12 dans la whitelist du VPN.' >&2
			exit 1
		fi

		# SKIP_MIGRATIONS : le worker Messenger démarre avec la même image ; seul
		# le conteneur php migre, sinon deux migrations concurrentes.
		if [ -z "$SKIP_MIGRATIONS" ] && [ -n "$(find ./migrations -iname '*.php' -print -quit 2>/dev/null)" ]; then
			php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
		fi
	fi

	setfacl -R -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null || true
	setfacl -dR -m u:www-data:rwX -m u:"$(whoami)":rwX var 2>/dev/null || true
fi

exec docker-php-entrypoint "$@"
