---
title: Coordonnées bancaires
description: Saisir une fois votre banque, votre IBAN et votre BIC pour que vos clients règlent vos factures par virement.
sidebar_position: 6
---

# Coordonnées bancaires

Saisissez vos coordonnées bancaires une fois : chaque facture que vos clients doivent encore payer leur indique comment vous régler par virement.

## Saisir vos coordonnées

1. Ouvrez `Paramètres`, onglet `Société`.
2. Dans le cadre `Coordonnées bancaires`, renseignez :
   - `Banque` : le nom de votre banque, par exemple `Crédit Agricole`. Facultatif.
   - `IBAN` : votre numéro de compte. Collez-le avec ou sans espaces ; il est vérifié et conservé par groupes de quatre, comme sur un relevé.
   - `BIC` : l'identifiant de votre banque, 8 ou 11 caractères. Facultatif, mais conseillé pour les virements depuis l'étranger.
3. Cliquez sur `Enregistrer les paramètres`.

Un IBAN mal saisi est refusé à l'enregistrement : la clé de contrôle est vérifiée.

## Où elles apparaissent

Dès qu'une facture est finalisée et jusqu'à son paiement, son PDF porte un cadre `Règlement par virement` près des totaux : votre banque, votre IBAN, votre BIC et le numéro de facture que le client doit indiquer en référence du virement, pour que le paiement retrouve sa facture.

Le cadre n'est pas imprimé sur :

- un brouillon, qui n'a pas encore de numéro ;
- une facture déjà payée ou annulée ;
- les devis et les avoirs.

Une note de débours à payer porte le même cadre, avec son propre numéro en référence.

Quand vous envoyez vos factures par voie électronique, ces coordonnées voyagent aussi dans les données Factur-X comme virement SEPA, pour que le logiciel de votre client prépare seul le paiement.

Laissez l'IBAN vide pour ne rien imprimer.

## Utiliser un compte de la page Banque

Si vous importez vos relevés dans `Comptabilité` › `Banque` :

- le formulaire d'ajout d'un compte est prérempli avec les coordonnées de vos factures, tant qu'aucun compte n'a cet IBAN ;
- le compte dont l'IBAN figure sur vos factures est marqué `Sur vos factures` ;
- sur un autre compte qui a un IBAN, `Mettre sur mes factures` en fait celui qu'impriment vos factures. Son nom devient celui de la banque et, le BIC étant propre à la banque, le BIC est vidé : vérifiez-le dans l'onglet `Société`, où vous êtes conduit.

Seuls les membres autorisés à modifier les paramètres peuvent le faire.

## Dépannage

### Mes coordonnées bancaires ne figurent pas sur une facture

Vérifiez que la facture est finalisée (pas un brouillon) et qu'elle n'est pas encore payée. Vérifiez aussi que l'IBAN est renseigné : sans lui, rien n'est imprimé, même si la banque et le BIC le sont.

### Je saisissais mon IBAN dans l'onglet Design

Votre IBAN et votre BIC ont été déplacés dans l'onglet `Société` avec leurs valeurs. Rien à ressaisir.
