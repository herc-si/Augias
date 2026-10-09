---
title: Créer une facture récurrente
description: Mettre en place un modèle de facture récurrente qui émet des factures automatiquement selon un calendrier.
sidebar_position: 1
---

# Créer une facture récurrente

Une facture récurrente est un modèle enregistré, lié à un client. Augias en tire une vraie facture selon le calendrier choisi (chaque jour, chaque semaine, chaque mois ou chaque année) jusqu'à ce que vous l'arrêtiez ou qu'elle atteigne sa fin.

## Ouvrir le formulaire

Dans le menu latéral, cliquez sur `Factures récurrentes` → `Créer une récurrente`, ou sur le bouton `Créer une facture récurrente` en haut à droite de la liste des factures récurrentes.

![La liste des factures récurrentes avec le bouton de création](/img/recurring-invoices/recurring-invoices-list-page.png)

## Remplir le modèle

Le haut du formulaire est celui d'une facture ponctuelle : choisissez le client, les contacts destinataires, et ajoutez les lignes.

![Le formulaire de création d'une facture récurrente](/img/recurring-invoices/create-recurring-invoice-form.png)

- **`Client`** *(obligatoire)* : le client facturé. Une fois choisi, une liste `Envoyer la facture à :` apparaît pour cocher au moins un contact qui recevra chaque facture.
- **`Remise`** *(facultative)* : un montant ou un pourcentage, appliqué à chaque facture générée.
- **`Lignes de facturation`** *(au moins une)* : description, prix, quantité et taxe. Le total en bas du formulaire est celui de chaque facture.

:::tip
Les descriptions des lignes acceptent des repères remplacés à chaque génération : `{day}`, `{day_name}`, `{month}` et `{year}`. Par exemple, `Abonnement pour {month}` devient « Abonnement pour mai » sur la facture de mai. Cliquez sur `Variables disponibles pour les descriptions`, au-dessus des lignes, pour la liste complète.
:::

`Conditions & Notes` est replié par défaut : cliquez sur l'en-tête de la section pour le déplier. Les deux champs valent pour chaque facture générée ; les notes restent internes et ne sont jamais montrées au client.

## Régler le calendrier

La section `Planification récurrente` dit *quand* les factures sont générées. Le détail est dans [Comprendre le calendrier](./understanding-the-schedule.md) ; en bref :

![La section Planification récurrente, en hebdomadaire](/img/recurring-invoices/schedule-weekly-options.png)

- **`Date de début`** *(obligatoire)* : le départ du calendrier. Aujourd'hui par défaut ; ne peut pas être dans le passé.
- **`Type de récurrence`** *(obligatoire)* : `Quotidienne`, `Hebdomadaire`, `Mensuelle` ou `Annuelle`. Chaque type fait apparaître son propre champ (jours de la semaine, jours du mois, mois de l'année).
- **`Type de fin`** *(obligatoire)* : `Jamais`, `À la date suivante` ou `Après x occurrences`. Le champ de date ou de nombre correspondant apparaît une fois le choix fait.

## Enregistrer

Deux choix d'enregistrement, en bas du formulaire :

- **`Enregistrer comme brouillon`** : enregistre le modèle sans générer de facture. Pratique pour revoir le calendrier avant de l'activer.
- **`Enregistrer et activer`** : dans le menu à côté de `Enregistrer comme brouillon`, enregistre le modèle et l'active aussitôt. La première facture est générée au premier passage du planificateur après la date de début.

Après l'enregistrement, vous arrivez sur la page de la facture récurrente. De là, vous pouvez `Activer` un brouillon, ou passer à [Gérer le calendrier](./managing-the-schedule.md) une fois qu'elle tourne.

:::warning
Les factures ne sont générées que si le planificateur d'Augias tourne en arrière-plan. Il se met en place une fois, à l'installation : voir [Tâches planifiées](../installation-guide/distribution-package/cron-job-setup.md). Sans lui, une facture récurrente active ne produit aucune facture.
:::
