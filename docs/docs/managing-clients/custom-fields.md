---
title: Champs personnalisés
description: Ajouter vos propres champs aux clients, aux contacts, aux factures et aux devis.
sidebar_position: 6
---

# Champs personnalisés

Les champs personnalisés gardent des informations en plus sur les clients, les contacts, les factures et les devis : un numéro de compte, un code projet, ou toute autre donnée propre à votre activité.

## Gérer les champs

Ouvrez la page `Champs personnalisés` depuis les paramètres. Un onglet par type de fiche (`Champs client`, `Champs contact`, `Champs facture`, `Champs devis`) liste les champs définis ; vous pouvez en ajouter, les modifier, les réordonner et les supprimer.

## Ajouter un champ

Cliquez sur `Ajouter un champ` pour ouvrir le formulaire.

### S'applique à

Choisissez la fiche sur laquelle le champ apparaît :

- **Client** : formulaires de création et de modification d'un client ;
- **Contact** : ajout et modification d'un contact ;
- **Facture** : formulaires de création et de modification d'une facture ;
- **Devis** : formulaires de création et de modification d'un devis.

### Libellé

Le nom affiché à côté du champ dans l'application et sur les PDF. 125 caractères au plus.

### Type

| Type | Description |
| --- | --- |
| **Texte** | Une ligne de texte |
| **Texte long** | Une zone de texte sur plusieurs lignes |
| **Nombre** | Une valeur numérique |
| **Date** | Un sélecteur de date |
| **E-mail** | Une adresse e-mail, au format vérifié |
| **URL** | Une adresse web, au format vérifié |
| **Case à cocher** | Oui ou non |
| **Choix unique** | Une liste déroulante, un seul choix |
| **Choix multiple** | Une liste déroulante, plusieurs choix |

Pour un choix unique ou multiple, ajoutez les options proposées sous le type avec `Ajouter une option`. Chaque option a un libellé et une valeur générée automatiquement.

Un champ qui a déjà des valeurs ne peut plus changer de type : supprimez-le et recréez-le.

### Obligatoire

Cochez **Obligatoire** pour rendre le champ indispensable à la création et à la modification de la fiche.

### Visibilité

Pour les champs de **facture** et de **devis** seulement :

| Option | Où le champ apparaît |
| --- | --- |
| **Interne uniquement** | Dans l'application seulement : ni sur les PDF, ni sur la page vue par le client |
| **Visible par le client** | Dans l'application, sur les PDF et sur la page vue par le client |

### Valeur par défaut

Facultatif : préremplit les nouvelles fiches avec cette valeur.

## Remplir les champs

Une fois défini, le champ apparaît de lui-même dans les formulaires concernés. Sur les factures et les devis, les valeurs visibles client sont imprimées sur le PDF.

## Réordonner les champs

Faites glisser la poignée à gauche de chaque ligne pour changer l'ordre des champs dans les formulaires et sur les PDF.

## Supprimer un champ

Utilisez l'action de suppression du champ dans la liste. Augias indique combien de fiches ont une valeur pour ce champ avant de confirmer.

:::danger
Supprimer un champ efface sa définition et toutes ses valeurs, sur toutes les fiches. Elles ne peuvent pas être récupérées.
:::

## Des champs propres à chaque entreprise

Chaque entreprise a ses propres champs personnalisés. Un champ créé dans une entreprise n'apparaît pas dans les autres.

## Voir aussi

- [Créer un client](./create-new-client.md)
- [Créer une facture](../invoices/creating-an-invoice.md)
