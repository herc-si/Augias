---
title: Appliquer les taxes aux factures
description: Ajouter des taxes ligne par ligne et des ajustements sur toute la facture, comme une retenue à la source.
sidebar_position: 4
---

# Appliquer les taxes aux factures

Augias gère deux niveaux de taxe indépendants sur chaque facture (et chaque devis) :

- **Taxes par ligne** : appliquées à chaque ligne, en pourcentage ou en montant fixe de son prix.
- **Taxes de facture** : appliquées à la facture entière, pour une retenue à la source, une majoration ou tout ajustement qui concerne toutes les lignes.

:::info
La colonne des taxes et la section des taxes de facture n'apparaissent qu'une fois au moins un [taux de taxe](./tax-rates.md) créé dans l'entreprise.
:::

## Taxes par ligne

Chaque ligne d'une facture a une colonne **TVA**. Une ligne peut recevoir un ou plusieurs taux.

### Ajouter une taxe à une ligne

1. Sur la ligne, cliquez sur **+ Ajouter une taxe** dans la colonne des taxes.
2. Choisissez le taux dans la liste. Elle affiche le nom du taux, le pourcentage ou le montant, et une étiquette pour les catégories particulières (exonérée, taux zéro, autoliquidation).
3. Pour une seconde taxe sur la même ligne, cliquez de nouveau sur **+ Ajouter une taxe** et choisissez un autre taux.

### Retirer une taxe d'une ligne

Cliquez sur le bouton de suppression à côté de la taxe, sur la ligne.

### Taxes composées

Un taux marqué [composé](./tax-rates.md#taxe-composée) se calcule sur le sous-total qui inclut les taxes déjà appliquées à la ligne, et non sur le prix d'origine. L'ordre des taxes sur la ligne fixe l'ordre de calcul.

## Taxes de facture

La section **Retenues et ajustements** se trouve sous les lignes. Elle sert aux taxes ou frais qui s'appliquent à toute la facture, par exemple une retenue à la source ou une contribution forfaitaire.

### Ajouter une taxe de facture

Cliquez sur **Ajouter une taxe de facture**. Une ligne de trois champs apparaît :

| Champ | Description |
| --- | --- |
| **Taxe** | Un de vos taux de taxe, dans la même liste que pour les lignes. |
| **Sens** | L'effet de la taxe sur le total de la facture (voir ci-dessous). |
| **Note** | Un texte libre facultatif, imprimé sur la facture (par exemple `Autoliquidation : TVA due par le preneur`). |

### Sens

| Sens | Effet |
| --- | --- |
| **Additive** | Le montant s'ajoute au total. Pour les majorations et les contributions. |
| **Déductive** | Le montant se retranche du total. Pour une retenue à la source que le client verse directement à l'administration. |
| **Informative** | La taxe figure sur la facture pour information, sans changer le total. Quand vous devez mentionner une taxe que le client gère lui-même. |

### Retirer une taxe de facture

Cliquez sur l'icône de corbeille à droite de la ligne.

## Taxes sur les devis

Les mêmes taxes par ligne et de facture sont disponibles sur les devis. Elles sont reprises quand un devis devient une [facture](../invoices/creating-an-invoice.md).

## Taux figés

À l'émission d'une facture, Augias fige chaque taux de taxe (nom, pourcentage, catégorie, type) tel qu'il est à ce moment. Si vous modifiez ensuite un taux, seules les nouvelles factures en tiennent compte ; les montants des factures déjà émises ne changent pas.

## Voir aussi

- [Régler les taux de taxe](./tax-rates.md)
- [Identifiants fiscaux des clients](./client-tax-identifiers.md)
- [Créer une facture](../invoices/creating-an-invoice.md)
