---
title: Homebrew
description: Installer Augias depuis le dépôt Homebrew de SolidWorx sur macOS ou Linux.
sidebar_position: 3
---

# Homebrew

Le dépôt Homebrew installe la même version autonome que l'[installation rapide](./quick-install.mdx), dans un emplacement géré, et la tient à jour avec `brew upgrade`.

## Configuration requise

- macOS ou Linux avec [Homebrew](https://brew.sh/).
- Une base de données : SQLite fonctionne d'emblée ; MySQL, MariaDB et PostgreSQL sont aussi pris en charge.

Ni PHP, ni serveur web, ni tâche cron ne sont nécessaires.

## Installer

```bash
brew install solidworx/tap/augias
```

## Lancer

```bash
augias run
```

L'application démarre sur `https://localhost:8765` avec un certificat auto-signé. Ouvrez l'adresse dans votre navigateur et terminez avec l'[assistant de premier démarrage](./system-installation.md).

Pour le SSL, un domaine personnalisé, le mode worker et toutes les options de `run`, voir l'[installation rapide](./quick-install.mdx#ssl).

:::info
Les tâches récurrentes et le travail en arrière-plan (envoi des e-mails) tournent seuls : pas de tâche cron ni de consommateur de messages à mettre en place.
:::

## Mettre à jour

```bash
brew upgrade solidworx/tap/augias
```
