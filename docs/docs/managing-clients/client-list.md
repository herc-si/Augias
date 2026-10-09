---
title: Liste des clients
description: Parcourir, rechercher, filtrer et archiver vos clients dans Augias.
sidebar_position: 1
---

# Liste des clients

La page `Clients` (menu latéral → `Clients` → `Liste des clients`, ou `/clients`) réunit tout ce qui concerne vos clients : la liste de ceux que vous facturez, la recherche d'un client précis et l'archivage de ceux avec qui vous ne travaillez plus.

![La liste des clients avec les statistiques, les onglets Actifs et Archivés et le tableau](/img/managing-clients/client-list-active.png)

## Le contenu de la page

Quatre statistiques résument l'entreprise en cours :

- `Clients actifs` : le nombre de clients que vous facturez.
- `Clients archivés` : le nombre de clients archivés.
- `Total des contacts` : le nombre de contacts, tous clients confondus.
- `Solde impayé` : le montant impayé sur l'ensemble des factures de vos clients.

Deux onglets suivent :

- `Actifs` : les clients proposés sur les nouveaux devis et factures.
- `Archivés` : les clients retirés de la création de devis et de factures, mais gardés pour l'historique.

Chaque onglet a son tableau, avec les mêmes colonnes : `Nom`, `Site web`, `Devise`, `Solde total`, `Solde impayé` et `Créé le`. La zone de recherche au-dessus du tableau filtre la liste, et le bouton `Filtres` filtre par devise ou par période. L'icône à côté affiche ou masque des colonnes.

Les deux icônes au bout de chaque ligne sont `Voir` (un œil), qui ouvre la fiche du client, et `Modifier` (un crayon), qui ouvre le formulaire de création.

## Créer un client

Cliquez sur le bouton de création en haut à droite. Le formulaire est décrit dans [Créer un client](./create-new-client.md).

## Archiver un client

L'archivage garde tout l'historique du client (devis, factures, paiements, contacts, adresses), mais le retire de la liste des clients proposés sur les nouveaux devis et factures.

1. Dans l'onglet `Actifs`, cochez un ou plusieurs clients.
2. Cliquez sur l'action groupée `Archiver` qui apparaît au-dessus du tableau.

Les clients archivés passent dans l'onglet `Archivés`. Ils ne comptent plus dans `Clients actifs` et ne sont plus proposés sur les nouveaux devis et factures.

:::info
L'archivage ne supprime rien. Vous pouvez réactiver un client archivé à tout moment, et ses factures, devis et paiements restent visibles dans le tableau de bord et les rapports.
:::

## Réactiver un client archivé

Dans l'onglet `Archivés`, cochez le ou les clients et utilisez l'action groupée `Activer`. Ils reviennent dans `Actifs` et sont de nouveau proposés.

## Supprimer un client

La suppression est définitive et possible depuis les deux onglets, sans archiver d'abord.

1. Cochez le ou les clients à supprimer.
2. Cliquez sur l'action groupée `Supprimer`.

:::danger
Supprimer un client supprime avec lui ses contacts, ses adresses, son crédit, ses brouillons et ses devis.

Un document émis, en revanche, se conserve : tant qu'un client a une facture ou un avoir émis, même annulé ou archivé, Augias refuse de le supprimer, et rien n'est supprimé. Pour ne plus facturer un client tout en gardant son historique, **archivez-le**.
:::
