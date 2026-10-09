---
title: Vos livres
description: Comment le livre des recettes et le registre des achats se remplissent seuls, et comment ajouter une écriture à la main.
sidebar_position: 2
---

# Vos livres

Vos livres obligatoires s'écrivent pour vous. Chaque paiement qu'Augias enregistre (une facture payée, une facture fournisseur réglée) devient une écriture dans le bon livre, à la date où l'argent a bougé.

Cliquez sur `Comptabilité` dans le menu latéral, puis choisissez un livre dans la carte `Livres`.

## Les livres que vous tenez

| Livre | Contenu |
| --- | --- |
| `Livre des recettes` | L'argent reçu. Tout le monde le tient. |
| `Registre des achats` | L'argent versé aux fournisseurs. Tenu seulement quand votre régime l'exige. |

En micro-entreprise, le registre des achats n'est exigé que des activités de revente et d'hébergement : une entreprise dont l'`Activité principale` est `Prestations de services (BIC)` ou `Prestations de services (BNC)` ne voit que le livre des recettes.

:::info
Les livres sont tenus en trésorerie. Une facture entre dans le livre des recettes quand elle est **payée**, pas quand elle est émise : une facture impayée n'est pas du chiffre d'affaires et ne figure nulle part dans vos livres.
:::

## Le contenu d'une écriture

| Colonne | Signification |
| --- | --- |
| `N°` | Le numéro d'ordre de l'écriture. Vide jusqu'à la clôture de la période : voir [Clôturer une période](./closing-a-period.md). |
| `Date` | La date du mouvement d'argent, pas celle de la facture. |
| `Nature` | Ce à quoi correspond l'argent. |
| `Tiers` | Le client ou le fournisseur, sous le nom qu'il avait au moment de l'écriture. |
| `Pièce` | Le numéro de la facture ou du justificatif. |
| `Montant` | Signé : une écriture d'annulation est négative. |

## Les écritures qu'Augias écrit seul

Un paiement enregistré n'importe où dans l'application produit exactement une écriture, qu'il passe par l'écran de paiement, l'API REST, les outils MCP ou une passerelle de paiement. Enregistrer deux fois le même paiement, ou une passerelle qui renvoie sa notification, ne crée pas de seconde écriture.

Une telle écriture le dit quand on l'ouvre : *Cette écriture reflète un paiement enregistré dans l'application. Seules l'activité et les notes sont modifiables ici ; le reste suit le paiement.*

Le champ à vérifier est l'`Activité`. Les écritures automatiques sont rangées sous votre `Activité principale` ; si une recette relève d'une autre activité, changez-la ici : c'est ce qui décide du plafond et du taux de cotisation auxquels elle compte.

Un remboursement s'écrit aussi seul : un paiement remboursé, ou le remboursement d'un [avoir](../invoices/credit-notes.md), produit une écriture négative datée du jour du remboursement. La recette d'origine reste dans le livre, et son annulation à côté.

:::warning
Rembourser un paiement par la passerelle *et* saisir un remboursement sur un avoir pour le même argent l'enregistre deux fois. Ne le faites que d'une des deux façons.
:::

## Ajouter une écriture à la main

Certaines sommes ne passent jamais par une facture ni une facture fournisseur. Cliquez sur `Nouvelle écriture` dans le livre pour les enregistrer.

| Champ | Remarques |
| --- | --- |
| `Date` | La date du mouvement d'argent, pas celle de la facture. |
| `Montant` | |
| `Nature de l'opération` | Ce à quoi correspond l'argent. |
| `Tiers` | Qui vous a payé, ou qui vous avez payé. |
| `Pièce justificative` | Le numéro de la facture ou du justificatif. |
| `Mode de règlement` | `Virement bancaire`, `Chèque`, `Carte bancaire`, `Prélèvement`, `Espèces`, `Paiement en ligne` ou `Autre`. |
| `Activité` | Livre des recettes seulement. |
| `Notes` | |

Les écritures saisies à la main se modifient et se suppriment librement, jusqu'à la clôture de leur période.

:::tip
Pour corriger une écriture déjà scellée, saisissez une écriture à la main avec un `Montant` négatif, et une `Nature de l'opération` qui dit ce qu'elle annule.
:::

## Écritures dans une autre devise

Vos livres sont tenus dans la devise de votre entreprise. Une écriture dans une autre devise est comptée à part et exclue de vos totaux de chiffre d'affaires, avec une remarque sur la page de comptabilité : *Certaines écritures sont enregistrées dans une autre devise et ne sont pas comprises dans ces totaux.*

Rien n'est converti, car les livres n'ont jamais enregistré de taux de change. Convertissez vous-même le montant et saisissez-le dans votre devise s'il doit compter dans votre chiffre d'affaires.

## Voir aussi

- [Clôturer une période](./closing-a-period.md)
- [Déclarer votre chiffre d'affaires](./declaring-your-turnover.md)
- [Statuts des factures](../invoices/invoice-statuses.md)
