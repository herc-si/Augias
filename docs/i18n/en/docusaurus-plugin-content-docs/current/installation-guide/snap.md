---
title: Snap
description: Install Augias from the Snap Store on Ubuntu or any Linux distribution with snapd.
sidebar_position: 4
---

# Snap

Augias is available on the [Snap Store](https://snapcraft.io/augias). The snap bundles the self-contained binary and registers it as a background service — no PHP, webserver, or cron job required.

## System requirements

- Linux with [snapd](https://snapcraft.io/docs/installing-snapd) installed. Ubuntu 16.04 and later include snapd out of the box.

## Install

```bash
sudo snap install augias
```

The service starts automatically after installation and listens on `http://localhost:8765`. Open that URL in your browser and finish setup with the [first-run wizard](./system-installation.md).

:::info
The snap runs over plain HTTP. For production, place Augias behind a reverse proxy (Nginx, Caddy, Traefik) that terminates TLS.
:::

## Manage the service

```bash
sudo snap start augias    # start
sudo snap stop augias     # stop
sudo snap restart augias  # restart
snap logs augias          # view logs
snap logs -n 100 augias   # view last 100 lines
```

## CLI

The snap exposes a `cli` app for running console commands:

```bash
augias.cli console cache:clear
augias.cli version
```

## Data

Application data is stored in `/var/snap/augias/common/`.

## Update

```bash
sudo snap refresh augias
```

Snaps update automatically in the background by default. Run the above to force an immediate refresh.
