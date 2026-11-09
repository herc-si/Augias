---
title: Suivre l'e-reporting
description: Voir ce qu'Augias déclare de vos ventes aux particuliers, et ce que votre plateforme en a transmis à l'administration fiscale.
sidebar_position: 7
---

# Suivre l'e-reporting

Une vente à un particulier ne passe pas par la facture électronique : elle est déclarée. Augias transmet chaque vente, avoir, encaissement et remboursement à votre plateforme de facturation électronique. La plateforme les regroupe et les envoie à l'administration fiscale selon le calendrier de votre régime de TVA.

Dans le menu latéral, ouvrez `Comptabilité`, puis `E-reporting`.

:::info
L'e-reporting passe par votre plateforme. Tant qu'aucune n'est branchée, la page affiche `Aucune plateforme branchée` : cliquez sur `Choisir une plateforme`. Voir [Connexion d'un compte SUPER PDP](../integrations/super-pdp.md).
:::

## Ce qui est déclaré

Ce qui fait d'une vente une vente à un particulier, c'est le type de client choisi sur sa fiche : `Particulier`. Voir [Créer un client](../managing-clients/create-new-client.md).

Augias déclare :

- chaque facture émise à un particulier ;
- chaque avoir qui s'y rapporte, et chaque remboursement ;
- chaque encaissement sur une facture d'un particulier.

Pour une facture partie par la facture électronique, Augias signale aussi à la plateforme qu'elle a été payée (`Facture payée (B2B)`). De même, quand vous payez une facture d'achat reçue par la plateforme, il le signale (`Achat payé (B2B)`).

Seuls les documents établis après le branchement de la plateforme sont déclarés. Augias dépose ce qui reste à déclarer toutes les heures. Pour ne pas attendre, cliquez sur `Synchroniser maintenant`.

Sur la page d'une facture ou d'un avoir adressé à un particulier, un cadre `E-reporting` indique où en est sa déclaration : `Déclarée le …`, `Sera déclarée lors du prochain passage, dans l'heure.`, ou le message d'erreur si le dépôt a échoué.

## Période en cours

Le cadre `Période en cours` montre ce que la plateforme transmettra à la prochaine échéance, d'après ce qu'Augias lui a déclaré jusqu'ici :

- `Ventes` : montant HT et TVA par jour et par catégorie (`Biens`, `Services`…).
- `Encaissements` : montant encaissé TTC par jour et par taux de TVA.

Si la plateforme ne répond pas, le cadre affiche `La plateforme n'a pas pu être interrogée :` suivi de sa réponse.

## Déclarations transmises

Le cadre `Déclarations transmises` liste ce que la plateforme a envoyé à l'administration fiscale, avec la période et l'état : `Envoyée à l'administration`, `Reçue`, `Acceptée`, `Rejetée` ou `Illisible`. Il est mis à jour toutes les heures.

La plateforme envoie une déclaration à la fin de chaque période. Avant la première échéance, le cadre reste vide.

:::warning
Une déclaration `Rejetée` ou `Illisible` n'a pas été prise en compte par l'administration. Augias vous prévient une fois par une alerte. Voyez avec votre plateforme ce qui a été refusé.
:::

## Ce qu'Augias a déclaré

Le cadre `Ce qu'Augias a déclaré` liste chaque dépôt : sa date, sa nature (`Vente`, `Avoir`, `Encaissement`, `Remboursement`…), le document concerné et son état, `Déposé` ou `Échec`.

Un dépôt en échec est retenté toutes les heures, ou tout de suite avec `Synchroniser maintenant`.

## Voir aussi

- [Déclarer votre chiffre d'affaires](./declaring-your-turnover.md)
- [Avoirs](../invoices/credit-notes.md)
