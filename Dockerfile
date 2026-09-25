#syntax=docker/dockerfile:1

# Image FrankenPHP commune au développement et à la production (CLAUDE.md §12).
# Structure dérivée de dunglas/symfony-docker.

ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1
ARG COMPOSER_VERSION=2

FROM composer/composer:${COMPOSER_VERSION}-bin AS composer

# ---------------------------------------------------------------- base
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS frankenphp_base

WORKDIR /app
VOLUME /app/var/

RUN apt-get update && apt-get install -y --no-install-recommends \
		acl \
		curl \
		file \
		gettext \
		git \
		postgresql-client \
	&& rm -rf /var/lib/apt/lists/*

RUN set -eux; \
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		zip \
	;

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD curl -f http://localhost:2019/metrics || exit 1
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# ---------------------------------------------------------------- dev
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev XDEBUG_MODE=off
VOLUME /app/var/

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Xdebug est installé mais désactivé par défaut (XDEBUG_MODE=off),
# activable à la volée : XDEBUG_MODE=debug make up
# GD sert à `make images` (variantes WebP et tailles responsives). Les
# variantes sont versionnées : la production n'en a pas besoin.
RUN set -eux; install-php-extensions xdebug gd

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# ---------------------------------------------------------------- prod
# Cible prévue pour le déploiement VPS. Le compose de production reste à
# compléter par le développeur (CLAUDE.md §12) — ne rien y ajouter sans
# instruction explicite.
FROM frankenphp_base AS frankenphp_prod

ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="import worker.Caddyfile"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/conf.d/
COPY --link frankenphp/worker.Caddyfile /etc/frankenphp/worker.Caddyfile
COPY --from=composer --link /composer /usr/bin/composer

COPY --link composer.* symfony.* ./
RUN set -eux; \
	composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link . ./
RUN rm -Rf frankenphp/

RUN set -eux; \
	mkdir -p var/cache var/log; \
	composer dump-autoload --classmap-authoritative --no-dev; \
	composer dump-env prod; \
	composer run-script --no-dev post-install-cmd; \
	php bin/console asset-map:compile; \
	chmod +x bin/console; sync;
