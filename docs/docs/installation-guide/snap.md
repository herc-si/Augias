---
title: Snap
description: Installer Augias depuis le Snap Store sur Ubuntu ou toute distribution Linux avec snapd.
sidebar_position: 4
---

# Snap

Augias est disponible sur le [Snap Store](https://snapcraft.io/augias). Le snap contient le binaire autonome et l'enregistre comme service d'arrière-plan : ni PHP, ni serveur web, ni tâche cron.

## Configuration requise

- Linux avec [snapd](https://snapcraft.io/docs/installing-snapd). Ubuntu l'inclut depuis la version 16.04.

## Installer

```bash
sudo snap install augias
```

Le service démarre de lui-même après l'installation et écoute sur `http://localhost:8765`. Ouvrez cette adresse dans votre navigateur et terminez avec l'[assistant de premier démarrage](./system-installation.md).

:::info
Le snap sert en HTTP simple. En production, placez Augias derrière un proxy inverse (Nginx, Caddy, Traefik) qui gère le TLS.
:::

## Piloter le service

```bash
sudo snap start augias    # démarrer
sudo snap stop augias     # arrêter
sudo snap restart augias  # redémarrer
snap logs augias          # voir les journaux
snap logs -n 100 augias   # voir les 100 dernières lignes
```

## Ligne de commande

Le snap fournit une application `cli` pour lancer les commandes console :

```bash
augias.cli console cache:clear
augias.cli version
```

## Données

Les données de l'application sont dans `/var/snap/augias/common/`.

## Mettre à jour

```bash
sudo snap refresh augias
```

Les snaps se mettent à jour seuls en arrière-plan par défaut. La commande ci-dessus force une mise à jour immédiate.
