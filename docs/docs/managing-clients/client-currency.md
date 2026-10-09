---
title: Devise du client
description: L'effet de la devise de chaque client sur ses devis, ses factures, ses paiements et son crédit.
sidebar_position: 5
---

# Devise du client

Chaque client a une devise : celle dans laquelle vous le facturez. Ses devis, ses factures, ses paiements et son crédit sont tous libellés dans cette devise.

## Où la régler

La devise se choisit dans le formulaire de [création](./create-new-client.md) ou de modification du client, avec la liste `Code de devise`.

La liste propose les codes [ISO 4217](https://fr.wikipedia.org/wiki/ISO_4217) (EUR, USD, GBP, CHF, JPY…). Le choix vide, `Par défaut du système`, s'affiche tant qu'aucune devise n'a été choisie pour le client.

## Devise par défaut ou devise choisie

- **`Par défaut du système`** *(aucun choix)* : le client prend la devise par défaut de votre entreprise au moment où un devis, une facture ou un paiement est créé. Si vous changez plus tard cette devise par défaut, ses prochains documents suivront.
- **Une devise choisie** *(EUR, USD…)* : le client garde cette devise, quelle que soit celle de l'entreprise. Pratique quand la plupart de vos clients paient dans votre devise et que quelques-uns paient dans une autre.

La devise par défaut de l'entreprise se règle dans `Paramètres`, onglet `Société`. Choisissez celle de la majorité de vos clients, puis ne la changez que sur les clients qui font exception.

:::info
Les clients sans devise choisie suivent la devise par défaut *au fil de l'eau*. Si vous passez de l'USD à l'EUR, tous les clients restés sur `Par défaut du système` utiliseront l'EUR pour leurs nouveaux documents. Les factures et devis existants gardent leur devise d'origine.
:::

## Ce que la devise détermine

- **Devis et factures** : le symbole et le code affichés sur le document, l'unité des lignes, des taxes, des remises et du total.
- **Paiements** : chaque paiement enregistré pour le client, y compris ceux qui utilisent son [crédit](./client-credit.md), est dans sa devise.
- **Solde créditeur** : le crédit tenu pour le client est dans sa devise.
- **La liste des clients** : la colonne `Devise` de `/clients` affiche la devise retenue (celle du client, sinon celle par défaut).
- **Indicateurs et totaux** : `Revenu total`, `Impayé` et les totaux par client du tableau de bord et de la fiche sont dans la devise du client.

## Changer la devise d'un client

La devise se change depuis le formulaire de modification du client, mais **avec prudence** :

:::warning
Changer la devise d'un client qui a déjà des factures, des devis ou du crédit ne convertit **aucun** montant passé. Les documents existants gardent leur devise et tout ce qui suit prend la nouvelle. L'historique du client mélange alors deux devises, ce qui complique les rapprochements.

Si vous devez vraiment passer un client à une autre devise, archivez l'ancienne fiche et créez-en une nouvelle dans la nouvelle devise.
:::

## Plusieurs devises dans une entreprise

Une même entreprise peut avoir des clients dans des devises différentes. Les documents et les indicateurs de chaque client restent dans sa devise. Augias **ne convertit pas** d'une devise à l'autre dans les tableaux de bord et les rapports : les totaux sont présentés devise par devise.

Si vous travaillez dans des devises très différentes et voulez des comptes séparés par devise, le plus net est d'utiliser des [entreprises](../companies/overview.md) distinctes, une par devise, et de passer de l'une à l'autre.

## Codes de devise personnalisés

La liste est limitée aux codes ISO 4217 publiés. Les cryptomonnaies, jetons maison et autres codes hors norme ne sont pas pris en charge : le champ vérifie que la valeur fait trois caractères et correspond à une devise connue.
