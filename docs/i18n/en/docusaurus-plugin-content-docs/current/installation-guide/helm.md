---
title: Helm (Kubernetes)
description: Deploy Augias to a Kubernetes cluster using the official Helm chart.
sidebar_position: 8
---

# Helm (Kubernetes)

The official Augias Helm chart deploys the application, a background worker, and a scheduler to any Kubernetes cluster. It can also bring up MySQL, PostgreSQL, and Redis via Bitnami subcharts.

## Prerequisites

- Kubernetes **1.23+**
- Helm **3.2+**
- A StorageClass that supports `ReadWriteOnce` PersistentVolumeClaims (required for the application's secrets vault at `/etc/augias`)

## Get the chart

The chart is not published to a Helm repository; it ships inside this one.

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
helm dependency update helm/augias
```

All commands below install from that local path.

## Quick start with MySQL

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="your-mysql-password" \
  --set mysql.auth.rootPassword="your-root-password" \
  --set app.secret="your-secret-key"
```

This brings up Augias with a bundled MySQL instance. Browse to the pod's URL and complete the [installation wizard](./system-installation.md).

## Quick start with PostgreSQL

```bash
helm install augias helm/augias \
  --set postgresql.enabled=true \
  --set postgresql.auth.password="your-pg-password" \
  --set app.secret="your-secret-key"
```

## External database

Pass a full `DATABASE_URL` to skip the bundled database subcharts:

```bash
helm install augias helm/augias \
  --set externalDatabase.url="mysql://user:password@host:3306/augias" \
  --set app.secret="your-secret-key"
```

## Enable async messaging with Redis

Redis is required for asynchronous background jobs (sending emails, processing payments). When `redis.enabled=true` the chart configures the Messenger transport automatically:

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="your-mysql-password" \
  --set redis.enabled=true \
  --set redis.auth.password="your-redis-password" \
  --set app.secret="your-secret-key"
```

## Automated install (skip the web wizard)

Set `install.enabled=true` to run the installer as a Kubernetes Job during the first deploy, so the wizard step is skipped entirely:

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="your-mysql-password" \
  --set app.secret="your-secret-key" \
  --set install.enabled=true \
  --set install.adminEmail="admin@example.com" \
  --set install.adminPassword="your-admin-password"
```

## Expose via Ingress

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="your-mysql-password" \
  --set app.secret="your-secret-key" \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host="invoices.example.com" \
  --set ingress.tls[0].secretName="augias-tls" \
  --set "ingress.tls[0].hosts[0]=invoices.example.com"
```

## OCI registry (alternative)

The chart is also published to GitHub Container Registry as an OCI artifact. Use this if you prefer OCI-native installs or want to pin to an exact version without adding a repo:

```bash
helm install augias oci://ghcr.io/augias/charts/augias --version 3.0.0
```

## Key values reference

| Value | Default | Description |
| --- | --- | --- |
| `app.secret` | *(auto-generated if empty)* | Application secret — **save this**; changing it invalidates all sessions and API tokens |
| `app.locale` | `en` | Default locale |
| `app.allowRegistration` | `false` | Allow public self-registration |
| `app.workerMode` | `false` | Enable FrankenPHP persistent worker mode |
| `install.enabled` | `false` | Run the CLI installer as a Job (skips web wizard) |
| `install.adminEmail` | — | Admin user email (used when `install.enabled=true`) |
| `install.adminPassword` | — | Admin user password (used when `install.enabled=true`) |
| `worker.enabled` | `true` | Deploy the Messenger consumer worker |
| `worker.replicaCount` | `1` | Number of worker pods |
| `scheduler.enabled` | `true` | Deploy the cron scheduler |
| `persistence.enabled` | `true` | Create a PVC for `/etc/augias` |
| `persistence.size` | `1Gi` | PVC size |
| `ingress.enabled` | `false` | Create an Ingress resource |

## Upgrading

Always pass `--reuse-values` (or re-specify `app.secret`) so the secret doesn't change between releases:

```bash
helm repo update augias
helm upgrade augias helm/augias --reuse-values
```

Database migrations run automatically as a pre-upgrade Job before the new pods start.

## Persistence

`/etc/augias` stores the application's Symfony secrets vault. The PVC is annotated with `helm.sh/resource-policy: keep` so it is **not** deleted when you run `helm uninstall`. Back it up before migrating clusters.

:::warning
For multi-replica deployments (`replicaCount > 1`), the PVC must use a `ReadWriteMany` StorageClass so all pods can share the vault. A `ReadWriteOnce` PVC only works with a single replica.
:::

## Related

- [System installation wizard](./system-installation.md)
- [Docker](./docker.md)
