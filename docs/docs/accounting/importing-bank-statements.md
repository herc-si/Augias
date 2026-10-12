---
title: Importer vos relevés bancaires
description: Importer les relevés téléchargés depuis votre banque et rapprocher chaque opération du paiement qu'elle prouve.
sidebar_position: 5
---

# Importer vos relevés bancaires

Téléchargez vos relevés sur le site de votre banque et importez-les dans Augias, puis rapprochez chaque opération de la facture ou de la facture fournisseur qu'elle règle. Augias ne se connecte jamais à votre banque : c'est vous qui choisissez ce que vous importez.

Cliquez sur `Comptabilité` dans le menu latéral, puis sur `Banque`.

## Ajouter un compte bancaire

Dans la carte `Ajouter un compte bancaire`, donnez un nom au compte, éventuellement son IBAN, et sa devise, puis cliquez sur `Ajouter le compte`. Chaque compte a son onglet en haut de la page.

Si vos factures portent déjà des coordonnées bancaires, le formulaire s'ouvre prérempli avec elles. Le compte imprimé sur vos factures est marqué `Sur vos factures`, et un autre peut prendre sa place avec `Mettre sur mes factures` : voir [Coordonnées bancaires](../companies/bank-details.md).

## Importer un relevé

1. Sur le site de votre banque, téléchargez un relevé dans l'un de ces formats :
   - `CAMT.053` (XML), la norme européenne, proposée par la plupart des banques ;
   - `OFX`, souvent présenté comme « Money » ou « Quicken » ;
   - `CSV`, l'export pour tableur.
2. Dans la carte `Importer un relevé`, choisissez le fichier et cliquez sur `Importer`.

Augias indique combien d'opérations ont été ajoutées et combien étaient déjà connues. Importer deux fois le même relevé, ou deux relevés qui se recouvrent, n'ajoute rien en double.

:::tip
Préférez `CAMT.053` ou `OFX` quand votre banque les propose : ils portent la référence de la banque pour chaque opération et le nom de l'autre partie, ce qui fiabilise le rapprochement.
:::

## Rapprocher les opérations

L'onglet `À rapprocher` liste les opérations dont rien dans Augias ne rend encore compte. À côté de chacune, Augias propose ce qu'elle est le plus probablement, parmi les documents de même montant exactement :

- `Paiement déjà enregistré` : un paiement que vous avez déjà saisi. Le rapprochement ne fait que l'y relier.
- `Encaisser la facture` : une facture encore due. Le rapprochement enregistre son paiement par virement à la date retenue par la banque, et la facture passe payée quand il ne reste plus rien à payer.
- `Payer la facture fournisseur` : la même chose pour une facture fournisseur.

Les propositions qui citent le numéro de facture ou le nom de l'autre partie viennent en premier.

Cliquez sur la bonne proposition. Si aucune ne convient, enregistrez le paiement depuis la facture comme d'habitude, ou cliquez sur `Écarter` pour une opération qui n'a rien à rapprocher, comme un virement entre vos propres comptes. `Annuler` remet dans la liste une opération rapprochée ou écartée ; le paiement enregistré n'est pas supprimé.

Les paiements enregistrés ainsi entrent dans vos livres comme les autres, à la date de l'opération bancaire.

:::info
Si votre entreprise facture la TVA et n'a pas choisi de régime fiscal, le paiement d'un client particulier ne peut pas non plus être enregistré depuis la page Banque, pour la raison expliquée dans [Mettre en place la comptabilité](./setting-up-accounting.md#quand-un-régime-est-obligatoire).
:::

## Dépannage

### `Colonnes introuvables dans le CSV`

Augias reconnaît les intitulés de colonnes habituels des exports bancaires français et anglais : une date, un libellé, et soit un montant, soit des colonnes débit et crédit séparées. Certaines banques utilisent d'autres intitulés. Téléchargez plutôt la version `CAMT.053` ou `OFX` du relevé, ou renommez les colonnes dans un tableur avant l'import.

### `Ce relevé est en USD, le compte en EUR`

Le fichier appartient à un autre compte. Importez-le dans le compte de cette devise, ou créez-en un.

### Une opération n'a aucune proposition

Seuls les documents de même montant exactement sont proposés, et un paiement déjà enregistré seulement à dix jours au plus de la date de la banque. Un paiement partiel, ou un virement qui couvre plusieurs factures, s'enregistre depuis les factures elles-mêmes.
