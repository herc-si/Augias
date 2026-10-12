---
title: Helm (Kubernetes)
description: Déployer Augias sur un cluster Kubernetes avec le chart Helm officiel.
sidebar_position: 8
---

# Helm (Kubernetes)

Le chart Helm officiel d'Augias déploie l'application, un worker d'arrière-plan et un planificateur sur n'importe quel cluster Kubernetes. Il peut aussi démarrer MySQL, PostgreSQL et Redis par les sous-charts Bitnami.

## Prérequis

- Kubernetes **1.23+**
- Helm **3.2+**
- Une StorageClass qui accepte les PersistentVolumeClaims `ReadWriteOnce` (nécessaire au coffre de secrets de l'application, dans `/etc/augias`)

## Récupérer le chart

Le chart n'est pas publié dans un dépôt Helm : il est livré dans ce dépôt-ci.

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
helm dependency update helm/augias
```

Toutes les commandes ci-dessous installent depuis ce chemin local.

## Démarrage rapide avec MySQL

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="votre-mot-de-passe-mysql" \
  --set mysql.auth.rootPassword="votre-mot-de-passe-root" \
  --set app.secret="votre-cle-secrete"
```

Augias démarre avec une instance MySQL fournie. Ouvrez l'adresse du pod et terminez avec l'[assistant d'installation](./system-installation.md).

## Démarrage rapide avec PostgreSQL

```bash
helm install augias helm/augias \
  --set postgresql.enabled=true \
  --set postgresql.auth.password="votre-mot-de-passe-pg" \
  --set app.secret="votre-cle-secrete"
```

## Base de données externe

Passez une `DATABASE_URL` complète pour vous passer des sous-charts de base de données :

```bash
helm install augias helm/augias \
  --set externalDatabase.url="mysql://user:password@host:3306/augias" \
  --set app.secret="votre-cle-secrete"
```

## Messages asynchrones avec Redis

Redis sert aux tâches d'arrière-plan asynchrones (envoi des e-mails, traitement des paiements). Avec `redis.enabled=true`, le chart configure seul le transport Messenger :

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="votre-mot-de-passe-mysql" \
  --set redis.enabled=true \
  --set redis.auth.password="votre-mot-de-passe-redis" \
  --set app.secret="votre-cle-secrete"
```

## Installation automatique (sans l'assistant web)

Réglez `install.enabled=true` pour lancer l'installeur comme Job Kubernetes au premier déploiement : l'étape de l'assistant est entièrement sautée.

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="votre-mot-de-passe-mysql" \
  --set app.secret="votre-cle-secrete" \
  --set install.enabled=true \
  --set install.adminEmail="admin@example.com" \
  --set install.adminPassword="votre-mot-de-passe-admin"
```

## Exposer par un Ingress

```bash
helm install augias helm/augias \
  --set mysql.enabled=true \
  --set mysql.auth.password="votre-mot-de-passe-mysql" \
  --set app.secret="votre-cle-secrete" \
  --set ingress.enabled=true \
  --set ingress.hosts[0].host="factures.example.com" \
  --set ingress.tls[0].secretName="augias-tls" \
  --set "ingress.tls[0].hosts[0]=factures.example.com"
```

## Registre OCI (alternative)

Le chart est aussi publié comme artefact OCI sur GitHub Container Registry. Pratique pour une installation OCI ou pour figer une version sans ajouter de dépôt :

```bash
helm install augias oci://ghcr.io/augias/charts/augias --version 3.0.0
```

## Principales valeurs

| Valeur | Défaut | Description |
| --- | --- | --- |
| `app.secret` | *(générée si vide)* | Le secret de l'application. **Conservez-le** : le changer invalide toutes les sessions et tous les jetons d'API. |
| `app.locale` | `en` | La langue par défaut. |
| `app.allowRegistration` | `false` | Ouvrir l'inscription publique. |
| `app.workerMode` | `false` | Activer le mode worker persistant de FrankenPHP. |
| `install.enabled` | `false` | Lancer l'installeur en ligne de commande comme Job (sans l'assistant web). |
| `install.adminEmail` | — | L'e-mail de l'administrateur (avec `install.enabled=true`). |
| `install.adminPassword` | — | Le mot de passe de l'administrateur (avec `install.enabled=true`). |
| `worker.enabled` | `true` | Déployer le worker consommateur de Messenger. |
| `worker.replicaCount` | `1` | Le nombre de pods worker. |
| `scheduler.enabled` | `true` | Déployer le planificateur cron. |
| `persistence.enabled` | `true` | Créer un PVC pour `/etc/augias`. |
| `persistence.size` | `1Gi` | La taille du PVC. |
| `ingress.enabled` | `false` | Créer une ressource Ingress. |

## Mettre à jour

Passez toujours `--reuse-values` (ou redonnez `app.secret`) pour que le secret ne change pas d'une version à l'autre :

```bash
helm upgrade augias helm/augias --reuse-values
```

Les migrations de base de données passent automatiquement, dans un Job lancé avant le démarrage des nouveaux pods.

## Persistance

`/etc/augias` contient le coffre de secrets Symfony de l'application. Le PVC porte l'annotation `helm.sh/resource-policy: keep` : il n'est **pas** supprimé par `helm uninstall`. Sauvegardez-le avant de changer de cluster.

:::warning
Avec plusieurs réplicas (`replicaCount > 1`), le PVC doit utiliser une StorageClass `ReadWriteMany` pour que tous les pods partagent le coffre. Un PVC `ReadWriteOnce` ne fonctionne qu'avec un seul réplica.
:::

## Voir aussi

- [Assistant d'installation](./system-installation.md)
- [Docker](./docker.md)
