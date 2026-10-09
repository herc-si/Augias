---
title: Git (avancé)
description: Cloner les sources d'Augias pour contribuer ou travailler sur le code.
sidebar_position: 9
---

# Git (avancé)

:::warning
L'installation depuis Git s'adresse aux contributeurs et aux développeurs qui veulent travailler sur le code d'Augias. **Elle n'est pas conseillée en production** : utilisez plutôt l'[installation rapide](./quick-install.mdx), [Homebrew](./homebrew.md) ou [Docker](./docker.md).
:::

## Configuration requise

- PHP 8.4 ou plus, avec les extensions `curl`, `gd`, `intl`, `json`, `openssl`, `pdo`, `soap` et `xsl`.
- [Composer](https://getcomposer.org/).
- [Bun](https://bun.sh/).
- [Symfony CLI](https://symfony.com/download), conseillée pour le serveur web local (voir ci-dessous). Facultative si vous avez votre propre serveur web.
- Une base de données prise en charge (MySQL, MariaDB, PostgreSQL ou SQLite).

## Cloner et installer

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
composer install
bun install
bun run build
```

## Lancer le serveur web local

Pour le développement, le plus simple est la [Symfony CLI](https://symfony.com/doc/current/setup/symfony_cli.html#running-the-local-web-server) : elle fournit un serveur web local en HTTPS, l'intégration Docker et un gestionnaire de workers.

```bash
symfony serve
```

La commande lit le `.symfony.local.yaml` du projet et :

- démarre un serveur web HTTPS sur le port `7005` (réglable dans `.symfony.local.yaml`) ;
- démarre le consommateur de messages asynchrones comme worker géré : les tâches planifiées et les messages asynchrones (e-mails) sont traités sans lancer `messenger:consume` à part.

Le `.symfony.local.yaml` du dépôt contient déjà la configuration HTTP et celle du worker :

```yaml title=".symfony.local.yaml"
http:
    document_root: public/
    passthru: index.php
    port: 7005
    preferred_port: 7005
    allow_http: true
    daemon: true

workers:
    messenger_consume:
        cmd: ['symfony', 'console', 'messenger:consume', 'async', '--time-limit=3600', '--memory-limit=128M']
        watch: ['config', 'src']
```

Les chemins `watch` redémarrent le worker à chaque modification du code : il prend vos changements en compte tout seul.

Pour suivre les journaux du worker avec ceux du serveur web :

```bash
symfony server:log
```

## Votre propre serveur web

Pour utiliser directement Nginx ou Apache, faites pointer la racine des documents sur `public/` (exemples de configuration dans le [guide du paquet de distribution](./distribution-package/index.mdx#2-configurer-le-serveur-web)) et mettez en place le [processus de fond](./distribution-package/cron-job-setup.md) de la même façon.

## Terminer l'installation

Ouvrez l'adresse affichée par la Symfony CLI (en général `https://127.0.0.1:7005`) et terminez avec l'[assistant de premier démarrage](./system-installation.md).

Pour le flux de développement, les conventions de code et le lancement des tests, lisez [`CONTRIBUTING.md`](https://github.com/herc-si/Augias/blob/4.0.x/CONTRIBUTING.md) dans le dépôt.

En cas de problème, [signalez-le](https://github.com/herc-si/Augias/issues).
