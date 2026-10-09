---
title: Relances de paiement
description: Relancer automatiquement les factures impayées par des e-mails programmés, ou envoyer une relance à la main à tout moment.
sidebar_position: 5
---

# Relances de paiement

Augias peut relancer automatiquement par e-mail les clients dont les factures restent impayées. Les relances suivent un calendrier fixe : une relance facultative avant l'échéance, puis 1, 7 et 14 jours après le passage en retard.

Vous pouvez aussi relancer à la main, à tout moment, depuis la page de la facture.

## Le calendrier des relances

Chaque facture reçoit au plus **4 relances automatiques** :

| Relance | Envoi | Ton |
| --- | --- | --- |
| Avant échéance | N jours avant l'échéance (réglable, 3 par défaut) | Amical |
| Retard, 1 jour | Le lendemain de l'échéance | Courtois |
| Retard, 7 jours | 7 jours après l'échéance | Ferme |
| Retard, 14 jours | 14 jours après l'échéance | Urgent (dernière) |

Chaque relance part une seule fois par facture : la même relance n'est jamais envoyée deux fois. Après celle des 14 jours, les relances automatiques s'arrêtent et une notification d'escalade part vers vos utilisateurs.

:::info
Seules les factures **En attente** ou **En retard** reçoivent des relances automatiques. Les factures payées, les brouillons et les factures annulées sont ignorés.
:::

## Activer les relances

Ouvrez `Paramètres`, onglet `Factures`, et descendez jusqu'au cadre **Rappels de paiement**.

![Le cadre Rappels de paiement avec l'interrupteur général, le rappel avant échéance et le nombre de jours](/img/invoices/payment-reminders-settings.png)

Activez **Activer les rappels automatiques** pour mettre en route tout le calendrier. Désactivé, aucune relance automatique ne part, pour aucune facture, y compris avant échéance.

## Relance avant échéance

La relance avant échéance prévient le client avant que la facture passe en retard.

Dans le sous-cadre **Rappel avant échéance** :

- **Activer les rappels avant échéance** active ou coupe cette seule relance, sans toucher aux relances de retard.
- **Jours avant l'échéance** fixe combien de jours avant l'échéance elle part. De 0 à 30, `3` par défaut ; `0` l'envoie le jour même de l'échéance.

## Les relances de retard

Les trois relances de retard partent à intervalles fixes : elles ne se désactivent ni ne se déplacent une à une.

![Le cadre Calendrier de rappel des factures en retard avec les intervalles de 1, 7 et 14 jours](/img/invoices/overdue-reminder-schedule.png)

- **1 jour** : un rappel courtois, le lendemain de l'échéance.
- **7 jours** : un rappel plus ferme, une semaine après.
- **14 jours** : un dernier rappel urgent, deux semaines après.

L'objet et le texte de l'e-mail se durcissent à chaque étape.

:::info
Les relances sont vérifiées et envoyées **une fois par heure**. Il peut donc s'écouler jusqu'à une heure entre l'échéance et la première relance.
:::

## Relancer à la main

Vous pouvez relancer un client à tout moment, où que la facture en soit dans le calendrier. Une relance manuelle ne modifie ni ne remet à zéro le calendrier automatique.

1. Ouvrez la facture concernée.
2. Cliquez sur le bouton `Plus d'actions` de la barre d'outils.
3. Choisissez `Envoyer un rappel`.

![Le menu Plus d'actions d'une facture avec Envoyer un rappel](/img/invoices/send-reminder-menu.png)

Une fenêtre de confirmation affiche le numéro de la facture et les adresses qui recevront la relance.

![La fenêtre Envoyer le rappel de paiement avec le destinataire et le bouton de confirmation](/img/invoices/send-reminder-modal.png)

Cliquez sur `Envoyer le rappel` pour l'envoyer aussitôt.

:::warning
La facture doit avoir au moins un contact avec une adresse e-mail. Sans contact, `Envoyer un rappel` n'apparaît pas.
:::

## Après la dernière relance

Après la relance des 14 jours, Augias cesse les relances automatiques pour cette facture et envoie une **notification d'escalade** à vos utilisateurs : le cycle automatique est terminé et il faut prendre le relais, par exemple en appelant le client, en proposant un échéancier ou en décidant de la suite.

Les relances manuelles restent possibles à tout moment, même après la fin du cycle automatique.
