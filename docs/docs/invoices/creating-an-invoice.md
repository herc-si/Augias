---
title: Créer une facture
description: Créer une facture dans Augias et l'envoyer à un client.
sidebar_position: 1
---

# Créer une facture

Pour créer une facture, ouvrez `Factures de vente` dans le menu latéral et cliquez sur `Créer une facture`.

![La liste des factures avec le bouton de création](/img/invoices/invoice-list.png)

## Choisir le client

Commencez par indiquer à qui s'adresse la facture. Deux possibilités :

- `Client existant` : choisir un client dans la liste ;
- `Nouveau client` : le créer sur place, en saisissant son nom, le nom du contact et son adresse e-mail. Le client est enregistré automatiquement.

![Le formulaire de facture en mode nouveau client, avec les champs du client et du contact](/img/invoices/create-invoice-new-client.png)

## En-tête de la facture

Le client choisi, renseignez l'en-tête :

| Champ | Obligatoire | Description |
| --- | --- | --- |
| **Date de facture** | Oui | La date d'émission. Aujourd'hui par défaut. |
| **Date d'échéance** | Non | La date limite de paiement. Laissez vide s'il n'y en a pas. |
| **Remise** | Non | Une remise sur toute la facture : saisissez une valeur et choisissez `%` pour un pourcentage, ou le symbole de la devise pour un montant. |

La facture n'a pas encore de numéro : il lui est attribué à la finalisation, dans la suite réglée dans les paramètres. Un brouillon affiche `Numéro attribué à la finalisation`.

![Le formulaire de facture avec l'en-tête et la section des lignes vide](/img/invoices/create-invoice-form.png)

## Lignes

Une facture compte au moins une ligne. Le formulaire s'ouvre avec une ligne vide ; cliquez sur `Ajouter un article` pour en ajouter.

Chaque ligne comporte :

| Champ | Description |
| --- | --- |
| **Description** | Le service ou le produit facturé. Plusieurs lignes de texte possibles. |
| **Prix** | Le prix unitaire. |
| **Quantité** | `1` par défaut. Jusqu'à six décimales, pour facturer exactement des fractions d'heure, une consommation ou un poids. |
| **Unité** | Ce que compte la quantité : `Unité`, `Heure`, `Jour`, `Mois`, `Kilogramme`, `Litre`, `Mètre` ou `Forfait`. Reprise du produit quand vous l'ajoutez depuis le catalogue. Le PDF l'affiche après la quantité, et la facture électronique la transmet. |
| **TVA** | Un taux de taxe facultatif pour cette ligne. Les taux se gèrent dans `Système` → `Taxes`. |

La colonne **Total** et le cadre **Totaux** à droite se mettent à jour pendant la saisie.

:::info
La taxe s'applique ligne par ligne, et non à la facture entière. Chaque ligne peut avoir son propre taux.
:::

## Conditions et notes

Cliquez sur **Conditions & Notes** en bas du formulaire pour déplier cette section facultative.

![La section Conditions & Notes dépliée](/img/invoices/create-invoice-terms-notes.png)

- **Conditions** : vos conditions de paiement. Ce texte figure sur la facture et le client le voit. Une nouvelle facture s'ouvre avec vos [conditions par défaut](./default-terms.md), rédigées pour un professionnel ou pour un particulier selon le client choisi.
- **Notes** : des notes pour vous seul. Elles n'apparaissent **pas** sur la facture ni sur le PDF.

## Enregistrer la facture

En bas du formulaire :

| Bouton | Effet |
| --- | --- |
| **Enregistrer comme brouillon** | Enregistre la facture sans l'envoyer, au statut **Brouillon**. Vous pourrez la modifier puis la finaliser plus tard. |
| **Enregistrer et envoyer au client** | Finalise la facture (elle prend son numéro et passe **En attente**) et l'envoie par e-mail aux contacts choisis. |
| **Finaliser sans envoyer** | Dans le menu à côté du bouton d'envoi : finalise la facture sans rien envoyer au client. Vous pourrez l'envoyer plus tard. |

:::tip
Gardez le brouillon tant que vous travaillez sur la facture. Une fois finalisée et remise au client, une facture se corrige par un [avoir](./credit-notes.md).
:::
