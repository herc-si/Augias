---
title: Statuts des factures
description: Comprendre chaque statut de facture dans Augias et les actions possibles à chaque étape.
sidebar_position: 2
---

# Statuts des factures

Chaque facture a un statut qui dit où elle en est. Le statut détermine les actions possibles et le déclenchement des relances automatiques.

## Vue d'ensemble

| Statut | Couleur | Signification |
| --- | --- | --- |
| **Nouveau** | Gris | La facture vient d'être dupliquée ou créée par programme, et n'a pas encore été enregistrée. |
| **Brouillon** | Bleu | La facture est enregistrée mais pas encore finalisée. Elle n'a pas de numéro et se modifie librement. |
| **En attente** | Jaune | La facture est finalisée et numérotée. Le paiement est attendu. |
| **En retard** | Rouge | L'échéance est passée et la facture n'est pas payée. |
| **Payée** | Vert | La facture est entièrement payée. |
| **Créditée** | Cyan | Des avoirs ont soldé la facture, sans paiement. |
| **Annulée** | Gris | La facture a été annulée. Les paiements déjà enregistrés deviennent du crédit client. |

## Brouillon

Un brouillon n'est pas visible du client et n'a pas encore de numéro. Tout se modifie : lignes, dates, remises, conditions.

![Un brouillon de facture avec le bouton d'envoi dans la barre d'outils](/img/invoices/invoice-view-draft.png)

**Actions possibles :** Modifier, Envoyer au client, Finaliser sans envoyer, Dupliquer, Annuler.

`Envoyer au client` finalise la facture, qui prend son numéro et passe **En attente**, puis l'envoie par e-mail. `Finaliser sans envoyer`, dans le menu à côté, la finalise sans rien envoyer.

## En attente

Une facture en attente est finalisée : le client doit la payer.

![Une facture en attente avec les boutons Payer maintenant et Envoyer au client, et le badge En attente](/img/invoices/invoice-view-pending.png)

**Actions possibles :** Payer maintenant, Envoyer au client, Envoyer un rappel, Dupliquer, Établir un avoir.

- **Payer maintenant** : enregistrer un paiement sur cette facture.
- **Envoyer au client** : renvoyer la facture par e-mail, par exemple si le premier envoi s'est perdu.
- **Établir un avoir** : corriger ou annuler la facture. Voir [Avoirs](./credit-notes.md).

Augias surveille l'échéance et fait passer la facture **En retard** dès le lendemain.

:::info
Une facture émise ne s'annule pas et ne se modifie pas : elle se corrige par un avoir. Quand un [régime comptable](../accounting/setting-up-accounting.md) est configuré, Augias l'impose et retire `Modifier` et `Annuler` des factures en attente ou en retard. Sans régime, ces deux actions restent proposées.
:::

:::info
Les relances automatiques ne concernent que les factures **En attente** ou **En retard**. Voir [Relances de paiement](./payment-reminders.md).
:::

## En retard

Une facture en retard est une facture en attente dont l'échéance est passée. Le badge passe au rouge et l'échéance est mise en évidence dans le résumé de la facture.

![Une facture en retard avec le badge rouge et l'échéance mise en évidence](/img/invoices/invoice-view-overdue.png)

**Actions possibles :** les mêmes qu'**En attente**. Les relances automatiques continuent selon le calendrier des retards (1, 7 et 14 jours).

## Payée

Une facture payée est close. Le résumé affiche la date de paiement et le solde.

![Une facture payée avec le badge vert et la date de paiement](/img/invoices/invoice-view-paid.png)

**Actions possibles :** Dupliquer, Envoyer au client, Établir un avoir, Archiver, PDF, Imprimer.

## Créditée

Une facture créditée est soldée par un ou plusieurs [avoirs](./credit-notes.md) imputés sur elle, sans aucun paiement : c'est ainsi qu'on annule une facture déjà émise. Les relances s'arrêtent. Si une partie avait été payée, la facture passe **Payée** une fois soldée.

**Actions possibles :** Dupliquer, Archiver, PDF, Imprimer.

## Annulée

Annuler une facture :

1. la passe au statut **Annulée** et arrête toutes les relances ;
2. transforme les paiements déjà enregistrés en **crédit client**, utilisable sur les prochaines factures.

Pour annuler, ouvrez le menu `Plus d'actions` de la facture et choisissez `Annuler`.

Une facture annulée peut être rouverte par `Rouvrir` : elle redevient un brouillon.

## Les passages d'un statut à l'autre

```text
Brouillon → En attente  (Envoyer au client ou Finaliser sans envoyer)
En attente → Payée      (paiement enregistré)
En attente → En retard  (échéance passée, automatique)
En retard → Payée       (paiement enregistré)
En attente → Créditée   (avoir imputé qui solde la facture)
En retard → Créditée    (avoir imputé qui solde la facture)
Brouillon → Annulée
En attente → Annulée    (sans régime comptable seulement)
En retard → Annulée     (sans régime comptable seulement)
Annulée → Brouillon     (Rouvrir)
```

Toute facture peut être dupliquée en une nouvelle facture au statut **Nouveau**.
