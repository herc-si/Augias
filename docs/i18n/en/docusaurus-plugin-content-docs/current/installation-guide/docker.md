---
title: Docker
description: Run Augias as a Docker container, optionally alongside a database via Docker Compose.
sidebar_position: 6
---

# Docker

The official Docker image runs the same self-contained build used in the [quick install](./quick-install.mdx). Multi-architecture images are published — Docker pulls the right one for your host automatically.

## System requirements

- [Docker](https://www.docker.com/get-started/) installed on the host.
- [Docker Compose](https://docs.docker.com/compose/) if you want to use the bundled compose example.

## Quick start

```bash
docker run -d -p 8765:8765 -v augias_data:/etc/augias augias/augias
```

The application starts on `http://127.0.0.1:8765`. Continue with the [first-run wizard](./system-installation.md).

:::tip
Change `8765` on the left side of the `-p` flag to expose Augias on a different host port (e.g. `-p 80:8765`).
:::

## Docker Compose

For a complete stack (app + database), use a `docker-compose.yml` like the one shipped with the repository:

```yaml title="docker-compose.yml"
services:
  db:
    image: "postgres:17"
    volumes:
      - db_data:/var/lib/postgresql/data
    restart: always
    environment:
      POSTGRES_DB: augias
      POSTGRES_USER: augias
      POSTGRES_PASSWORD: ${AUGIAS_DB_PASSWORD:?set AUGIAS_DB_PASSWORD in a .env file next to this one}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U augias -d augias"]
      interval: 5s
      timeout: 5s
      retries: 12
  app:
    image: "augias/augias:latest"
    depends_on:
      db:
        condition: service_healthy
    ports:
      - "8765:8765"
    restart: always
    environment:
      AUGIAS_ATTACHMENTS_DIR: /var/augias/attachments
    volumes:
      - app_data:/etc/augias
      - attachments_data:/var/augias/attachments

volumes:
  db_data: {}
  app_data: {}
  attachments_data: {}
```

Choose the database password first, in a `.env` file beside `docker-compose.yml`:

```bash title=".env"
AUGIAS_DB_PASSWORD=a-long-random-password
```

Then bring the stack up:

```bash
docker compose up -d
```

:::info
The stack uses PostgreSQL. MySQL and MariaDB are supported too — the [first-run wizard](./system-installation.md) asks which one you are connecting to — so change the `db` service if you already run one of those.
:::

:::warning
There is no default for `AUGIAS_DB_PASSWORD` on purpose. Without it `docker compose up` stops and tells you to set it, rather than starting a database with no password on it.
:::

Supporting documents attached to accounting entries are kept on their own volume, because the obligation to keep them is counted in years — longer than any container.

## Persisting data

Mount a volume (or bind mount) at `/etc/augias` so application data survives container restarts and image upgrades:

```bash
docker run -d -p 8765:8765 -v augias_data:/etc/augias augias/augias
```

## Image source

Pull from [Docker Hub](https://hub.docker.com/r/augias/augias).

:::info
Recurring tasks and async work (email sending) run automatically inside the container — no separate cron job or messenger consumer to set up.
:::

## Update

```bash
docker pull augias/augias:latest
docker compose up -d   # or `docker stop` + `docker run` again
```
