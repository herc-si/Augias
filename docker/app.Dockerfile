# syntax=docker/dockerfile:1
#
# Augias as a self-contained image, built from source.
#
# The released image (docker/package.Dockerfile) wraps a single static binary
# with the whole application compiled into it. That is the right artifact for
# someone installing Augias, and the wrong one for a deployment that adds a
# bundle of its own: a static binary takes no plugins. This image exists for
# the latter — it carries a real source tree and a real vendor directory, so a
# derived image can install a package into it.
#
# It is also, unlike docker/dev.Dockerfile, self-contained: the application is
# *in* the image rather than mounted over it at run time, which is what makes
# two images two deployments rather than one deployment twice.
#checkov:skip=CKV_DOCKER_2
#checkov:skip=CKV_DOCKER_3

FROM dunglas/frankenphp:php8.4 AS base

RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    pdo_mysql \
    pdo_pgsql \
    redis \
    soap \
    xsl \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ---------------------------------------------------------------- dependencies
FROM base AS vendor

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
# --no-scripts, because the scripts want an application that is not here yet;
# the source arrives on the next layer so that a dependency change alone does
# not invalidate this one.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# This image is the public one, and must stay it.
#
# A working tree that has a private package installed for development — a path
# repository in composer.json — would otherwise bake it in here, and the whole
# point of two images is that this one does not contain the other's code. The
# check costs nothing and the mistake is silent: an image that works, ships,
# and carries something it should not.
RUN ! composer show --name-only 2>/dev/null | grep -q '^herc-si/' \
    || (echo "This image must not contain a private package. Build it from a clean checkout." >&2 && exit 1)

# --------------------------------------------------------------------- assets
FROM oven/bun:1 AS assets

WORKDIR /app

# Several JS dependencies are `file:vendor/...`, so the front end cannot be
# built before Composer has run. That ordering is not an accident of this
# Dockerfile; it is how the project is wired.
COPY --from=vendor /app /app

RUN bun install --frozen-lockfile && bun run build

# -------------------------------------------------------------------- runtime
FROM base AS app

LABEL org.opencontainers.image.title=Augias
LABEL org.opencontainers.image.description="The open-source invoicing platform for freelancers and small businesses"
LABEL org.opencontainers.image.source=https://github.com/herc-si/Augias
LABEL org.opencontainers.image.licenses=MIT
LABEL org.opencontainers.image.vendor="HERC SI"

ARG AUGIAS_VERSION=''
ENV AUGIAS_VERSION=${AUGIAS_VERSION}
ENV AUGIAS_ENV=prod
ENV AUGIAS_DEBUG=0
ENV AUGIAS_CONFIG_DIR=/etc/augias
ENV AUGIAS_DOCKER=true
ENV SERVER_NAME=:8765
ENV APP_PATH=/app

COPY --from=vendor /app /app
COPY --from=assets /app/public/static /app/public/static

# The cache is warmed on first boot rather than here. Warming needs an
# application secret, and the only secret available at build time is one baked
# into the image — which is worse than a slow first request.
RUN rm -rf var/cache var/log && mkdir -p var/cache var/log && chmod -R 0777 var

EXPOSE 8765

VOLUME ["/etc/augias"]

ENTRYPOINT ["frankenphp"]

CMD ["run", "--config", "/app/Caddyfile"]
