---
title: Sentry
description: Envoyer les erreurs, les journaux et les mesures de performance d'Augias vers Sentry.
sidebar_position: 1
---

# Sentry

Augias s'intègre à [Sentry](https://sentry.io/) pour surveiller les erreurs, les journaux et les performances de votre installation. L'intégration est fournie : il suffit d'un DSN pour l'activer.

## Ce qui est envoyé

Avec un DSN configuré, Augias envoie à Sentry :

- **Les erreurs** : exceptions non interceptées et toute entrée de journal de niveau `ERROR` ou plus, mises en mémoire par le gestionnaire *fingers crossed* de Monolog pour que chaque erreur parte avec son contexte (jusqu'à 50 entrées précédentes).
- **Les journaux** : les entrées de niveau `INFO` et plus, sauf les canaux `doctrine`, `request`, `security`, `event` et `console` (bruyants et rarement utiles à grande échelle).
- **Les traces de performance** *(sur option)* : requêtes HTTP, commandes console, requêtes SQL Doctrine, rendus Twig, accès au cache Symfony et requêtes HttpClient sortantes.
- **Les profils** *(sur option, avec l'extension PHP `excimer`)* : profils CPU des requêtes tracées.

Les erreurs de statut HTTP `401`, `404` et `405` sont exclues par défaut pour limiter le bruit.

## Mettre en place Sentry

1. Créez un projet dans Sentry et copiez son [DSN](https://docs.sentry.io/product/sentry-basics/dsn-explainer/).
2. Réglez la variable d'environnement `AUGIAS_SENTRY_DSN` de votre instance (voir les instructions par plateforme ci-dessous).
3. Redémarrez l'application pour qu'elle charge le nouvel environnement.

C'est tout ce qu'il faut pour recevoir les erreurs. Le suivi des performances et le profilage sont facultatifs et se règlent à part (voir [Suivi des performances](#suivi-des-performances)).

### Docker

Avec Docker, passez le DSN comme variable d'environnement :

```bash
docker run -e AUGIAS_SENTRY_DSN=https://<key>@o0.ingest.sentry.io/<project-id> augias/augias
```

### Paquet de distribution

Avec le paquet de distribution ou une installation depuis les sources, ajoutez le DSN au fichier `.env` à la racine de l'application. Créez le fichier s'il n'existe pas :

```ini title=".env"
AUGIAS_SENTRY_DSN=https://<key>@o0.ingest.sentry.io/<project-id>
```

## Options de configuration

Tous les réglages Sentry sont des variables d'environnement préfixées par `AUGIAS_SENTRY_`. Elles se règlent comme le DSN.

| Variable | Défaut | Description |
| --- | --- | --- |
| `AUGIAS_SENTRY_DSN` | *(vide)* | Le DSN de votre projet Sentry. Vide, l'intégration est désactivée. |
| `AUGIAS_SENTRY_RELEASE` | Version de l'application | Le nom de version attaché aux événements. Utile pour repérer une régression d'une mise à jour à l'autre. |
| `AUGIAS_SENTRY_SEND_DEFAULT_PII` | `0` | À `1`, joint aux événements l'utilisateur, l'adresse IP et les cookies de la requête. Laissez `0` tant que vous n'avez pas examiné les conséquences pour la vie privée de vos utilisateurs. |
| `AUGIAS_SENTRY_TRACES_SAMPLE_RATE` | `0` | La part des requêtes tracées, entre `0.0` et `1.0`. `0` désactive les traces. |
| `AUGIAS_SENTRY_PROFILES_SAMPLE_RATE` | `0` | La part des requêtes *tracées* également profilées. Demande l'extension PHP `excimer`. |
| `AUGIAS_SENTRY_HTTP_TIMEOUT` | `2` | Délai de lecture HTTP (en secondes) pour l'envoi des événements. |
| `AUGIAS_SENTRY_HTTP_CONNECT_TIMEOUT` | `2` | Délai de connexion HTTP (en secondes) pour l'envoi des événements. |

:::info
`AUGIAS_SENTRY_SEND_DEFAULT_PII` accepte `1`/`0` ou `true`/`false`. Les taux d'échantillonnage attendent un nombre décimal entre `0` et `1` (par exemple `0.1` pour 10 %).
:::

## Suivi des performances

Les traces sont prêtes mais désactivées par défaut. Pour les activer, donnez un taux supérieur à `0` :

```ini title=".env"
AUGIAS_SENTRY_TRACES_SAMPLE_RATE=0.1
```

Points de départ conseillés :

- **Peu de trafic, auto-hébergement pour une seule entreprise** : `1.0` (tout capturer).
- **Trafic moyen** : `0.1` (10 % des requêtes).
- **Fort trafic** : `0.01` (1 %).

Une fois activées, les traces détaillent chaque requête Doctrine, rendu Twig, accès au cache et appel HttpClient sortant, ainsi que les commandes console. Les processus de longue durée (`messenger:consume`, `schedule:run`, `cron:run`) sont exclus : ils produiraient sinon une seule trace couvrant toute leur vie.

:::tip
Commencez en production avec un taux bas et augmentez-le seulement si vous manquez de données. Sentry facture au volume d'événements, et les traces en produisent bien plus que le suivi des erreurs.
:::

### Profilage

Le profilage capture des échantillons CPU des requêtes tracées et demande l'[extension PHP `excimer`](https://github.com/wikimedia/php-excimer). Pour l'activer :

```ini title=".env"
AUGIAS_SENTRY_TRACES_SAMPLE_RATE=0.1
AUGIAS_SENTRY_PROFILES_SAMPLE_RATE=1.0
```

Le taux de profilage est *relatif* au taux de traces. Avec les valeurs ci-dessus, 10 % des requêtes sont tracées et toutes ces traces sont profilées : 10 % des requêtes sont donc profilées.

:::warning
Si `excimer` n'est pas installée, laissez `AUGIAS_SENTRY_PROFILES_SAMPLE_RATE` à `0`. Le binaire statique d'Augias inclut `excimer` ; si vous avez compilé PHP vous-même, installez-la par PECL.
:::

## Passer par un relais Sentry

[Sentry Relay](https://docs.sentry.io/product/relay/) est un petit proxy qui garde les événements localement et les transmet à Sentry en différé. Il est utile pour une latence prévisible, pour nettoyer les données sensibles avant qu'elles ne quittent votre réseau, ou quand Augias tourne dans un environnement aux sorties restreintes.

Pour passer par le relais, faites pointer le DSN vers lui et gardez les délais courts par défaut :

```ini title=".env"
AUGIAS_SENTRY_DSN=http://<key>@localhost:3000/<project-id>
AUGIAS_SENTRY_HTTP_TIMEOUT=2
AUGIAS_SENTRY_HTTP_CONNECT_TIMEOUT=2
```

En envoi direct vers `sentry.io` (sans relais), envisagez de porter les deux délais à `5` à `10` secondes pour absorber une latence occasionnelle.

## Nommer les versions

Par défaut, les événements portent la version d'Augias. Si vous déployez depuis les sources ou une version modifiée, réglez `AUGIAS_SENTRY_RELEASE` sur une valeur propre au déploiement, en général un SHA Git ou une étiquette de version :

```ini title=".env"
AUGIAS_SENTRY_RELEASE=4.1.0
```

Vous repérez ainsi dans Sentry les régressions apportées par une version précise.

## Vérifier l'intégration

Pour vérifier que les événements arrivent dans Sentry, envoyez un événement de test en ligne de commande :

```bash
bin/console sentry:test
```

La commande envoie un événement factice avec le DSN configuré. Il doit apparaître dans la vue *Issues* de Sentry en quelques secondes.

## Désactiver Sentry

Videz `AUGIAS_SENTRY_DSN` (ou retirez-la du `.env`) et redémarrez l'application. Sans DSN, rien n'est envoyé et l'intégration ne coûte quasiment rien.
