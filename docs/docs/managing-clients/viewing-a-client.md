---
title: Consulter un client
description: "La fiche d'un client : synthèse financière, contacts, adresses, crédit, et ses devis, factures et avoirs."
sidebar_position: 3
---

# Consulter un client

La fiche d'un client (`/clients/view/{id}`) réunit tout ce qui le concerne : synthèse financière, contacts, adresses, solde créditeur, et liens vers ses devis, factures, avoirs et paiements.

Vous y arrivez après avoir créé un client, et depuis chaque lien qui porte son nom.

![La fiche d'un client avec l'en-tête, les indicateurs, le crédit et l'onglet Infos](/img/managing-clients/client-view-overview.png)

## En-tête

En haut de la page :

- le **nom** du client en titre, avec un badge actif ou archivé ;
- sous le nom : le site web (s'il est renseigné), la devise et le numéro de TVA, chacun précédé d'une petite icône ;
- à droite, les boutons `Créer un devis` et `Créer une facture`, et le menu `Plus d'actions` avec `Modifier` (ouvre [le formulaire](./create-new-client.md) rempli avec la fiche) et `Supprimer`.

## Indicateurs

Quatre cartes résument votre relation avec ce client :

- `Revenu total` : les paiements reçus de ce client, dans sa devise.
- `Impayé` : le solde impayé de ses factures ouvertes.
- `Factures` : le nombre de factures, avec le détail des payées et des impayées.
- `Devis` : le nombre de devis que vous lui avez adressés.

`Revenu total` s'affiche en vert et `Impayé` en rouge, pour se lire d'un coup d'œil.

## Solde créditeur

Sous les indicateurs, une carte affiche le solde créditeur du client et un bouton `Ajouter un crédit`. Le crédit est un solde que vous tenez sur le compte du client et qu'il utilise plus tard pour payer une facture. Voir [Crédit client](./client-credit.md).

## Onglets

Le bas de la page a des onglets :

- `Infos` *(par défaut)* : les cartes `Contacts` et `Adresses`.
- `Devis` : tous les devis de ce client, avec statut et total. Leur nombre figure sur l'onglet.
- `Factures` : toutes ses factures, avec statut et total.
- `Avoirs` : tous ses [avoirs](../invoices/credit-notes.md).
- `Paiements` : n'apparaît que si au moins un paiement a été enregistré pour ce client.

Ces listes ont les mêmes actions que les pages Devis, Factures et Paiements. Depuis la fiche, vous consultez les listes et ouvrez chaque document ; vous ne modifiez pas les factures ou les devis sur place.

## Contacts

La carte `Contacts` de l'onglet `Infos` liste toutes les personnes enregistrées pour ce client. Chaque contact affiche :

- son nom (prénom et nom), avec une icône crayon pour le modifier ;
- en dessous, chaque coordonnée supplémentaire avec son type (par exemple `E-mail`, `Mobile`, `Téléphone`), sous forme de lien `mailto:` ou `tel:` quand c'est possible.

Un contact a toujours au moins l'un de ces champs : `Prénom`, `Nom`, `E-mail`.

Pour ajouter un contact, cliquez sur `Ajouter un contact` en haut à droite de la carte : le même formulaire qu'à la création s'ouvre. Renseignez nom, e-mail et coordonnées, puis enregistrez.

Pour supprimer un contact, modifiez-le et utilisez la suppression du formulaire. Augias refuse de supprimer le dernier contact : un client en a toujours au moins un.

### Coordonnées supplémentaires

Le bloc `Coordonnées supplémentaires` de chaque contact accepte autant de couples `Type` + `Valeur` que nécessaire.

Une installation neuve propose trois types :

- e-mail : obligatoire, vérifié comme une adresse e-mail ;
- mobile : facultatif, texte libre ;
- téléphone : facultatif, texte libre.

Les types sont communs à tous les contacts de l'entreprise. Ajouter un type (par exemple un fax) demande pour l'instant une intervention directe en base de données : aucun écran ne gère cette liste.

## Adresses

La carte `Adresses` de l'onglet `Infos` liste les adresses du client. Chacune affiche l'adresse mise en forme (rue, ville, région, code postal, pays) et trois commandes : `Modifier` (crayon), `Supprimer` (corbeille) et `Voir sur la carte`, qui ouvre l'adresse dans un service de cartographie.

Cliquez sur `Ajouter une adresse` pour en saisir une nouvelle. Les champs sont ceux de la création : voir [Créer un client → Adresses](./create-new-client.md#adresses).

Les adresses ne sont pas typées facturation ou livraison. Sur un nouveau document, la première sert par défaut ; vous pouvez en choisir une autre sur le document.

## Modifier le client

`Plus d'actions` → `Modifier` met à jour les informations du client (nom, site web, devise, identifiants). Le formulaire est celui de la création, déjà rempli. Les contacts et les adresses se gèrent directement sur la fiche, sans ouvrir ce formulaire.

## Voir aussi

- [Crédit client](./client-credit.md) : ajouter, retirer et utiliser du crédit.
- [Devise du client](./client-currency.md) : l'effet de la devise du client sur les devis, les factures et les paiements.
