---
title: Retrouver les factures générées
description: Retrouver les factures produites par un calendrier récurrent et les gérer comme toute autre facture.
sidebar_position: 4
---

# Retrouver les factures générées

Chaque fois que le planificateur tombe sur une date du calendrier, il crée une vraie facture. Ces factures se comportent exactement comme les autres (envoi, paiement, avoir…), mais Augias garde un lien vers la facture récurrente dont elles viennent.

## Depuis la page de la facture récurrente

Ouvrez la facture récurrente. Dès qu'elle a généré au moins une facture, une carte `Factures générées` apparaît dans la colonne de droite, avec les cinq plus récentes, leur numéro, leur total et leur statut.

![La page d'une facture récurrente active](/img/recurring-invoices/recurring-invoice-view-active.png)

Le compteur `Total généré`, en bas de la carte `Résumé de la facture`, donne le nombre de factures produites par ce calendrier. Chaque entrée de la carte `Factures générées` mène directement à la facture.

Au-delà de cinq factures, un lien sous la liste ouvre la liste des factures filtrée sur cette facture récurrente : pratique pour une action groupée, un export ou l'historique complet.

## Travailler avec les factures générées

Une facture générée est une facture Augias ordinaire. Vous pouvez :

- l'envoyer au client ;
- enregistrer ses paiements ;
- la corriger par un [avoir](../invoices/credit-notes.md) ;
- l'annuler ou l'archiver indépendamment de la facture récurrente.

Annuler, mettre en pause ou archiver la facture récurrente ne touche **pas** les factures déjà générées : elles gardent leur propre statut.

## Retrouver toutes les factures récurrentes

La liste des factures récurrentes (menu latéral → `Factures récurrentes` → `Récurrentes`) les classe par onglet :

- **`Actives`** : les factures récurrentes actives, en brouillon et en pause ;
- **`Terminées`** : celles qui ont atteint leur condition de fin ;
- **`Archivées`** : celles que vous avez archivées.

Les quatre cartes du haut résument l'activité : `Récurrences actives`, `À venir sous 7 jours`, `Répartition par statut` (actives, brouillons, en pause) et `Total généré` (toutes factures récurrentes confondues).

Les colonnes du tableau (`Client`, `Fréquence`, `Date de début`, `Date de fin`, `Prochaine exécution`, `Statut`, `Total`, `Taxe`, `Remise`) se trient d'un clic sur l'en-tête. Les commandes `Filtres` et `Rechercher` au-dessus du tableau affinent la liste.
