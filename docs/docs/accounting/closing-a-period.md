---
title: Clôturer une période
description: Sceller un mois ou un trimestre de comptabilité pour que ses écritures ne puissent plus être modifiées.
sidebar_position: 3
---

# Clôturer une période

La clôture transforme une liste d'écritures en livres sur lesquels vous pouvez vous appuyer. Elle les numérote, les scelle et fige les totaux, et elle est définitive.

Les périodes suivent votre `Périodicité de déclaration` : mensuelle ou trimestrielle. Une période naît avec la première écriture qui y est rangée : une entreprise qui n'a rien enregistré n'a aucune période.

## Clôturer une période terminée

Une période ne se clôture qu'une fois terminée : l'argent reçu avant sa date de fin lui appartient encore. Fermez-la quand la dernière recette de la période est enregistrée.

Cliquez sur `Comptabilité` dans le menu latéral. La carte `Période en cours` montre la période (`2026-Q1`, `2026-03`, etc.) et son état, ouverte ou clôturée. Une fois la période terminée, la carte affiche un bouton `Clôturer` suivi du nom de la période, sous l'avertissement *La clôture numérote les écritures, les scelle et fige les totaux. C'est définitif.* Le même bouton figure sur la page de déclaration de chaque période terminée. Voir [Déclarer votre chiffre d'affaires](./declaring-your-turnover.md).

:::danger
Une période clôturée ne se rouvre pas. Une erreur ne se corrige ensuite que par une écriture d'extourne dans une période ultérieure : voir [Corriger une écriture scellée](#corriger-une-écriture-scellée).
:::

## Ce que fait la clôture

- Chaque écriture reçoit un `N°` sans trou dans son livre, par ordre de date.
- Chaque écriture reçoit une empreinte, et chaque empreinte inclut la précédente : les écritures forment une chaîne qu'on ne peut ni réordonner ni compléter.
- Les écritures sont verrouillées. Toute modification ou suppression est refusée, d'où qu'elle vienne.
- Les totaux de la période sont conservés tels qu'ils étaient à ce moment, au lieu d'être recalculés.

Ouvrir une écriture scellée vous renvoie au livre avec le message *Cette écriture appartient à une période clôturée : elle ne peut plus être modifiée. Passez une écriture d'extourne dans une période ouverte.*

## Les périodes se clôturent dans l'ordre

Une période ne peut pas être clôturée tant qu'une période antérieure est ouverte : cela laisserait un trou dans la numérotation et casserait la chaîne. La tentative donne *Cette période ne peut pas être clôturée : elle l'est déjà, ou une période antérieure est encore ouverte.*

Clôturez d'abord la période antérieure.

## Un paiement qui arrive en retard

Un paiement daté d'une période déjà clôturée ne peut pas y entrer. Augias garde la vraie date, range l'écriture dans la plus ancienne période encore ouverte et la signale comme tardive. Rien n'est perdu, et aucune date n'est réécrite en douce.

## Corriger une écriture scellée

Ajoutez une écriture d'extourne dans une période ouverte :

1. Ouvrez le livre et cliquez sur `Nouvelle écriture`.
2. Saisissez le montant qui annule l'erreur, négatif pour annuler une recette.
3. Dites ce qu'elle annule dans `Nature de l'opération`, et indiquez le numéro de l'écriture d'origine dans `Notes`.

L'écriture d'origine et son extourne restent toutes deux visibles : c'est voulu.

## Vérifier que les livres sont intacts

En auto-hébergement, vous pouvez reparcourir tous les livres scellés et vérifier que rien n'a changé dans la base :

```bash
bin/console augias:accounting:verify-ledger
```

La commande affiche une ligne par entreprise et par livre, avec le nombre d'écritures vérifiées et le résultat, et se termine en erreur si un livre ne correspond plus à ce qu'il était au scellement, en nommant l'écriture où la chaîne se rompt. Elle se contente de lire et de signaler, sans jamais réparer : réécrire une empreinte est précisément ce que le scellement empêche.

## Voir aussi

- [Vos livres](./your-books.md)
- [Déclarer votre chiffre d'affaires](./declaring-your-turnover.md)
