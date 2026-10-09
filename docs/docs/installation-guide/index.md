---
title: Guide d'installation
description: Choisir comment installer Augias : installation rapide, Homebrew, Docker, paquet de distribution ou depuis les sources.
sidebar_position: 1
---

# Guide d'installation

Augias tourne partout où l'on peut lancer un binaire, un conteneur Docker ou PHP. Choisissez la méthode qui convient à votre environnement : chaque parcours ci-dessous se suffit à lui-même, ne suivez que les étapes qui le concernent.

:::tip[Vous ne voulez pas l'héberger vous-même ?]
Ce guide couvre l'auto-hébergement : vous faites tourner Augias sur votre propre infrastructure et gardez vos propres sauvegardes.
:::

## Choisir une méthode

| Pour… | Utilisez |
| --- | --- |
| Démarrer en moins d'une minute, sans PHP ni serveur web à installer | [Installation rapide](./quick-install.mdx) **(conseillée)** |
| Installer par un gestionnaire de paquets sur macOS ou Linux | [Homebrew](./homebrew.md) |
| Installer un snap sur Ubuntu ou tout Linux avec snapd | [Snap](./snap.md) |
| Installer nativement sur Debian, Ubuntu, RHEL, Fedora ou Alpine | [Paquets Linux](./linux-packages.mdx) |
| Faire tourner Augias en conteneur à côté de vos autres services | [Docker](./docker.md) |
| Déployer sur un cluster Kubernetes | [Helm](./helm.md) |
| Déployer sur un hébergement mutualisé, un serveur web existant, ou garder la main sur toute la pile | [Paquet de distribution](./distribution-package/index.mdx) |
| Travailler sur le code ou contribuer | [Git (avancé)](./git.md) |

Après l'installation, terminez avec l'[assistant de premier démarrage](./system-installation.md).
