---
title: Gérer les factures
description: Consulter, rechercher, dupliquer, annuler et archiver des factures dans Augias.
sidebar_position: 4
---

# Gérer les factures

## La liste des factures

Ouvrez `Factures de vente` dans le menu latéral pour voir toutes vos factures. Quatre cartes résument la situation en haut de la page :

- **Total des factures** : le nombre de factures actives.
- **En attente** : le nombre de factures en attente, avec entre parenthèses celles en retard.
- **Revenu total** : la somme des factures payées.
- **Impayé** : le montant encore dû sur toutes les factures non payées.

![La liste des factures avec les cartes de synthèse et le tableau triable](/img/invoices/invoice-list.png)

Le tableau liste chaque facture avec ces colonnes : N° de facture, Date de facture, Client, Solde, Date d'échéance, Date de paiement, Statut, Total, Taxe et Remise. Cliquez sur l'en-tête d'une colonne pour trier.

La zone **Rechercher** filtre par numéro ou par nom de client. Le bouton **Filtres** filtre par statut, par période ou selon d'autres critères. Le bouton **Colonnes** affiche ou masque des colonnes.

### Factures archivées

L'onglet `Archivées` montre les factures que vous avez archivées. Elles disparaissent de la liste active et du calcul de l'impayé, mais ne sont pas supprimées et restent consultables.

## Consulter une facture

Cliquez sur `Voir` dans la colonne des actions pour ouvrir la facture.

La page montre la facture entière : vos coordonnées à gauche, celles du client à droite, le détail ligne par ligne et les totaux en bas. Les conditions et les notes éventuelles figurent sous les lignes.

Le cadre **Résumé de la facture**, à droite, affiche le statut, le total, la date de facture, l'échéance et, pour une facture payée, la date de paiement et le solde.

Le cadre **Client** donne le nom du client (avec un lien vers sa fiche) et le contact qui reçoit les e-mails.

## Modifier une facture

Cliquez sur `Modifier` dans les actions de la liste, ou dans `Plus d'actions` sur la page de la facture. Le formulaire est le même qu'à la création.

Un brouillon se modifie librement. Une facture déjà émise se corrige par un [avoir](./credit-notes.md). Quand un [régime comptable](../accounting/setting-up-accounting.md) est configuré, Augias refuse de la modifier et vous ramène sur la facture.

:::info
Sans régime comptable, modifier une facture **En attente** ou **En retard** la repasse en brouillon, et rien n'est renvoyé au client. Utilisez `Envoyer au client` après la modification pour qu'il reçoive la nouvelle version.
:::

## Dupliquer une facture

La duplication crée une nouvelle facture avec le même client, les mêmes lignes, la même remise, les mêmes conditions et les mêmes notes. Elle part au statut **Nouveau**, sans numéro : aucune date ni aucun paiement de l'originale n'est repris.

Pour dupliquer une facture :

1. Ouvrez la facture.
2. Cliquez sur le bouton `Plus d'actions`.
3. Choisissez `Dupliquer`.

![Le menu Plus d'actions d'une facture](/img/invoices/invoice-more-actions.png)

La copie s'ouvre dans le formulaire pour que vous ajustiez dates et montants avant d'enregistrer.

:::tip
Dupliquer est le moyen le plus rapide de refacturer les mêmes services au même client. Pour une facturation automatique et régulière, utilisez plutôt les [factures récurrentes](../recurring-invoices/creating-a-recurring-invoice.md).
:::

## Annuler une facture

Annuler une facture la passe au statut **Annulée**, arrête toutes les relances et transforme les paiements enregistrés en **crédit client**.

Pour annuler :

1. Ouvrez la facture.
2. Cliquez sur `Plus d'actions`, puis `Annuler`.

:::warning
N'annulez de cette façon qu'un brouillon, ou une facture qui n'a jamais été remise au client. Une facture émise se corrige par un [avoir](./credit-notes.md) ; avec un régime comptable, `Annuler` n'est d'ailleurs plus proposé pour elle.
:::

Une facture annulée peut être rouverte avec `Rouvrir` : elle redevient un brouillon.

## Archiver une facture

L'archivage sort une facture payée ou annulée de la liste active. La facture n'est ni supprimée ni retirée des totaux financiers : la liste reste simplement plus lisible.

Pour archiver une ou plusieurs factures, cochez-les dans la liste et utilisez les actions groupées qui apparaissent, ou passez par `Plus d'actions` sur la page d'une facture.

Les factures archivées se retrouvent dans l'onglet `Archivées` de la liste.
