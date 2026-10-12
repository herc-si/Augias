---
title: Meilisearch
description: Alimenter la recherche d'Augias avec une instance Meilisearch.
sidebar_position: 2
---

# Meilisearch

Augias s'appuie sur [Meilisearch](https://www.meilisearch.com/) pour la barre de recherche du haut de page. Avec une instance Meilisearch configurée, une seule zone de saisie cherche dans les clients, contacts, factures, factures récurrentes, devis et paiements, en tolérant les fautes de frappe et avec des filtres par type.

L'intégration est entièrement facultative : sans elle, Augias fonctionne normalement et la barre de recherche est masquée.

## Fonctionnement

- Six index sont tenus, un par type de fiche : `clients`, `contacts`, `invoices`, `recurring_invoices`, `quotes`, `payments`.
- Les fiches sont rangées par entreprise. Chaque fiche est indexée avec un `companyId`, et les recherches sont filtrées sur l'entreprise active : chacun ne voit que ses propres données.
- Les créations, modifications et suppressions sont indexées en temps réel par des écouteurs Doctrine : aucune réindexation programmée en fonctionnement normal.
- La barre de recherche n'apparaît que lorsque l'adresse de Meilisearch et la clé d'API sont toutes deux configurées.

## Prérequis

Il vous faut un serveur Meilisearch (v1.x) en marche. Les choix courants :

- **Auto-hébergé** : par [le binaire officiel, l'image Docker ou un gestionnaire de paquets](https://www.meilisearch.com/docs/learn/getting_started/installation).
- **Meilisearch Cloud** : un hébergement géré, qui fournit d'emblée une adresse et une clé d'API.

Le serveur doit être joignable en HTTP depuis l'application Augias. En auto-hébergement, c'est en général une adresse de réseau privé ou `http://localhost:7700`.

:::warning
En production, donnez toujours une clé maîtresse à votre serveur Meilisearch (`MEILI_MASTER_KEY` côté Meilisearch). Sans clé maîtresse, n'importe qui pouvant joindre le port HTTP peut y écrire.
:::

## Configuration

L'intégration se règle par trois variables d'environnement, comme toute variable d'Augias (option `-e` de Docker, ou fichier `.env` à la racine de l'application pour le paquet de distribution).

| Variable | Défaut | Description |
| --- | --- | --- |
| `AUGIAS_MEILISEARCH_URL` | *(vide)* | L'adresse de votre instance Meilisearch, par exemple `http://meilisearch:7700`. Vide, l'intégration est désactivée. |
| `AUGIAS_MEILISEARCH_API_KEY` | *(vide)* | Une clé d'API avec lecture et écriture sur les index. Utilisez la clé maîtresse pour la mise en place, puis une clé restreinte une fois les index créés (voir [Sécurité](#sécurité)). |
| `AUGIAS_MEILISEARCH_PREFIX` | `augias_<env>_` | Le préfixe ajouté à chaque nom d'index. Par défaut, il sépare les index `dev`, `test` et `prod` qui partagent un même serveur. |

Une configuration de production typique :

```ini title=".env"
AUGIAS_MEILISEARCH_URL=http://meilisearch.internal:7700
AUGIAS_MEILISEARCH_API_KEY=votre-cle-meilisearch
AUGIAS_MEILISEARCH_PREFIX=augias_prod_
```

Redémarrez l'application après chaque changement.

:::info
La barre de recherche ne s'affiche que si `AUGIAS_MEILISEARCH_URL` et `AUGIAS_MEILISEARCH_API_KEY` sont toutes deux non vides. Si les variables sont réglées mais que la barre n'apparaît toujours pas, videz le cache de l'application : `bin/console cache:clear`.
:::

## Indexation initiale

Après la première configuration, et chaque fois que vous importez des données hors de l'application (par exemple depuis une sauvegarde de base ou une migration depuis un autre outil), remplissez les index à la main.

Créez les index avec leurs réglages :

```bash
bin/console meilisearch:create
```

Puis importez les données existantes :

```bash
bin/console meilisearch:import
```

La commande parcourt chaque fiche de la base et l'envoie à Meilisearch. Pour un gros volume, réglez la taille des lots et le délai de réponse :

```bash
bin/console meilisearch:import --batch-size=500 --response-timeout=10000
```

Pour ne réimporter que certains types, passez `--indices` avec une liste de noms d'index séparés par des virgules :

```bash
bin/console meilisearch:import --indices=invoices,clients
```

:::tip
Pour réindexer un système en service sans interruption, utilisez `--swap-indices`. Meilisearch remplit des index temporaires et les échange d'un coup à la fin de l'import : les utilisateurs ne voient jamais de résultats partiels pendant la reconstruction.

```bash
bin/console meilisearch:import --swap-indices
```

:::

Après l'import initial, les changements du quotidien sont pris en compte seuls : inutile de relancer `meilisearch:import` quand les utilisateurs créent, modifient ou suppriment des fiches dans l'application ou par l'API.

## Commandes de maintenance

Le module de recherche Meilisearch fournit quelques commandes de gestion des index. Toutes respectent le préfixe configuré.

| Commande | Rôle |
| --- | --- |
| `bin/console meilisearch:create` | Crée les index et applique leurs réglages (attributs filtrables et triables). Peut être relancée sans risque. |
| `bin/console meilisearch:import` | Importe en masse chaque fiche dans son index. Voir [Indexation initiale](#indexation-initiale). |
| `bin/console meilisearch:update-settings` | Envoie seulement les réglages (attributs filtrables et triables, etc.) sans réindexer les documents. À lancer après une mise à jour d'Augias si les réglages des index ont changé. |
| `bin/console meilisearch:clear` | Vide les index de leurs documents, sans supprimer les index. |
| `bin/console meilisearch:delete` | Supprime entièrement les index. Il faudra ensuite relancer `meilisearch:create` et `meilisearch:import`. |

Chaque commande accepte `--indices=<liste>` pour se limiter à certains index.

## La barre de recherche

Une fois Meilisearch configuré et indexé, la barre de recherche apparaît en haut de chaque page. La syntaxe (texte libre, filtres comme `in:`, `status:`, `client:`, `sort:`, etc.), ce qui se recherche et des exemples sont décrits dans [Rechercher](../using-augias/searching.md) : cette page couvre tout ce dont les utilisateurs ont besoin.

L'indexation est en temps réel : quand une fiche est créée, modifiée ou supprimée par l'application, l'API ou le serveur MCP, le changement part vers Meilisearch dans la même requête. Pas d'autre délai que le temps d'indexation de Meilisearch (quelques millisecondes en général), et pas de réindexation programmée en fonctionnement normal.

Si les index se décalent (par exemple après la restauration d'une base), relancez `bin/console meilisearch:import` pour les reconstruire depuis la base (voir [Indexation initiale](#indexation-initiale)).

## Sécurité

La clé d'API utilisée par Augias doit pouvoir lire et écrire dans les index. Le plus simple est la clé maîtresse de Meilisearch, mais en production, générez une [clé d'API restreinte](https://www.meilisearch.com/docs/learn/security/master_api_keys) aux index qui portent votre préfixe.

Donnez-lui ces actions sur les index `<préfixe>*` :

- `documents.add`, `documents.delete`, `documents.get` : indexation en temps réel et recherche ;
- `indexes.create`, `indexes.update`, `indexes.delete` : commandes de gestion ;
- `settings.update`, `settings.get` : `meilisearch:update-settings` ;
- `search` : la barre de recherche.

Si vous ne lancez pas les commandes de maintenance depuis le serveur de l'application (par exemple depuis une machine d'exploitation séparée), l'application peut se contenter d'une clé limitée à `search`, `documents.add`, `documents.delete` et `documents.get`.

:::warning
Le cloisonnement entre entreprises est assuré par Augias, avec le filtre `companyId` de chaque requête, et non par Meilisearch. Quiconque accède directement à l'API HTTP de Meilisearch avec une clé valide peut lire les données de toutes les entreprises. Traitez Meilisearch comme la base de données de l'application : sur un réseau privé, avec une clé restreinte.
:::

## Désactiver l'intégration

Pour couper la recherche, videz l'adresse ou la clé :

```ini title=".env"
AUGIAS_MEILISEARCH_URL=
AUGIAS_MEILISEARCH_API_KEY=
```

Redémarrez l'application. La barre de recherche disparaît et Augias cesse d'envoyer des mises à jour à Meilisearch. Les index déjà présents sur le serveur Meilisearch restent en place : supprimez-les avec `bin/console meilisearch:delete` (avant de vider les variables) ou depuis le tableau de bord de Meilisearch si vous n'en avez plus besoin.
