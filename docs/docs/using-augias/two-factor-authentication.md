---
title: Authentification à deux facteurs
description: Protéger votre compte Augias par une seconde vérification à la connexion.
sidebar_position: 2
---

# Authentification à deux facteurs

L'authentification à deux facteurs ajoute une seconde vérification après le mot de passe. Même si quelqu'un connaît votre mot de passe, il lui faut encore votre téléphone ou votre messagerie pour se connecter.

## Ouvrir les réglages

Dans le menu latéral, cliquez sur votre nom ou votre avatar pour ouvrir le menu du profil, puis choisissez `Authentification à deux facteurs`. L'adresse directe est `/profile/2fa`.

## Choisir une méthode

Augias propose deux méthodes indépendantes. Vous pouvez activer l'une, l'autre ou les deux.

### Par e-mail

Augias vous envoie un code à 6 chiffres à chaque connexion, à l'adresse e-mail de votre compte.

Cliquez sur `Activer` sous **Authentification par e-mail**. Le badge à côté de la méthode passe à `Activé`.

### Par application d'authentification (TOTP)

Vous générez les codes à 6 chiffres dans une application comme Google Authenticator, Authy ou toute application compatible TOTP, sans connexion internet une fois configurée.

1. Cliquez sur `Activer` sous **Application d'authentification**. La fenêtre **Configurer l'application d'authentification** s'ouvre.
2. Ouvrez votre application et scannez le QR code affiché. Si vous ne pouvez pas le scanner, cliquez sur `Impossible de scanner ? Saisissez-le manuellement` pour afficher la clé secrète et tapez-la dans l'application.
3. L'application affiche un code à 6 chiffres. Saisissez-le dans le champ `Saisissez le code à 6 chiffres depuis votre application`.
4. Cliquez sur `Vérifier et activer`.

## Codes de secours

Quand vous activez une méthode, Augias génère une série de codes de secours à usage unique, pour le jour où vous n'avez plus accès à votre téléphone ou à votre messagerie.

La partie **Codes de secours** indique combien il en reste. Vous pouvez :

- **Voir les codes** : afficher les codes restants ;
- **Télécharger** : les enregistrer dans un fichier texte (`augias-backup-codes-AAAA-MM-JJ.txt`), à garder en lieu sûr ;
- **Régénérer les codes** : invalider tous les codes existants et en créer une nouvelle série.

:::warning
Chaque code de secours ne sert qu'une fois. Régénérer les codes invalide définitivement ceux que vous n'avez pas utilisés.
:::

## Se connecter avec la double authentification

Après le mot de passe, une page de vérification s'affiche. Saisissez le code à 6 chiffres reçu par e-mail ou affiché par votre application.

Cochez `Faire confiance à cet appareil pendant 30 jours` sur un ordinateur personnel que vous utilisez souvent : aucun code ne vous sera demandé sur cet appareil pendant 30 jours.

### Avec un code de secours

Sur la page de vérification, choisissez l'autre méthode et saisissez un code de secours à la place du code à 6 chiffres.

## Appareils de confiance

Si vous avez fait confiance à un appareil à la connexion, la partie **Appareil de confiance** apparaît dans les réglages. Cliquez sur `Révoquer la confiance` pour que la double authentification soit de nouveau demandée sur cet appareil.

## Désactiver la double authentification

Cliquez sur `Désactiver` à côté de la méthode à couper. Si vous désactivez toutes les méthodes, vos codes de secours sont effacés aussi.

## Voir aussi

- [Votre profil](./user-profile.md)
- [Connexion avec Google](../integrations/google-oauth.md)
