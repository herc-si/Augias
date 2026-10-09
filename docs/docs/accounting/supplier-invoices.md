---
title: Enregistrer les factures fournisseurs
description: Enregistrer les factures de vos fournisseurs, saisies à la main ou lues dans leur fichier Factur-X.
sidebar_position: 6
---

# Enregistrer les factures fournisseurs

Enregistrez les factures de vos fournisseurs pour connaître ce que vous devez et la TVA que vous pouvez déduire. Dans le menu latéral, cliquez sur `Factures d'achat`, puis sur `Ajouter une facture`.

Les factures que vos fournisseurs envoient par la plateforme de facturation électronique arrivent seules, sans rien à saisir. Pour les autres, deux possibilités.

## Depuis un fichier Factur-X

Beaucoup de fournisseurs envoient déjà un PDF `Factur-X` : un PDF d'apparence ordinaire qui contient les données de la facture.

1. En haut de la page `Ajouter une facture`, dans le cadre `Importer une facture Factur-X`, choisissez le PDF du fournisseur (ou le fichier XML de la facture seul).
2. Cliquez sur `Importer`.

Augias lit dans la facture elle-même le fournisseur, le numéro, les dates d'émission et d'échéance, le total et la TVA ; rien n'est deviné. Le fournisseur est retrouvé par son SIREN ou son numéro de TVA, puis par son nom, ou créé.

La facture s'ouvre en brouillon pour que vous la vérifiiez, avec le fichier joint. Enregistrez-la, puis validez-la comme toute facture fournisseur. Le fichier du fournisseur reste accessible depuis la page de la facture, avec `Voir la facture du fournisseur`.

:::info
Un PDF sans données intégrées, comme un scan ou la photo d'un ticket, ne peut pas être lu ainsi. Augias le signale, et vous saisissez la facture ci-dessous.
:::

## Saisie à la main

Remplissez le formulaire : le fournisseur (choisissez-le ou tapez un nouveau nom), le numéro et les dates de la facture, le total et, si vous facturez la TVA, son montant et la nature de l'achat, bien ou service.

## Dépannage

### `Ce fichier ne contient pas de facture Factur-X lisible`

Le PDF ne porte pas de données de facture. Demandez à votre fournisseur s'il peut envoyer du Factur-X, qui deviendra la règle pour les entreprises françaises en septembre 2027, ou saisissez la facture à la main.

### `Ce fichier est un avoir fournisseur`

Les avoirs fournisseurs ne sont pas encore gérés. Enregistrez le remboursement comme une écriture négative dans vos livres, ou diminuez la facture fournisseur correspondante.
