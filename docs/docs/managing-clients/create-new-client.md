---
title: Créer un client
description: Ajouter un client dans Augias avec ses informations, ses identifiants, ses contacts et ses adresses.
sidebar_position: 2
---

# Créer un client

Ajoutez un client avant de lui adresser des devis ou des factures. Une seule page réunit ses informations, ses identifiants fiscaux, au moins un contact et autant d'adresses que nécessaire.

## Ouvrir le formulaire

Dans le menu latéral, cliquez sur `Clients`, puis `Ajouter un client`, ou allez directement sur `/clients/add`.

![Le formulaire d'ajout d'un client](/img/managing-clients/create-client-form.png)

## Client, fournisseur, ou les deux ?

Cochez `Client`, `Fournisseur`, ou les deux. Une même fiche peut servir aux deux rôles ; il en faut au moins un, et vous pourrez le changer plus tard.

## Informations générales

- **Rechercher dans l'annuaire des entreprises** : tapez le nom, le SIREN ou le SIRET d'une entreprise française. Choisissez-la dans les résultats : le nom, les identifiants et l'adresse du siège se remplissent depuis l'annuaire officiel. Une entreprise fermée est signalée `Fermée`.
- **Nom** : le nom ou la raison sociale du client, repris partout où il apparaît (factures, devis, liste, recherche). **Pour un particulier, laissez-le vide** : il sera rempli avec le nom du contact. C'est ce qui fait de la fiche celle d'un particulier plutôt que d'un professionnel.
- **Site web** *(facultatif)* : l'adresse complète, affichée comme lien sur la fiche.
- **Code de devise** *(facultatif)* : la devise dans laquelle vous facturez ce client. `Par défaut du système` reprend la devise de votre entreprise. Voir [Devise du client](./client-currency.md).

:::info
Professionnel ou particulier, la différence compte : les [conditions par défaut](../invoices/default-terms.md) ne sont pas les mêmes, et un particulier n'a pas à fournir de SIRET pour la facturation électronique.
:::

## Contacts

Un client a **au moins un contact**. Pour chacun :

- `Prénom` *(obligatoire)*
- `Nom` *(facultatif)*
- `E-mail` *(obligatoire)*

Sous ces champs, `Coordonnées supplémentaires` permet d'ajouter d'autres moyens de joindre le contact (téléphone, mobile…), chacun sous la forme `Type` + `Valeur`. Les types disponibles et leurs règles sont décrits dans [Consulter un client → Contacts](./viewing-a-client.md#contacts).

Cliquez sur `Ajouter un contact` pour en ajouter un autre, et sur `Supprimer` pour retirer une ligne avant d'enregistrer. Après l'enregistrement, les contacts se gèrent aussi depuis la fiche du client.

## Identifiants fiscaux

`SIREN`, `SIRET` et `N° de TVA intracommunautaire` figurent sur les factures et les devis adressés au client, et servent à lui adresser ses factures électroniques. Laissez-les vides pour un particulier.

Sous `Autres identifiants`, `Ajouter un identifiant` ajoute une adresse électronique de facturation (quand ce n'est pas le SIREN), un RCS, un code APE/NAF ou un autre identifiant. Voir [Identifiants fiscaux des clients](../taxes/client-tax-identifiers.md).

## Adresses

Les adresses sont facultatives. Ajoutez-en si vous voulez une adresse de facturation ou de livraison sur les factures et les devis du client.

Cliquez sur `Ajouter une adresse`. Chaque adresse comporte :

- `Adresse 1`, `Adresse 2`, `Ville`, `Région`, `Code postal` : texte libre, facultatif ;
- `Pays` : une liste déroulante (`Sélectionner un pays`).

Les adresses ne sont pas typées facturation ou livraison : c'est une simple liste, et la première sert par défaut sur les nouveaux documents.

## Enregistrer

Cliquez sur `Enregistrer` en bas du formulaire. Augias enregistre le client avec ses contacts et ses adresses, affiche un message de confirmation et vous emmène sur [la fiche du client](./viewing-a-client.md).

:::tip
Vous pouvez laisser les adresses vides et les ajouter plus tard depuis la fiche du client, comme les contacts supplémentaires.
:::

## Ensuite

Le nouveau client apparaît dans la [liste des clients](./client-list.md) et peut être choisi sur les nouveaux devis et factures. Sa fiche, où vous arrivez après l'enregistrement, permet d'ajouter du crédit, de modifier les informations ou de lui établir sa première facture.
