---
title: Métriques Prometheus
description: Exposer les métriques HTTP de Caddy et celles des workers FrankenPHP pour Prometheus.
sidebar_position: 4
---

# Métriques Prometheus

Avec le [paquet de distribution](../installation-guide/distribution-package/index.mdx) d'Augias (le binaire unique), vous pouvez exposer un point de collecte compatible Prometheus, qui publie les métriques HTTP de Caddy et les statistiques des workers et des threads FrankenPHP.

:::info
Les métriques Prometheus ne sont disponibles qu'avec le paquet de distribution (le binaire `augias`). Les déploiements Docker et Helm n'exposent pas ce point de collecte par défaut.
:::

## Activer les métriques

Passez `--enable-metrics` à la commande `run` :

```bash
augias run --enable-metrics
```

Le point de collecte écoute par défaut sur le port **9090**. Augias confirme l'adresse au démarrage :

```text
Metrics: Prometheus metrics available at http://localhost:9090/metrics
```

## Changer de port

Utilisez `--metrics-port` pour écouter sur un autre port :

```bash
augias run --enable-metrics --metrics-port 9100
```

## Configurer la collecte Prometheus

Ajoutez une tâche de collecte à votre `prometheus.yml` :

```yaml
scrape_configs:
  - job_name: augias
    static_configs:
      - targets:
          - localhost:9090
```

Remplacez `localhost` par l'hôte ou l'adresse IP d'Augias, et changez le port si vous avez utilisé `--metrics-port`.

## Ce qui est publié

Le point `/metrics` publie les métriques habituelles du serveur HTTP Caddy (nombre de requêtes, tailles de réponse, latences par code de statut et par route) et les statistiques des workers et du pool de threads FrankenPHP (workers actifs, threads inactifs, temps d'exécution PHP).

## Voir aussi

- [Installation du paquet de distribution](../installation-guide/distribution-package/index.mdx)
- [Intégration Sentry](./sentry.md)
