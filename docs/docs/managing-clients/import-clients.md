---
title: Importer des clients
description: Reprendre une liste de clients depuis un tableur ou un autre logiciel, à partir d'un fichier CSV.
sidebar_position: 7
---

# Importer des clients

Reprenez d'un coup la liste de clients que vous teniez dans un tableur ou dans un autre logiciel, à partir d'un fichier CSV. Vous voyez ce qui sera créé avant que rien ne soit importé.

## Préparer le fichier

Depuis la liste des clients, cliquez sur `Importer`, puis sur `Télécharger le modèle` pour obtenir un fichier d'exemple à compléter. Vous pouvez aussi partir de l'export de votre ancien outil.

Le fichier compte une ligne par client, et sa première ligne nomme les colonnes. L'ordre des colonnes est libre et seule la colonne `Nom` est obligatoire.

| Colonne | Ce qu'elle contient |
|---|---|
| `Nom` | Le nom du client. Obligatoire. |
| `Type` | `Entreprise` (par défaut) ou `Particulier`. |
| `SIRET` | Le SIRET du client. Obligatoire pour une entreprise. |
| `N° TVA` | Son numéro de TVA intracommunautaire. |
| `E-mail`, `Prénom`, `Nom du contact` | Le contact à qui écrire. Il est créé quand la ligne porte une adresse e-mail. |
| `Site web` | L'adresse du site du client. |
| `Adresse`, `Complément d'adresse`, `Code postal`, `Ville`, `Pays` | Son adresse. Le pays s'écrit en toutes lettres ou par son code (`France` ou `FR`). |
| `Devise` | Le code de sa devise (`EUR`). Voir [Devise du client](./client-currency.md). |

Les titres usuels sont aussi reconnus, en français comme en anglais : « Raison sociale », « Company », « Zip »… Une colonne qui n'est pas reconnue est ignorée, et l'aperçu vous la signale.

:::info
Le séparateur (point-virgule ou virgule) et l'encodage du fichier sont détectés : un fichier enregistré par Excel en « CSV » convient tel quel. Un fichier contient 2 000 lignes et pèse 1 Mo au plus.
:::

## Vérifier l'aperçu

Choisissez votre fichier, puis cliquez sur `Voir l'aperçu`. À ce stade, rien n'est importé. L'aperçu indique :

- le nombre de **clients à créer** ;
- les clients **déjà présents** : une ligne dont le nom existe déjà dans vos clients, archivés compris, est laissée de côté ;
- les **lignes refusées**, avec leur numéro dans le fichier et ce qui bloque.

Une ligne est refusée quand elle enfreint une règle de la fiche client : une entreprise sans SIRET, une adresse e-mail mal formée, un nom vide. Les règles sont celles de [la création d'un client](./create-new-client.md).

:::tip
Pour reprendre une ligne refusée, corrigez-la dans le fichier et importez-le de nouveau : les clients déjà créés sont reconnus et ne sont pas créés une seconde fois.
:::

## Importer

Cliquez sur `Importer ces clients`. Le compte rendu reprend les mêmes chiffres, cette fois pour ce qui a été fait, et `Voir les clients` vous ramène à la liste.

L'import ne fait qu'ajouter : il ne modifie ni ne supprime jamais un client existant.

## Voir aussi

- [Liste des clients](./client-list.md)
- [Créer un client](./create-new-client.md)
