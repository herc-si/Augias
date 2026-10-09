---
title: Avoirs
description: Corriger une facture émise par un avoir, le régler par imputation ou par remboursement, et l'envoyer au client.
sidebar_position: 8
---

# Avoirs

Un avoir corrige une facture déjà émise : une annulation, un retour, une ristourne, un geste commercial ou une erreur. Il porte son propre numéro, se conserve comme une facture et se règle soit en le déduisant d'une facture, soit en remboursant le client.

## Pourquoi un avoir plutôt qu'une annulation

Une facture émise ne se retire pas : elle a pris son numéro dans une suite qui doit rester continue, et le client l'a reçue. Pour l'annuler, même avant tout paiement, on établit un avoir qui s'y rapporte. Seul un brouillon s'annule ou se modifie librement.

Quand un [régime comptable](../accounting/setting-up-accounting.md) est configuré, Augias applique cette règle : une facture en attente ou en retard ne peut plus être annulée ni modifiée, et le message renvoie vers l'avoir.

## Établir un avoir depuis une facture

C'est le cas le plus courant.

1. Ouvrez la facture. Elle doit être en attente, en retard ou payée.
2. Dans le menu `Plus d'actions`, cliquez sur `Établir un avoir`.

Le formulaire s'ouvre déjà rempli : le client, la facture dans `Facture concernée`, le motif `Annulation`, les lignes de la facture et sa remise. Ajustez les lignes pour ne créditer qu'une partie de la facture.

:::info[La remise de la facture d'origine]
Un avoir n'accorde pas de remise : il reprend celle que la facture avait déjà, pour rendre ce qui a réellement été facturé. Une facture de 1 000 € HT remisée de 10 % a coûté 1 080 € TTC au client ; un avoir sans la remise lui rendrait 1 200 €.

Elle ne se saisit pas : le formulaire l'indique sur une ligne, `Remise de la facture d'origine`. Un pourcentage s'applique tel quel aux lignes créditées ; un montant fixe est réparti au prorata des lignes créditées (créditer la moitié de la facture reprend la moitié de la remise). Les débours ne sont jamais remisés.
:::

## Établir un avoir sans facture d'origine

Pour une ristourne ou un geste commercial qui ne se rapporte à aucune facture en particulier :

1. Dans le menu latéral, ouvrez `Avoirs`, puis cliquez sur `Créer un avoir`.
2. Choisissez le client, puis le `Motif` : `Annulation`, `Retour`, `Ristourne`, `Geste commercial` ou `Correction d'erreur`.
3. Laissez `Facture concernée` sur `Aucune facture précise`, ou choisissez une facture. Seules les factures émises du client sont proposées : en attente, en retard ou payées.
4. Ajoutez une ligne par élément crédité.

Les conditions de l'avoir sont préremplies avec le texte des avoirs, pas avec celui des factures. Voir [Conditions par défaut](./default-terms.md#avoirs).

## Enregistrer ou émettre

En bas du formulaire :

- `Enregistrer le brouillon` garde l'avoir modifiable. Un brouillon n'a pas encore de numéro.
- `Émettre` attribue le numéro et fige l'avoir.
- `Émettre et envoyer` fait de même, puis l'envoie par e-mail aux contacts choisis.

Le numéro suit le préfixe et le suffixe réglés dans `Paramètres`, onglet `Avoirs` (par défaut `AV-`, puis le numéro, puis l'année).

:::warning
Un avoir émis ne peut plus être modifié ni supprimé : il se conserve comme une facture. S'il est faux, établissez-en un autre.
:::

À l'émission, le montant de l'avoir s'ajoute au [crédit du client](../managing-clients/client-credit.md).

Si la `Facture concernée` est en attente ou en retard, l'avoir s'y impute aussitôt, pour au plus ce qui reste dû :

- l'avoir couvre tout ce qui reste dû : la facture passe au statut **Créditée**, et les relances s'arrêtent ;
- il en couvre une partie : la facture reste en attente, avec un solde réduit d'autant ;
- il dépasse ce qui reste dû : le surplus reste au crédit du client.

Une facture déjà payée n'est pas touchée : l'avoir reste au crédit du client, à rembourser ou à déduire plus tard.

## Régler un avoir

La page d'un avoir émis affiche un cadre `Règlement` avec le `Reste dû`. Pour enregistrer ce qui en a été fait :

1. Choisissez la `Nature` :
   - `Imputé sur une facture` : le montant est déduit de ce que le client doit sur une autre de ses factures. Choisissez cette facture. Si plus rien n'y reste dû, elle passe **Créditée** (ou **Payée** si elle avait aussi reçu un paiement).
   - `Remboursé` : vous avez rendu l'argent au client. Aucune facture n'est à choisir.
2. Saisissez le `Montant`, au plus le reste dû, la `Date` et, si besoin, des `Notes`.
3. Cliquez sur `Enregistrer`.

Un avoir peut se régler en plusieurs fois. Quand le reste dû tombe à zéro, il passe au statut `Soldé`. Chaque règlement est déduit du crédit du client.

:::info
Dans vos livres, un remboursement est une sortie d'argent : il est enregistré à la date saisie. Une imputation n'est pas enregistrée à part, puisque le paiement qui suit est simplement plus petit. Voir [Vos livres](../accounting/your-books.md).
:::

## Envoyer un avoir

Si vous ne l'avez pas envoyé à l'émission, ouvrez l'avoir et cliquez sur `Envoyer au client`. Le bouton n'apparaît que si l'avoir a au moins un contact. Le PDF est joint à l'e-mail et mentionne la facture concernée.

## Retrouver les avoirs

- `Avoirs` dans le menu latéral liste tous les avoirs avec leur statut : `Brouillon`, `Émis` ou `Soldé`.
- L'onglet `Avoirs` de la fiche d'un client liste les siens.

## Voir aussi

- [Statuts des factures](./invoice-statuses.md)
- [Crédit client](../managing-clients/client-credit.md)
