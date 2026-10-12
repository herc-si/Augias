---
title: Crédit client
description: Tenir un solde créditeur sur le compte d'un client et l'utiliser pour payer ses factures.
sidebar_position: 4
---

# Crédit client

Le crédit client est un solde tenu sur le compte d'un client, qu'il peut utiliser plus tard pour payer l'une de ses factures au lieu d'un nouveau paiement. Il vient surtout des [avoirs](../invoices/credit-notes.md) émis, des trop-perçus et des factures annulées après paiement.

Chaque client a un seul solde créditeur, dans sa devise.

## Où le trouver

Sur la fiche du client (`/clients/view/{id}`), la carte `Solde créditeur` se trouve sous les indicateurs financiers :

![La carte Solde créditeur avec le solde et le bouton Ajouter un crédit](/img/managing-clients/client-view-overview.png)

Elle affiche toujours le solde du moment, à zéro tant qu'aucun crédit n'a été ajouté.

## D'où vient le crédit

Le solde bouge tout seul dans ces cas :

- **Avoir émis** : le montant de l'avoir s'ajoute au solde, puis chaque règlement de l'avoir (imputation ou remboursement) le retire.
- **Trop-perçu** : quand un client paie plus que le montant d'une facture, la différence s'ajoute au solde.
- **Facture annulée après paiement** : les paiements déjà enregistrés deviennent du crédit.

## Ajouter du crédit à la main

Cliquez sur `Ajouter un crédit` dans la carte pour ouvrir la fenêtre :

![La fenêtre Ajouter un crédit avec le champ Montant et l'astuce sur les montants négatifs](/img/managing-clients/add-credit-modal.png)

- `Montant` *(obligatoire)* : le montant à ajouter, dans la devise du client.
- L'astuce sous le champ rappelle que, pour soustraire un montant, il suffit d'ajouter un « - » devant.

Cliquez sur `Enregistrer`. La fenêtre se ferme et la carte affiche aussitôt le nouveau solde.

:::warning[Ceci n'est pas un paiement]
Un crédit accordé ici n'est pas de l'argent encaissé : il n'entre ni dans vos recettes ni dans vos livres. Si le client vous a réellement versé quelque chose (espèces, acompte, virement), enregistrez plutôt un paiement sur sa facture, sinon cette somme n'est comptée nulle part.
:::

## Retirer du crédit

Utilisez la même fenêtre avec un montant négatif : `-20` retire 20 du solde.

:::warning
Augias ne vous empêche pas de passer sous zéro de cette façon. Si vous retirez plus que le solde, il devient négatif. Il n'y a pas d'autre moyen de réduire le crédit à la main.
:::

## Utiliser le crédit sur une facture

Le crédit s'utilise **au moment du paiement**, jamais automatiquement à la création d'une facture. Pour payer une facture avec le crédit du client :

1. Ouvrez la facture à régler.
2. Cliquez sur `Payer maintenant`.
3. Dans le formulaire de paiement, choisissez le moyen de paiement `Crédit`.
4. Saisissez le montant, au plus le plus petit du solde de la facture et du crédit disponible, puis validez.

Une fois le paiement enregistré, le solde créditeur du client baisse d'autant, et la facture avance vers **Payée** comme avec tout autre paiement.

:::info
Rien n'oblige à utiliser tout le crédit sur une seule facture : payez une partie, gardez le reste sur le compte et utilisez-le plus tard. Une facture peut aussi être payée en partie par le crédit et en partie par un autre moyen (Stripe, PayPal, espèces…), avec deux paiements.
:::

Augias refuse un paiement par crédit qui dépasse le crédit disponible ou le solde de la facture.

## Historique du crédit

Le solde créditeur est un nombre unique. Augias garde le détail de deux de ses mouvements :

- **Paiements par crédit** : chaque utilisation crée un paiement au moyen `Crédit`, visible dans l'onglet `Paiements` du client et compté dans `Revenu total`.
- **Avoirs** : chaque avoir garde l'historique de ses règlements, dans son cadre `Règlement`.

Les ajustements manuels (fenêtre `Ajouter un crédit`) ne laissent pas de trace au-delà du solde obtenu. Si vous avez besoin d'une piste d'audit, notez-les ailleurs.

## Quand utiliser le crédit

Le crédit sert quand le client **a déjà droit à une somme** qui n'est pas encore rattachée à une facture : un avoir à déduire de ses prochaines factures, un trop-perçu qu'il souhaite garder sur son compte.

Ce n'est **pas** le bon outil pour une remise (utilisez la remise de la facture ou d'une ligne), pour un acompte encaissé (enregistrez un paiement), ni pour une somme que le client vous doit (établissez une facture et laissez-la ouverte).
