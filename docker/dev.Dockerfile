# syntax=docker/dockerfile:1
#
# Local development image: runs the FrankenPHP web server (same engine used
# in production) directly against the source tree, mounted as a volume by
# docker-compose.dev.yml. Not used for building release artifacts — see
# docker/linux-static-build.Dockerfile and docker/package.Dockerfile for that.
FROM dunglas/frankenphp:php8.4

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

# The `installation` test group drives a real browser through Panther, and is
# excluded from the default suite precisely because it needs one (see the
# comments in phpunit.xml.dist). GitHub's runners ship Chrome; this image does
# not, so the group could not be run locally at all. Debian's chromium and
# chromium-driver are versioned together, which avoids the browser/driver
# version drift that Google's own packages invite.
RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium chromium-driver \
    && rm -rf /var/lib/apt/lists/*

# Panther finds chromedriver on PATH by itself, but falls back to looking for a
# binary named `google-chrome` for the browser, which Debian's package does not
# provide — so point it at chromium explicitly. The container runs as root, and
# Chromium refuses to start as root with its sandbox on. /dev/shm is 64M in a
# container by default, which Chromium exhausts on any real page.
ENV PANTHER_CHROME_BINARY=/usr/bin/chromium \
    PANTHER_NO_SANDBOX=1 \
    PANTHER_CHROME_ARGUMENTS=--disable-dev-shm-usage

# The base image's default memory_limit (256M) isn't enough to run
# `composer install`'s post-install `cache:clear` step (or `bin/console
# cache:clear` in general) once the project grows past a certain number of
# bundles/services — it OOMs and takes the whole container down on every
# restart. Development only; production builds warm the cache at image build
# time, before traffic, so this doesn't apply there.
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/memory-limit.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
