---
title: Facturer un acompte
description: Facturer une part d'un devis accepté avant les travaux, puis le solde sur la facture finale.
sidebar_position: 9
---

# Facturer un acompte

Un devis accepté peut se facturer en plusieurs fois : un ou plusieurs acomptes d'abord, puis une facture finale pour ce qui reste.

## Facturer l'acompte

Ouvrez le devis accepté. Dans le cadre `Acomptes`, indiquez le pourcentage du devis à facturer puis cliquez sur `Facturer`.

Le formulaire de facture s'ouvre, déjà rempli : une ligne « Acompte de 30 % sur le devis n° … » par taux de taxe du devis, afin que chaque taux soit facturé pour sa part. Vérifiez la facture, choisissez son destinataire, puis enregistrez-la ou envoyez-la comme n'importe quelle facture.

Une facture d'acompte est une vraie facture : elle prend un numéro quand elle est finalisée, porte sa taxe, se règle et se relance comme les autres. Sa fiche rappelle le devis dont elle vient.

:::info
Tant que rien n'est enregistré, rien n'est créé : fermer le formulaire n'ajoute aucune facture et ne consomme aucun numéro.
:::

Vous pouvez facturer plusieurs acomptes sur le même devis, tant que leur somme ne dépasse pas le devis. Le cadre `Acomptes` les liste avec leur montant et leur statut.

## Facturer le solde

Quand le travail est fait, cliquez sur `Créer la facture` sur le devis. La facture finale reprend toutes les lignes du devis, puis une ligne « Acompte déjà facturé — facture n° … » par acompte, en négatif. Ses totaux sont donc ce qui reste à facturer : hors taxes, taxe et montant à payer.

Seuls les acomptes **finalisés** sont déduits. Une facture d'acompte restée en brouillon ou annulée n'est pas reprise : finalisez-la ou supprimez-la avant de créer la facture finale.

:::warning
Une fois la facture finale créée, il n'est plus possible de facturer un acompte sur ce devis.
:::

## Bon à savoir

- Une remise en pourcentage sur le devis s'applique de la même façon à l'acompte et à la facture finale. Une remise d'un montant fixe est répartie entre eux dans la proportion de l'acompte.
- En facturation électronique, une facture d'acompte est transmise comme telle (type « facture d'acompte »).

## Voir aussi

- [Créer une facture](./creating-an-invoice.md)
- [Statuts des factures](./invoice-statuses.md)
