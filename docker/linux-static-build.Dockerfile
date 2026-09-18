# syntax=docker/dockerfile:1
#checkov:skip=CKV_DOCKER_2
#checkov:skip=CKV_DOCKER_3
#checkov:skip=CKV_DOCKER_7
FROM golang-base AS builder

ARG TARGETARCH
ARG TARGETOS

ARG AUGIAS_VERSION=''
ENV AUGIAS_VERSION=${AUGIAS_VERSION}

ARG PHP_VERSION=''
ENV PHP_VERSION=${PHP_VERSION}

# args passed to static-php-cli (mirrors upstream FrankenPHP)
ARG PHP_EXTENSIONS=''
ARG PHP_EXTENSION_LIBS=''
ARG SPC_OPT_BUILD_ARGS

ARG CLEAN=''
ARG EMBED=''
ARG DEBUG_SYMBOLS=''
ARG MIMALLOC=''
ARG NO_COMPRESS=''

ARG RELEASE='0'
ENV RELEASE=${RELEASE}

ENV GOTOOLCHAIN=local

SHELL ["/bin/ash", "-eo", "pipefail", "-c"]

ARG CI
ENV CI=${CI}

RUN apk update; \
	apk add --no-cache \
		alpine-sdk \
		autoconf \
		automake \
		bash \
		binutils \
		bison \
		build-base \
		cmake \
		curl \
		file \
		flex \
		g++ \
		gcc \
		gettext-libs \
		git \
		github-cli \
		jq \
		libgcc \
		libstdc++ \
		libtool \
		linux-headers \
		llvm19 \
		m4 \
		make \
		pkgconfig \
		php84 \
		php84-common \
		php84-ctype \
		php84-curl \
		php84-dom \
		php84-iconv \
		php84-mbstring \
		php84-openssl \
		php84-pcntl \
		php84-phar \
		php84-posix \
		php84-session \
		php84-sodium \
		php84-tokenizer \
		php84-xml \
		php84-xmlwriter \
		upx \
		wget \
		xz ; \
	ln -sf /usr/bin/php84 /usr/bin/php

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /go/src/app

ENV SPC_DEFAULT_C_FLAGS='-fPIE -fPIC -O3'
ENV SPC_LIBC='musl'
ENV SPC_CMD_VAR_PHP_MAKE_EXTRA_LDFLAGS_PROGRAM='-Wl,-O3 -pie'
ENV SPC_REL_TYPE='binary'
# Explicitly set download args to prevent build-static.sh from auto-adding --prefer-pre-built
# on musl Linux, which causes issues with pre-built packages missing .pc files
#ENV SPC_OPT_DOWNLOAD_ARGS='--ignore-cache-sources=php-src --retry 5'

COPY --link ./build/dist ./build/dist
COPY --link ./scripts ./scripts
COPY --link ./frankenphp ./frankenphp
COPY --link ./composer.json ./composer.json
COPY --link ./composer.lock ./composer.lock

WORKDIR /go/src/app/frankenphp
RUN go mod download

WORKDIR /go/src/app

RUN \
    --mount=type=secret,id=github-token \
    --mount=type=cache,id=php-buildroot-${TARGETARCH},target=/go/src/app/frankenphp/dist/static-php-cli/buildroot \
    --mount=type=cache,id=php-pkgroot-${TARGETARCH},target=/go/src/app/frankenphp/dist/static-php-cli/pkgroot \
    --mount=type=cache,id=php-downloads-${TARGETARCH},target=/go/src/app/frankenphp/dist/static-php-cli/downloads \
    --mount=type=cache,id=php-source-${TARGETARCH},target=/go/src/app/frankenphp/dist/static-php-cli/source \
    CI="" GITHUB_TOKEN=$(cat /run/secrets/github-token) ./scripts/build_binary.sh $AUGIAS_VERSION

FROM alpine

ARG TARGETARCH
ARG TARGETOS

LABEL org.opencontainers.image.title=Augias
LABEL org.opencontainers.image.description="The open-source invoicing platform for freelancers and small businesses"
LABEL org.opencontainers.image.url=https://augias.herc-si.fr
LABEL org.opencontainers.image.source=https://github.com/herc-si/Augias
LABEL org.opencontainers.image.licenses=MIT
LABEL org.opencontainers.image.vendor="HERC SI"

ARG AUGIAS_VERSION=''
ENV AUGIAS_VERSION=${AUGIAS_VERSION}

ENV AUGIAS_ENV=prod
ENV AUGIAS_DEBUG=0
ENV AUGIAS_CONFIG_DIR=/etc/augias
ENV AUGIAS_DOCKER=true

EXPOSE 8765

VOLUME ["/etc/augias"]

COPY --from=builder /go/src/app/frankenphp/dist/augias-${TARGETOS}-${TARGETARCH} /usr/local/bin/augias

ENTRYPOINT ["/usr/local/bin/augias"]

CMD ["run", "--disable-https"]
