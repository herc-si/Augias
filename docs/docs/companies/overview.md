---
title: Vue d'ensemble
description: Le fonctionnement des entreprises dans Augias.
sidebar_position: 1
---

# Vue d'ensemble

Dans Augias, une entreprise est un espace de travail indépendant. Tout ce que vous faites dans l'application (ajouter des clients, envoyer des devis, émettre des factures, suivre les paiements) se passe dans une entreprise à la fois.

## Ce que contient une entreprise

Clients, contacts, devis, factures, factures récurrentes, avoirs, paiements, taux de taxe, moyens de paiement, paramètres et modèles appartiennent tous à une seule entreprise. Les données d'une entreprise ne sont jamais visibles depuis une autre, même pour un utilisateur membre des deux.

Chaque entreprise a sa devise. Choisie à la création, elle sert de devise par défaut pour les factures, les devis et les paiements. Chaque client peut en avoir une autre.

## Utilisateurs et entreprises

- Un utilisateur peut créer autant d'entreprises qu'il le souhaite. Il n'y a pas de limite en auto-hébergement.
- Un utilisateur peut être invité dans une entreprise existante, avec un rôle qui fixe ce qu'il peut y faire :
  - **Propriétaire** : tous les droits, y compris fermer l'entreprise et en transférer la propriété ;
  - **Administrateur** : tout sauf fermer l'entreprise (paramètres, membres, facturation, comptabilité) ;
  - **Facturation** : clients, devis, factures, avoirs, paiements et achats, sans paramètres ni comptabilité ;
  - **Comptable** : consulte tout, tient la comptabilité et exporte les données, sans créer ni modifier aucun document.
- On passe d'une entreprise à l'autre dans l'application, sans se déconnecter.

## Pour continuer

- [Créer une entreprise](./creating-a-company.md)
- [Passer d'une entreprise à l'autre](./switching-between-companies.md)
- [Fermer une entreprise](./deleting-a-company.md)
