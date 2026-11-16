---
title: Consulter les rapports
description: "La balance âgée, le chiffre d'affaires par client et le relevé de compte : qui vous doit quoi, et ce que chaque client vous a rapporté."
sidebar_position: 8
---

# Consulter les rapports

La page `Rapports`, dans le menu, répond à deux questions : qui vous doit de l'argent et depuis quand, et ce que chaque client vous a rapporté sur une année. Les chiffres sont lus directement dans vos factures, vos avoirs et vos paiements : ils sont à jour à chaque ouverture de la page.

## Balance âgée

L'onglet `Balance âgée` liste, client par client, ce qui reste à payer sur vos factures émises, réparti selon le retard de chaque facture sur son échéance :

| Colonne | Ce qu'elle contient |
|---|---|
| `Non échu` | Les factures dont l'échéance n'est pas passée, ou qui n'ont pas d'échéance. |
| `1 à 30 j de retard`, `31 à 60 j de retard`, `61 à 90 j de retard` | Les factures en retard, selon le nombre de jours écoulés depuis l'échéance. |
| `Plus de 90 j` | Les retards les plus anciens, affichés en rouge. |
| `Total` | Tout ce que le client vous doit. |

Le client qui vous doit le plus est en tête. Cliquez sur son nom pour ouvrir sa fiche et retrouver les factures concernées.

:::info
Seul le reste à payer compte : une facture réglée en partie n'apparaît que pour son solde. Les brouillons et les factures payées n'apparaissent pas.
:::

## Chiffre d'affaires par client

L'onglet `Chiffre d'affaires par client` porte sur une année civile, à choisir parmi les quatre dernières.

| Colonne | Ce qu'elle contient |
|---|---|
| `Factures` | Le nombre de factures émises dans l'année. |
| `Facturé HT` | Leur montant hors taxes et hors débours. |
| `Avoirs HT` | Les avoirs émis dans l'année, sur la même base. |
| `Net HT` | Le facturé moins les avoirs. Les clients sont classés sur cette colonne. |
| `Encaissé TTC` | Les paiements reçus dans l'année, toutes taxes comprises. |

Une facture compte pour l'année de sa date de facture, un paiement pour l'année où il a été enregistré. Les brouillons et les factures annulées ne comptent pas, et un règlement imputé sur le [crédit du client](../managing-clients/client-credit.md) n'est pas un encaissement.

:::note
Si vous facturez dans plusieurs devises, chaque rapport affiche un tableau par devise : des montants en devises différentes ne sont jamais additionnés.
:::

## Relevé de compte d'un client

Le relevé de compte liste, pour un client, tout ce qui a fait varier ce qu'il vous doit, par date, avec le solde après chaque ligne. Ouvrez la fiche du client, puis le menu `⋮` et `Relevé de compte`.

| Mouvement | Effet sur le solde |
|---|---|
| Facture émise | Augmente ce que le client vous doit (`Débit`). |
| Paiement reçu | Le diminue (`Crédit`), quel que soit le moyen de paiement. |
| Avoir émis | Le diminue (`Crédit`). |
| Remboursement d'un avoir | L'augmente de nouveau (`Débit`). |

Choisissez `Tout` pour l'historique complet, ou une année : le relevé commence alors par le `Solde reporté`, ce qui était dû avant le 1er janvier. Un solde négatif signifie que vous devez de l'argent au client.

`Télécharger en PDF` produit le relevé à vos couleurs, à joindre à une relance ou à envoyer en fin d'année.

:::info
Les brouillons et les factures annulées n'apparaissent pas. Un avoir imputé sur une facture n'a pas de ligne à part : il a déjà diminué le solde au moment où il a été émis.
:::

## Exporter

Le bouton `Exporter en CSV` télécharge le rapport affiché, prêt à ouvrir dans un tableur.

## Voir aussi

- [Exporter vos données](./exporting-data.md)
