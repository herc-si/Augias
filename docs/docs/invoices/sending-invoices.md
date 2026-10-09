---
title: Envoyer, imprimer et télécharger une facture
description: Envoyer une facture par e-mail, la télécharger en PDF ou l'imprimer depuis Augias.
sidebar_position: 3
---

# Envoyer, imprimer et télécharger une facture

Une facture prête peut partir chez le client par e-mail, être téléchargée en PDF ou imprimée. Les trois se font depuis la page de la facture.

## Envoyer une facture par e-mail

Cliquez sur `Envoyer au client` dans la barre d'outils de la facture.

![La barre d'outils d'une facture en attente avec le bouton d'envoi](/img/invoices/invoice-view-pending.png)

Ce bouton :

1. finalise la facture si c'était encore un brouillon : elle prend son numéro et passe **En attente** ;
2. l'envoie par e-mail aux contacts choisis.

L'e-mail contient un lien pour consulter et payer la facture en ligne, et le PDF de la facture en pièce jointe.

:::info
L'objet de l'e-mail se règle dans `Paramètres`, onglet `Factures`. Laissé vide, il suit la langue de votre entreprise. Le repère `{id}` insère le numéro de facture, par exemple `Facture {id} de Acme`.

La même page permet d'indiquer une adresse en copie cachée, qui reçoit une copie de chaque e-mail de facture.
:::

### Envoyer de nouveau

Si le client n'a pas reçu le premier e-mail ou demande une copie, cliquez de nouveau sur `Envoyer au client`. La facture doit être **En attente** ou **En retard**. Un nouvel envoi ne remet pas à zéro le calendrier des relances.

### Relance manuelle

Pour relancer sans renvoyer toute la facture, choisissez `Envoyer un rappel` dans le menu `Plus d'actions`. Voir [Relances de paiement](./payment-reminders.md).

## Télécharger le PDF

Cliquez sur le bouton `PDF` de la barre d'outils pour télécharger la facture.

![Le PDF d'une facture avec l'entreprise, le numéro, le client et les lignes](/img/invoices/invoice-pdf.png)

Le PDF comprend :

- le nom et les coordonnées de votre entreprise ;
- le numéro, la date de facture et l'échéance ;
- le montant dû, mis en évidence ;
- le nom du client, son numéro de TVA, son adresse et son e-mail ;
- les lignes avec prix, quantité et totaux ;
- le sous-total, le détail de la TVA et le total ;
- le lien de paiement, si la facture n'est pas payée ;
- vos conditions, s'il y en a ;
- un filigrane en diagonale qui rappelle le statut de la facture.

:::tip
Le PDF est produit par le serveur à chaque téléchargement : il est toujours à jour. Après une modification, téléchargez-le de nouveau.
:::

## Imprimer

Cliquez sur le bouton `Imprimer` (icône d'imprimante) pour ouvrir la fenêtre d'impression du navigateur.

Vous pouvez imprimer sur papier, ou choisir « Imprimer en PDF » dans votre système, en alternative au téléchargement du PDF.
