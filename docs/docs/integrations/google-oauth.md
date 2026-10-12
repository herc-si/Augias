---
title: Connexion avec Google
description: Permettre aux utilisateurs de se connecter à Augias avec leur compte Google.
sidebar_position: 3
---

# Connexion avec Google

Augias peut s'appuyer sur Google comme fournisseur d'identité : les utilisateurs se connectent ou s'inscrivent avec leur compte Google au lieu d'un e-mail et d'un mot de passe. Un utilisateur qui a déjà un compte Augias peut aussi le relier à Google depuis son profil, pour se connecter ensuite de l'une ou l'autre façon.

L'intégration est facultative. Sans client Google configuré, l'application n'utilise que l'e-mail et le mot de passe.

## Ce que cela ajoute

Une fois l'intégration configurée, trois points d'entrée apparaissent :

- un bouton `Se connecter avec Google` sur la page de connexion ;
- un bouton `S'inscrire avec Google` sur la page d'inscription (seulement si l'inscription publique est ouverte, voir [Inscription par Google](#inscription-par-google)) ;
- une ligne `Compte Google` dans `/profile` → `Sécurité`, où un utilisateur connecté relie son compte à une identité Google. Une fois relié, la ligne affiche un badge `Lié`.

Quand un utilisateur termine le parcours Google, Augias procède dans cet ordre :

1. Si un utilisateur Augias a déjà cet identifiant Google, il est connecté sous ce compte.
2. Sinon, si un utilisateur Augias a la même adresse e-mail que le compte Google, l'identifiant Google lui est rattaché et il est connecté.
3. Sinon, si un utilisateur est déjà connecté (liaison depuis le profil), l'identifiant Google est rattaché à cet utilisateur.
4. Sinon, si l'inscription publique est ouverte, un nouvel utilisateur est créé avec l'e-mail et l'état de vérification renvoyés par Google.
5. Sinon, l'authentification est refusée avec un message d'erreur sur la page de connexion.

## Créer un client OAuth Google

Augias a besoin d'un client OAuth 2.0 Google pour dialoguer avec Google.

1. Ouvrez la [Google Cloud Console](https://console.cloud.google.com/) et choisissez (ou créez) un projet.
2. Allez dans `API et services` → `Identifiants`.
3. Configurez l'[écran de consentement OAuth](https://support.google.com/cloud/answer/10311615) si ce n'est pas déjà fait. Choisissez `Externe` pour un usage général, renseignez le nom de l'application, l'e-mail d'assistance et le contact du développeur, et ajoutez les portées `email` et `profile`.
4. Cliquez sur `Créer des identifiants` → `ID client OAuth`.
5. Choisissez `Application Web` comme type d'application et donnez-lui un nom (par exemple *Augias production*).
6. Sous `URI de redirection autorisés`, ajoutez l'adresse de retour OAuth de votre installation :

   ```text
   https://votre-domaine-augias.example/oauth/check/google
   ```

   Le chemin est toujours `/oauth/check/google`. Ajoutez une entrée par environnement (production, préproduction, développement local).
7. Cliquez sur `Créer` et copiez l'`ID client` et le `Code secret du client` générés.

:::warning
L'URI de redirection doit correspondre exactement, protocole (`http`/`https`), hôte et chemin compris. Sinon, Google renvoie une erreur `redirect_uri_mismatch` quand l'utilisateur clique sur le bouton de connexion.
:::

## Configurer Augias

Réglez deux variables d'environnement sur l'instance, puis redémarrez l'application :

| Variable | Description |
| --- | --- |
| `AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID` | L'ID client donné par la Google Cloud Console. |
| `AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET` | Le code secret du client donné par la Google Cloud Console. |

Les deux doivent être renseignées pour activer l'intégration. Si l'une est vide, les boutons Google disparaissent partout.

Avec Docker :

```bash
docker run \
  -e AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID=... \
  -e AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET=... \
  augias/augias
```

Pour le paquet de distribution et les installations depuis les sources, ajoutez les valeurs au fichier `.env` à la racine de l'application :

```ini title=".env"
AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID=1234567890-abcdef.apps.googleusercontent.com
AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET=GOCSPX-votre-secret
```

:::tip
Si les boutons Google n'apparaissent pas après avoir réglé les variables, videz le cache de l'application : `bin/console cache:clear`.
:::

## Se connecter avec Google

Sur la page de connexion, cliquez sur `Se connecter avec Google`. Le navigateur part chez Google, l'utilisateur autorise Augias, et Google le renvoie vers `/oauth/check/google`. Augias connecte l'utilisateur (d'abord par l'identifiant Google, puis par l'e-mail) et l'envoie au choix de l'entreprise.

Les comptes existants sont retrouvés par leur e-mail : un utilisateur inscrit avec e-mail et mot de passe qui clique plus tard sur `Se connecter avec Google` arrive dans son compte habituel, et l'identifiant Google est gardé pour les connexions suivantes.

## Inscription par Google

Quand l'inscription publique est ouverte, la page d'inscription affiche un bouton `S'inscrire avec Google` à côté du formulaire. Il suit le même parcours ; si aucun utilisateur Augias ne correspond à l'e-mail renvoyé, un nouvel utilisateur est créé avec :

- l'adresse e-mail renvoyée par Google ;
- l'état de vérification de l'e-mail selon Google (la vérification Augias est sautée si Google a déjà vérifié l'adresse) ;
- un mot de passe aléatoire jamais affiché : l'utilisateur ne se connecte que par Google jusqu'à ce qu'il choisisse un mot de passe par la réinitialisation.

Si l'inscription publique est fermée, le bouton `S'inscrire avec Google` est masqué, et la connexion Google est refusée pour tout e-mail qui n'a pas déjà de compte Augias.

## Relier un compte existant

Un utilisateur inscrit avec e-mail et mot de passe peut relier son compte à Google depuis son profil. Connecté, ouvrez `/profile`, descendez jusqu'à `Sécurité` et cliquez sur `Se connecter avec Google` sur la ligne `Compte Google`. Au retour de Google, la ligne affiche le badge `Lié`, et l'utilisateur peut se connecter par Google ou avec son mot de passe.

![La partie Sécurité du profil, avec la ligne Compte Google et son bouton](/img/integrations/profile-google-link.png)

:::info
Un compte Augias ne se relie qu'à un compte Google à la fois. Pour changer de compte Google, il faut aujourd'hui délier l'actuel directement en base de données : aucun bouton ne le fait dans l'application.
:::

## Dépannage

### `redirect_uri_mismatch` renvoyé par Google

L'URI de redirection configurée dans la Google Cloud Console ne correspond pas exactement à l'adresse de retour d'Augias. Comparez le protocole, l'hôte et le chemin : le chemin doit être `/oauth/check/google`, le protocole et l'hôte ceux de l'adresse publique de votre installation.

### Les boutons Google n'apparaissent pas sur la page de connexion ou d'inscription

`AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_ID` et `AUGIAS_OAUTH_CLIENT_GOOGLE_CLIENT_SECRET` doivent toutes deux être renseignées et non vides. Après un changement, videz le cache avec `bin/console cache:clear` et rechargez la page.

### L'authentification est refusée au retour de Google

L'inscription publique est fermée et l'e-mail du compte Google ne correspond à aucun utilisateur Augias. Ouvrez l'inscription pour que l'utilisateur soit créé, ou faites créer l'utilisateur avec la même adresse e-mail par un administrateur.
