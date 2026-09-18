# syntax=docker/dockerfile:1
#checkov:skip=CKV_DOCKER_2
#checkov:skip=CKV_DOCKER_3
FROM alpine

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

COPY augias /usr/local/bin/augias

ENTRYPOINT ["/usr/local/bin/augias"]

CMD ["run", "--disable-https"]
