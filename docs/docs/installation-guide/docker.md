---
title: Docker
description: Faire tourner Augias en conteneur Docker, éventuellement avec une base de données par Docker Compose.
sidebar_position: 6
---

# Docker

L'image Docker officielle contient la même version autonome que l'[installation rapide](./quick-install.mdx). Les images sont publiées pour plusieurs architectures : Docker récupère la bonne pour votre machine.

## Configuration requise

- [Docker](https://www.docker.com/get-started/) installé sur la machine.
- [Docker Compose](https://docs.docker.com/compose/) pour utiliser l'exemple de composition ci-dessous.

## Démarrage rapide

```bash
docker run -d -p 8765:8765 -v augias_data:/etc/augias augias/augias
```

L'application démarre sur `http://127.0.0.1:8765`. Poursuivez avec l'[assistant de premier démarrage](./system-installation.md).

:::tip
Changez le `8765` de gauche dans l'option `-p` pour exposer Augias sur un autre port de la machine (par exemple `-p 80:8765`).
:::

## Docker Compose

Pour une pile complète (application et base de données), utilisez un `docker-compose.yml` comme celui fourni dans le dépôt :

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

Choisissez d'abord le mot de passe de la base, dans un fichier `.env` à côté de `docker-compose.yml` :

```bash title=".env"
AUGIAS_DB_PASSWORD=un-long-mot-de-passe-aleatoire
```

Puis lancez la pile :

```bash
docker compose up -d
```

:::info
La pile utilise PostgreSQL. MySQL et MariaDB sont aussi pris en charge (l'[assistant de premier démarrage](./system-installation.md) demande lequel vous utilisez) : changez le service `db` si vous en avez déjà un.
:::

:::warning
`AUGIAS_DB_PASSWORD` n'a volontairement pas de valeur par défaut. Sans elle, `docker compose up` s'arrête et vous demande de la régler, plutôt que de démarrer une base sans mot de passe.
:::

Les justificatifs joints aux écritures comptables ont leur propre volume : leur obligation de conservation se compte en années, plus longtemps que la vie d'un conteneur.

## Conserver les données

Montez un volume (ou un dossier) sur `/etc/augias` pour que les données survivent aux redémarrages et aux mises à jour de l'image :

```bash
docker run -d -p 8765:8765 -v augias_data:/etc/augias augias/augias
```

## Source de l'image

L'image se récupère sur [Docker Hub](https://hub.docker.com/r/augias/augias).

:::info
Les tâches récurrentes et le travail en arrière-plan (envoi des e-mails) tournent seuls dans le conteneur : pas de tâche cron ni de consommateur de messages à mettre en place.
:::

## Mettre à jour

```bash
docker pull augias/augias:latest
docker compose up -d   # ou `docker stop` puis `docker run` de nouveau
```
