---
title: Comprendre le calendrier
description: Comment le calendrier d'une facture récurrente décide du moment où les factures sont générées.
sidebar_position: 2
---

# Comprendre le calendrier

Une facture est générée quand trois conditions sont réunies : la date du jour correspond au calendrier, la facture récurrente est active, et le planificateur d'Augias tourne. Cette page explique chacune.

## La génération

Le planificateur d'Augias passe **toutes les heures**. À chaque passage, il :

1. trouve toutes les factures récurrentes actives ;
2. vérifie si la date du jour fait partie des dates du calendrier ;
3. si oui, et si aucune facture n'a encore été générée ce jour-là, en crée une.

Cette dernière étape garantit qu'une facture récurrente ne produit jamais qu'une facture par jour concerné, même si le planificateur passe plusieurs fois.

:::warning
C'est le planificateur qui fait le travail : sans lui, aucune facture n'est générée. Le guide des [tâches planifiées](../installation-guide/distribution-package/cron-job-setup.md) explique sa mise en place sur chaque plateforme. Homebrew, Docker et l'installation rapide le lancent automatiquement.
:::

## Types de récurrence

Le champ `Type de récurrence` décide de ce qu'est un « jour concerné ». Chaque type demande un complément différent. L'application affiche pour l'instant leurs noms en anglais.

### Quotidienne (`Daily`)

Une facture chaque jour à partir de la date de début. Aucun complément : une fois active, elle génère chaque jour.

### Hebdomadaire (`Weekly`)

Fait apparaître `Se répète le`, une rangée de cases de `Lundi` à `Dimanche`. Cochez un ou plusieurs jours : une facture est générée chaque semaine sur chaque jour coché.

Pour un abonnement hebdomadaire, cochez un seul jour. Pour tous les jours ouvrés, cochez de `Lundi` à `Vendredi`.

### Mensuelle (`Monthly`)

Fait apparaître `Jours du mois`, une liste à choix multiples du 1er au 31. Choisissez un ou plusieurs jours : une facture est générée chaque mois sur chaque jour choisi.

:::note
Si vous choisissez le 31 et qu'un mois compte 30 jours ou moins, aucune facture n'est générée ce mois-là pour ce jour : il n'existe pas dans ce mois.
:::

### Annuelle (`Yearly`)

Fait apparaître deux champs :

- **`Se répète les mois`** : des cases de `Janvier` à `Décembre`. Cochez un ou plusieurs mois.
- **`Jour du mois`** *(facultatif)* : une liste du 1er au 31. Laissé vide, le calendrier prend le jour de la `Date de début`.

Un calendrier annuel génère une facture par mois choisi et par an, le jour choisi (ou hérité).

## Fin du calendrier

Le champ `Type de fin` décide de l'arrêt du calendrier.

- **`Jamais`** : les factures sont générées indéfiniment, jusqu'à ce que vous mettiez en pause, annuliez ou archiviez la facture récurrente.
- **`À la date suivante`** : fait apparaître la `Date de fin` (dans le futur). Le calendrier s'arrête à cette date ou après, et la facture récurrente passe automatiquement à `Terminé`.
- **`Après x occurrences`** : fait apparaître `Fin après un nombre d'occurrences`. Une fois ce nombre de factures générées, le calendrier s'arrête et la facture récurrente passe à `Terminé`.

`Terminé` est la fin *naturelle*, posée automatiquement quand une condition de fin est atteinte. Pour arrêter un calendrier à la main, voir [Gérer le calendrier](./managing-the-schedule.md).

## Ce que montre la page

La page de la facture récurrente montre une carte `Planification récurrente` qui résume le réglage en clair, la `Date de début` et la `Date de fin` s'il y en a une.

Tant que la facture récurrente est active, une carte `Prochaines occurrences` liste les prochaines dates de génération : pratique pour vérifier que le calendrier fait ce que vous attendiez.
