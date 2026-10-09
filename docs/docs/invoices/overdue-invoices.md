---
title: Factures en retard
description: Passer automatiquement les factures impayées en retard et relancer les clients de plus en plus fermement.
sidebar_position: 6
---

# Factures en retard

Augias fait passer seules les factures impayées en retard une fois l'échéance dépassée, et peut envoyer aux clients des relances de plus en plus fermes, à intervalles réguliers.

## Fonctionnement

Une tâche de fond passe toutes les heures sur les factures en attente. Toute facture dont l'échéance est dépassée passe au statut **En retard**. Augias envoie alors aussi une notification interne aux utilisateurs abonnés aux alertes de factures.

:::info
Seule une facture qui a une échéance peut passer en retard. Une facture sans date d'échéance ne passe jamais en retard.
:::

## Donner une échéance à une facture

À la création ou à la modification d'une facture, remplissez le champ `Date d'échéance`. La date figure sur le PDF et sur la page de la facture vue par le client ; elle sert au passage en retard comme au calendrier des relances.

Le détail du formulaire est dans [Créer une facture](./creating-an-invoice.md).

## Relances de paiement

En plus du passage en retard, Augias peut relancer les clients par e-mail. Les relances partent vers les contacts de la facture à trois moments après l'échéance :

| Jours de retard | Objet de l'e-mail |
| --- | --- |
| 1 jour | Rappel de paiement : facture `{id}` |
| 7 jours | Paiement en retard : facture `{id}` |
| 14 jours | URGENT : facture `{id}`, règlement immédiat demandé |

Une relance peut aussi partir quelques jours *avant* l'échéance.

Le détail du réglage est dans [Relances de paiement](./payment-reminders.md).

## Régler les relances

Ouvrez `Paramètres`, onglet `Factures`, cadre `Rappels de paiement`.

| Réglage | Par défaut | Description |
| --- | --- | --- |
| **Activer les rappels automatiques** | Activé | Interrupteur général de toutes les relances automatiques. |
| **Activer les rappels avant échéance** | Activé | Envoie une relance avant l'échéance. |
| **Jours avant l'échéance** | 3 | Le nombre de jours avant l'échéance où part la relance. |

:::note
Les relances font partie des offres payantes. Pendant l'essai, les réglages sont visibles mais ne peuvent pas être activés.
:::

## Statuts

Une facture passée en retard affiche le statut **En retard** dans la liste et sur sa page. Un paiement enregistré sur une facture en retard la fait passer **Payée**.

Le cycle complet est décrit dans [Statuts des factures](./invoice-statuses.md).

## Voir aussi

- [Relances de paiement](./payment-reminders.md)
- [Statuts des factures](./invoice-statuses.md)
- [Créer une facture](./creating-an-invoice.md)
