---
title: Régler les taux de taxe
description: Créer et gérer les taux de taxe à appliquer aux factures et aux devis.
sidebar_position: 1
---

# Régler les taux de taxe

Les taux de taxe définissent les pourcentages ou les montants fixes qu'Augias applique aux lignes des factures et des devis, et aux ajustements sur toute la facture comme une retenue à la source. Il faut au moins un taux pour que la colonne des taxes apparaisse sur les factures.

## Ouvrir les taux de taxe

Dans le menu latéral, dépliez **Système** et cliquez sur **Taux de taxe**. La liste montre tous les taux de votre entreprise.

## Ajouter un taux

Cliquez sur **Ajouter un taux de taxe** pour ouvrir le formulaire.

### Nom

Un libellé court qui identifie le taux (par exemple `TVA 20 %`, `TVA 5,5 %`). Le nom est unique dans l'entreprise et compte 32 caractères au plus.

### Taux

La valeur de la taxe. Pour un pourcentage, saisissez par exemple `20` (pour 20 %). Pour un forfait, saisissez le montant fixe.

### Type

Fixe le calcul par rapport au prix de l'article :

| Type | Comportement |
| --- | --- |
| **Incluse** | La taxe est déjà comprise dans le prix : elle en est extraite au calcul et affichée à part. |
| **Exclusive** | La taxe s'ajoute au prix de l'article et au total. |
| **Forfait** | Un montant fixe, quels que soient le prix et la quantité. |

### Catégorie

La catégorie détermine la présentation du taux sur les documents et celle des totaux. Les libellés s'affichent pour l'instant en anglais dans l'application :

| Catégorie | Quand l'utiliser |
| --- | --- |
| **Normale** (`Standard`) | Le cas général des biens et services taxables. |
| **Taux zéro** (`Zero-Rated`) | Taxable à 0 %. |
| **Exonérée** (`Exempt`) | Non soumis à la taxe. Le taux reste affiché par transparence. |
| **Hors champ** (`Out of Scope`) | Hors du champ de la taxe. |
| **Autoliquidation** (`Reverse Charge`) | Le client déclare la taxe à la place du fournisseur. |

### Taxe composée

Cochez **Taxe composée** pour calculer ce taux sur le sous-total majoré des autres taxes plutôt que sur le prix d'origine : une taxe sur la taxe, que certains pays exigent.

## Enregistrer

Cliquez sur **Enregistrer**. Le taux est aussitôt proposé sur les factures et les devis.

## Modifier ou supprimer un taux

Dans la liste des taux, utilisez les actions de la ligne pour modifier ou supprimer un taux.

:::warning
Modifier un taux ne vaut que pour la suite. Les montants de taxe des factures et devis déjà émis sont figés au taux en vigueur à l'émission et ne changent pas.
:::

## Voir aussi

- [Identifiants fiscaux de l'entreprise](./company-tax-identifiers.md)
- [Appliquer les taxes aux factures](./applying-tax-to-invoices.md)
