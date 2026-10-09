---
title: Tâches planifiées
description: Planifier le processus de fond d'Augias sur votre plateforme : systemd, cron, Plesk, cPanel ou Windows.
sidebar_position: 2
---

import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Tâches planifiées

Augias utilise un seul processus de fond pour les messages asynchrones (e-mails, webhooks) et les tâches planifiées (factures récurrentes, relances, passage en retard). La commande est la même partout :

```bash
bin/console messenger:consume --all --time-limit=3600 --memory-limit=128M
```

Les configurations ci-dessous sont autant de façons de garder cette commande en marche. Choisissez celle qui convient à votre environnement.

:::warning
Sans ce processus, ni les fonctions asynchrones ni les tâches planifiées ne s'exécutent : les e-mails ne partent pas et les factures récurrentes ne sont pas générées.
:::

:::info
Ce processus n'est à mettre en place qu'avec le [paquet de distribution](./index.mdx) ou une installation depuis [Git](../git.md). L'[installation rapide](../quick-install.mdx), [Homebrew](../homebrew.md) et [Docker](../docker.md) le lancent automatiquement.
:::

## Choisir une plateforme

<Tabs groupId="cron-platform">
  <TabItem value="systemd" label="systemd" default>

  Un service systemd de longue durée est la solution la plus fiable sur un serveur Linux.

  Créez un fichier d'unité `/etc/systemd/system/augias-worker.service` :

  ```ini title="/etc/systemd/system/augias-worker.service"
  [Unit]
  Description=Augias worker
  After=network.target

  [Service]
  Type=simple
  User=www-data
  WorkingDirectory=/opt/augias
  ExecStart=/usr/bin/php bin/console messenger:consume --all --time-limit=3600 --memory-limit=128M
  Restart=on-failure
  RestartSec=5

  [Install]
  WantedBy=multi-user.target
  ```

  Rechargez, activez et démarrez-le :

  ```bash
  sudo systemctl daemon-reload
  sudo systemctl enable --now augias-worker.service
  ```

  Pour plus de débit, lancez plusieurs copies avec un modèle systemd (`augias-worker@.service`) et démarrez `augias-worker@1`, `augias-worker@2`, etc.

  </TabItem>

  <TabItem value="supervisord" label="Supervisord">

  Utilisez [Supervisord](http://supervisord.org/) sur un système sans systemd (ou si vous gérez déjà d'autres services avec lui).

  Créez un fichier de programme `/etc/supervisor/conf.d/augias-worker.conf` :

  ```ini title="/etc/supervisor/conf.d/augias-worker.conf"
  [program:augias-worker]
  command=/usr/bin/php /opt/augias/bin/console messenger:consume --all --time-limit=3600 --memory-limit=128M
  user=www-data
  numprocs=1
  process_name=%(program_name)s_%(process_num)02d
  autostart=true
  autorestart=true
  startsecs=5
  startretries=10
  stopasgroup=true
  killasgroup=true
  stopwaitsecs=30
  stdout_logfile=/var/log/supervisor/augias-worker.log
  stderr_logfile=/var/log/supervisor/augias-worker.err.log
  ```

  Rechargez Supervisord et démarrez le processus :

  ```bash
  sudo supervisorctl reread
  sudo supervisorctl update
  sudo supervisorctl start augias-worker:*
  ```

  Augmentez `numprocs` pour faire tourner plusieurs processus en parallèle : Supervisord ajoute lui-même le numéro de processus à `process_name`.

  :::tip
  `stopwaitsecs=30` laisse au processus le temps de finir le message en cours avant d'être arrêté. Gardez une valeur supérieure au message le plus lent que vous attendez.
  :::

  </TabItem>

  <TabItem value="cron" label="Cron Linux">

  Utilisez cron quand un service de longue durée est impossible (par exemple sur un hébergement mutualisé qui bloque les démons). L'option `--time-limit=55` fait s'arrêter le processus avant le passage suivant de cron :

  ```bash title="crontab -e"
  * * * * * /usr/bin/php /opt/augias/bin/console messenger:consume --all --limit=10 --time-limit=55 --memory-limit=128M
  ```

  :::note
  Cette méthode ajoute jusqu'à 60 secondes de délai avant le traitement des messages asynchrones et des tâches planifiées. C'est acceptable pour la plupart des installations, mais préférez **systemd** si vous l'avez.
  :::

  Remplacez `/opt/augias` par le chemin réel de votre installation.

  </TabItem>

  <TabItem value="cpanel" label="cPanel">

  1. Connectez-vous à cPanel.
  2. Ouvrez **Avancé → Tâches cron**.
  3. Ajoutez une tâche :
     - **Paramètres courants :** `Une fois par minute (* * * * *)`
     - **Commande :**

       ```bash
       /usr/bin/php /home/votreutilisateur/chemin/vers/augias/bin/console messenger:consume --all --limit=10 --time-limit=55 --memory-limit=128M
       ```

  4. Enregistrez.

  Remplacez `/home/votreutilisateur/chemin/vers/augias` par le chemin réel de votre installation.

  </TabItem>

  <TabItem value="plesk" label="Plesk">

  1. Connectez-vous au panneau Plesk.
  2. Ouvrez **Outils & paramètres → Tâches planifiées** (ou **Tâches planifiées** sous votre domaine).
  3. Cliquez sur **Ajouter une tâche** et réglez :
     - **Type de tâche :** Exécuter une commande
     - **Exécuter :** `Style cron : * * * * *`
     - **Commande :**

       ```bash
       /usr/bin/php /chemin/vers/augias/bin/console messenger:consume --all --limit=10 --time-limit=55 --memory-limit=128M
       ```

  4. Enregistrez la tâche.

  </TabItem>

  <TabItem value="windows" label="Windows">

  Utilisez le Planificateur de tâches pour lancer le processus chaque minute.

  1. Ouvrez le **Planificateur de tâches** et choisissez **Créer une tâche**.
  2. **Général** : nommez la tâche `Augias worker`.
  3. **Déclencheurs** : ajoutez un déclencheur :
     - Lancer la tâche : **À l'heure programmée**
     - **Quotidien**, tous les `1` jours
     - **Répéter la tâche toutes les :** `1 minute` pendant `Indéfiniment`
  4. **Actions** : ajoutez une action :
     - **Action :** Démarrer un programme
     - **Programme/script :** `php.exe`
     - **Ajouter des arguments :**

       ```text
       C:\chemin\vers\augias\bin\console messenger:consume --all --limit=10 --time-limit=55 --memory-limit=128M
       ```

  5. Enregistrez la tâche.

  Remplacez `C:\chemin\vers\augias` par le chemin réel de votre installation.

  </TabItem>
</Tabs>

## Vérifier que le processus tourne

Suivez le journal de l'application ou consultez la file des messages :

```bash
bin/console messenger:stats
```

Une installation saine garde des files courtes : les messages sont traités en quelques secondes (systemd) ou en une minute au plus (cron).
