---
title: Gérer le calendrier
description: Mettre en pause, reprendre, annuler, archiver ou modifier une facture récurrente.
sidebar_position: 3
---

# Gérer le calendrier

Une fois la facture récurrente créée, son cycle de vie se pilote depuis sa page : le bouton principal, en haut à droite, porte l'action principale, et le menu `Plus d'actions` à côté contient les autres.

![La page d'une facture récurrente avec le menu des actions ouvert](/img/recurring-invoices/recurring-invoice-actions-menu.png)

## États

Une facture récurrente passe par ces états :

- **`Brouillon`** : enregistrée, mais ne génère pas encore de factures. Modifiable.
- **Active** : le planificateur génère les factures aux dates prévues.
- **`En pause`** : la génération est suspendue. Le calendrier est conservé et peut reprendre.
- **`Terminé`** : le calendrier a atteint sa date de fin ou son nombre d'occurrences. Augias pose cet état seul ; voir [Comprendre le calendrier](./understanding-the-schedule.md#fin-du-calendrier).
- **Annulée** : arrêtée à la main. Plus aucune facture ne sera générée.
- **Archivée** : masquée des listes par défaut. Archivez quand vous ne voulez plus voir une facture récurrente sans perdre son historique.

## Activer un brouillon

Un brouillon a un bouton `Activer` en haut à droite. Cliquez dessus pour le faire passer de brouillon à active. Le planificateur la prend en compte à son prochain passage.

## Mettre en pause et reprendre

Tant que la facture récurrente est active, le menu `Plus d'actions` propose `Mettre en pause`. La génération s'arrête aussitôt ; le calendrier, les lignes et la condition de fin restent tels quels.

![Une facture récurrente en pause avec le bouton Reprendre](/img/recurring-invoices/recurring-invoice-paused.png)

En pause, le bouton principal devient `Reprendre`. Il remet le calendrier en route. La génération reprend à la prochaine date prévue : les dates manquées pendant la pause ne sont pas rattrapées.

## Annuler

L'annulation arrête définitivement la génération. Dans `Plus d'actions`, cliquez sur `Annuler`. Les factures déjà générées ne sont pas touchées.

Préférez `Annuler` à `Mettre en pause` quand le calendrier ne doit pas reprendre, par exemple quand le client a mis fin à son abonnement.

## Archiver

`Archiver` (dans `Plus d'actions`) retire la facture récurrente des onglets `Actives` et `Terminées` de la liste. Elle reste dans le système, sous l'onglet `Archivées`, et ses factures générées ne sont pas touchées.

## Modifier

En brouillon, active ou en pause, `Modifier` (dans `Plus d'actions`) permet de changer les lignes, le calendrier, la date de début ou la condition de fin. Enregistrer une modification repasse la facture récurrente en brouillon : il faut ensuite l'`Activer` de nouveau, ce qui évite qu'une modification en cours génère une facture par erreur.

## Dupliquer

`Dupliquer` (dans `Plus d'actions`) crée une nouvelle facture récurrente remplie à partir de celle-ci. Pratique pour reprendre un modèle qui fonctionne pour un autre client.
