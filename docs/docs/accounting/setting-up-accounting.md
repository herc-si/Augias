---
title: Mettre en place la comptabilité
description: Choisir votre régime fiscal pour qu'Augias tienne vos livres et calcule ce que vous devez.
sidebar_position: 1
---

# Mettre en place la comptabilité

La comptabilité est inactive tant que vous n'avez pas choisi de régime fiscal. Le régime fixe les livres à tenir, les seuils de chiffre d'affaires qui vous concernent et le calcul de vos cotisations : rien d'autre dans cette section ne fonctionne avant ce choix.

Cliquez sur `Comptabilité` dans le menu latéral. Tant qu'aucun régime n'est choisi, la page affiche `La comptabilité n'est pas encore configurée` et un bouton `Aller aux réglages`.

## Choisir un régime

Dans le menu latéral, dépliez `Système`, cliquez sur `Paramètres` et ouvrez l'onglet `Comptabilité`.

Au départ, seul le `Régime fiscal` compte. Deux régimes sont proposés :

- `France — Micro-entreprise` : comptabilité de trésorerie, avec un livre des recettes, un registre des achats pour les activités de revente, et le chiffre d'affaires déclaré à l'URSSAF.
- `France — Réel normal` : comptabilité de trésorerie avec TVA, avec un livre des recettes, un registre des achats, la TVA collectée sur les ventes et déduite sur les achats, déclarée sur la CA3. Les cotisations de ce régime portent sur le résultat, qu'Augias ne calcule pas : seule la TVA y est établie.

Les autres champs ont des valeurs par défaut qui fonctionnent : vous pouvez enregistrer dès le régime choisi et revenir sur le reste plus tard.

## Quand un régime est obligatoire

Si vous facturez la TVA (`Non assujetti à la TVA` décoché) et que vous avez des clients particuliers, vous devez choisir un régime avant de pouvoir enregistrer leurs paiements.

La loi française considère tout logiciel qui enregistre des paiements hors des livres comme un logiciel de caisse, et impose que les logiciels de caisse soient certifiés (article 286 du Code général des impôts). Augias n'est pas un logiciel de caisse certifié. Dès que vos livres sont tenus dans Augias, chaque paiement enregistré entre aussitôt dans le livre des recettes, sans intervention de quiconque, et l'obligation ne s'applique plus.

Tant qu'aucun régime n'est choisi :

- un client enregistré sans nom d'entreprise, donc particulier, est refusé ;
- l'enregistrement d'un paiement d'un client particulier existant est refusé, à l'écran de paiement, par l'API et par MCP ;
- la carte `Nécessite votre attention` du tableau de bord affiche `Comptabilité à tenir`.

Les clients qui paient en ligne ne sont pas bloqués.

:::info
Rien de tout cela ne s'applique si vous n'êtes pas assujetti à la TVA (franchise en base), ou si tous vos clients sont des professionnels.
:::

## Les champs de l'onglet Comptabilité

| Champ | Rôle |
| --- | --- |
| `Régime fiscal` | Fixe les livres tenus, les seuils applicables et le calcul des cotisations. |
| `Activité principale` | La valeur par défaut des écritures créées automatiquement : `Vente de marchandises`, `Prestations de services (BIC)` ou `Prestations de services (BNC)`. Modifiable sur chaque écriture. |
| `Début d'activité` | Sert à proratiser les seuils d'une première année incomplète et à calculer la durée de l'ACRE. |
| `Périodicité de déclaration` | `Mensuelle` ou `Trimestrielle`. C'est aussi le rythme de clôture de vos livres. |
| `Non assujetti à la TVA` | Supprime la TVA des factures et des devis, et y imprime la mention ci-dessous. |
| `Mention d'exonération de TVA` | Imprimée sur chaque facture et chaque devis tant que vous n'êtes pas assujetti à la TVA. |
| `Option pour le versement libératoire` | Payer l'impôt sur le revenu en pourcentage du chiffre d'affaires avec vos cotisations, plutôt que sur votre déclaration annuelle. |
| `Exonération ACRE` | Réduit le taux des cotisations sociales les premiers mois d'activité. |
| `Caisse de retraite` | `SSI` ou `CIPAV`. Les deux n'appliquent pas le même taux au même chiffre d'affaires BNC. |

:::info
L'`Activité principale` est une valeur par défaut, pas une contrainte. Une entreprise qui vend des marchandises *et* facture des prestations indique l'activité écriture par écriture, et chacune est comparée à son propre plafond.
:::

:::warning
L'`Exonération ACRE` a besoin de la date de `Début d'activité` pour calculer sa durée. Sans cette date, l'exonération ne peut pas s'appliquer.
:::

## Ce qui change une fois le régime choisi

- `Comptabilité` dans le menu latéral affiche votre chiffre d'affaires de l'année, vos livres et la période en cours.
- Chaque paiement enregistré à partir de là s'écrit seul dans le bon livre. Voir [Vos livres](./your-books.md).
- Le chiffre d'affaires est comparé chaque jour aux seuils de votre régime, et vous êtes prévenu la première fois que vous en approchez ou que vous en dépassez un. Voir [Déclarer votre chiffre d'affaires](./declaring-your-turnover.md).

## Reprendre les documents antérieurs

Les factures, avoirs, paiements et factures fournisseurs enregistrés dans Augias *avant* le choix du régime ne sont pas encore dans vos livres. Tant qu'il en manque pour l'exercice en cours, la page `Comptabilité` le signale et propose `Vérifier et reprendre`. Vous pouvez aussi ouvrir cette page à tout moment avec `Reprendre l'historique`, en bas du cadre du régime.

1. Choisissez le jour à partir duquel reprendre. Par défaut, le premier jour de l'exercice en cours. Il ne peut pas être antérieur ou égal à la date de verrouillage de vos livres.
2. Cliquez sur `Afficher` pour lister les écritures qui seraient ajoutées, avec leur date, leur livre, leur document et leur montant.
3. Cliquez sur le bouton d'ajout pour les écrire dans vos livres.

Chaque écriture va dans la période de sa date, comme si elle avait été écrite le jour même. Ce qui est déjà dans vos livres n'est pas touché : relancer la reprise n'ajoute rien.

:::note
Seul ce qui a été enregistré dans Augias est repris. L'argent reçu en dehors (avant que vous utilisiez Augias, ou jamais saisi ici) doit encore être ajouté à la main : voir [Ajouter une écriture à la main](./your-books.md#ajouter-une-écriture-à-la-main).
:::

## Voir aussi

- [Vos livres](./your-books.md)
- [Clôturer une période](./closing-a-period.md)
- [Régler les taux de taxe](../taxes/tax-rates.md)
