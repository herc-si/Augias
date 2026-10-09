---
title: Déclarer votre chiffre d'affaires
description: Suivre votre chiffre d'affaires face aux seuils de votre régime et préparer les montants à déclarer.
sidebar_position: 4
---

# Déclarer votre chiffre d'affaires

Augias calcule ce que vous avez à déclarer et ce que cela vous coûtera. Il ne déclare rien à votre place : vous recopiez les montants sur le site de l'organisme collecteur, puis vous enregistrez ici que c'est fait.

## Où vous en êtes cette année

Cliquez sur `Comptabilité` dans le menu latéral. La carte du chiffre d'affaires, titrée avec l'année (`Chiffre d'affaires 2026`), montre ce que vous avez encaissé depuis le 1er janvier, par activité quand il y en a plusieurs, et une barre pour chaque seuil qui a quelque chose à mesurer.

Trois seuils s'appliquent en micro-entreprise :

| Seuil | Signification |
| --- | --- |
| `Plafond du régime` | Dépassé deux années de suite, il vous fait sortir du régime micro. |
| `Seuil de TVA` | Dépassé, il vous rend redevable de la TVA. |
| `Seuil de TVA (majoré)` | La tolérance au-dessus du seuil. |

Un seuil marqué `proratisé` a été réduit parce que votre activité a commencé en cours d'année. Cela ne vaut que pour le plafond du régime, jamais pour les seuils de TVA.

:::warning
Les taux et les seuils fournis avec Augias n'ont pas tous été vérifiés auprès d'une source officielle. Contrôlez-les sur le site de l'organisme collecteur avant d'agir. Chaque écran qui affiche un montant le rappelle.
:::

## Alertes de seuil

Une fois par jour, Augias compare votre chiffre d'affaires de l'année à chaque seuil et lève une alerte la première fois que vous en atteignez 80 %, puis la première fois que vous le dépassez. Les alertes apparaissent sur la page de comptabilité, dans une carte titrée avec l'année (`Alertes de seuil 2026`), et partent par e-mail à ceux qui se sont abonnés à la notification `Seuil de chiffre d'affaires atteint` dans leur profil.

Chaque étape n'est signalée qu'une fois par an. Repasser sous un seuil n'efface rien : le chiffre d'affaires annuel ne baisse pas, et le franchissement a bien eu lieu.

:::info
La vérification quotidienne est une tâche planifiée. Avec Docker et le binaire, elle tourne seule ; dans une installation manuelle, mettez en place le [processus de fond](../installation-guide/distribution-package/cron-job-setup.md), sinon les alertes ne partiront jamais.
:::

## Ouvrir une déclaration

Cliquez sur `Déclarations` en haut de la page de comptabilité. La liste montre chaque période de l'année en cours avec :

- `Livres` : la période est ouverte ou clôturée ;
- `Déclaration` : `Non calculée`, `Brouillon`, `Prête à déclarer` ou `Déclarée` ;
- `À payer` : ce que la période vous coûtera.

La liste porte sur les périodes, pas sur les déclarations : un trimestre clôturé puis oublié apparaît comme non déclaré.

Cliquez sur `Ouvrir` sur une période pour voir ses montants.

## Les montants à déclarer

La déclaration détaille ce que vous devez au lieu de donner un seul total, parce que c'est ainsi que le formulaire de l'organisme collecteur le demande :

| Colonne | Signification |
| --- | --- |
| `Ligne` | Le prélèvement : `Cotisations sociales`, `Contribution à la formation professionnelle`, `Versement libératoire de l'impôt sur le revenu`. |
| `Base` | Le chiffre d'affaires auquel le taux s'applique. |
| `Taux` | Le pourcentage appliqué. |
| `Montant` | Ce que coûte la ligne. |

Le `Chiffre d'affaires de la période` figure au-dessus et le `Total dû` en dessous. Quand l'ACRE s'applique, la ligne sociale devient `Cotisations sociales (exonération ACRE appliquée)`.

Une période sans encaissement le dit : *Aucun encaissement sur cette période : il n'y a rien à déclarer.*

## Déclarer, puis l'enregistrer

Tant que la période est ouverte, la déclaration est un `Brouillon` qui se met à jour au fil des écritures, et la page indique *La période est encore ouverte : clôturez-la pour figer les montants avant de déclarer.*

[Clôturez la période](./closing-a-period.md) : la déclaration passe `Prête à déclarer`. La carte `Déclaration` propose alors `Déclarer en ligne`, qui ouvre le site de l'organisme collecteur, et un court formulaire :

1. Recopiez les montants sur ce site.
2. Saisissez la `Référence` qu'il vous a donnée.
3. Ajoutez des `Notes` si vous le souhaitez.
4. Cliquez sur `Enregistrer la déclaration`.

:::info
L'enregistrement d'une déclaration est sans retour. La déclaration devient la trace de ce que vous avez réellement envoyé, et Augias cesse de la recalculer : des écritures ultérieures ou un changement de taux ne peuvent jamais réécrire une déclaration déjà faite.
:::

## Voir aussi

- [Clôturer une période](./closing-a-period.md)
- [Mettre en place la comptabilité](./setting-up-accounting.md)
