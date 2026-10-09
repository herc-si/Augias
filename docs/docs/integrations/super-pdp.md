---
title: Connexion d'un compte SUPER PDP
description: Permettre aux entreprises de relier leur compte SUPER PDP à Augias en quelques clics, sans coller d'identifiants d'API.
sidebar_position: 5
---

# Connexion d'un compte SUPER PDP

Augias envoie les factures électroniques par [SUPER PDP](https://www.superpdp.tech), une plateforme agréée française (PA/PDP). Par défaut, chaque entreprise crée une application dans son propre compte SUPER PDP et colle son identifiant client et son secret dans Augias.

Si votre installation enregistre sa propre application auprès de SUPER PDP, les entreprises connectent plutôt leur compte. Elles sont envoyées sur SUPER PDP, où elles se connectent ou créent un compte, font vérifier l'entreprise et donnent l'accès à Augias. Elles ne copient jamais d'identifiants.

L'intégration est facultative. Sans application configurée, les réglages SUPER PDP demandent l'identifiant client et le secret comme avant.

## Créer l'application sur SUPER PDP

1. Dans votre compte SUPER PDP, créez une application OAuth.
2. Donnez-lui comme adresse de redirection le retour SUPER PDP de votre installation :

   ```text
   https://votre-domaine-augias.example/electronic-invoicing/super-pdp/callback
   ```

   Le chemin est toujours `/electronic-invoicing/super-pdp/callback`. Le protocole et l'hôte sont ceux de l'adresse publique de votre installation.
3. Laissez les portées (scopes) vides, et copiez l'identifiant client et le secret.

:::info
Sur SUPER PDP, un compte de test (bac à sable) et un compte de production sont distincts. Les entreprises connectées par une application de test arrivent dans le bac à sable, et leurs factures ne partent nulle part pour de vrai.
:::

## Configurer Augias

Réglez deux variables d'environnement, puis redémarrez l'application :

| Variable | Description |
| --- | --- |
| `AUGIAS_SUPER_PDP_CLIENT_ID` | L'identifiant client de l'application. |
| `AUGIAS_SUPER_PDP_CLIENT_SECRET` | Le secret de l'application. |

Les deux doivent être renseignées pour que la connexion soit proposée aux entreprises. Si l'une est vide, les champs identifiant et secret reviennent.

Pour le paquet de distribution et les installations depuis les sources, ajoutez les valeurs au fichier `.env` à la racine de l'application :

```ini title=".env"
AUGIAS_SUPER_PDP_CLIENT_ID=votre-identifiant-client
AUGIAS_SUPER_PDP_CLIENT_SECRET=votre-secret
```

:::warning
Les jetons des entreprises connectées sont chiffrés avec le secret de l'application (`AUGIAS_APP_SECRET`). Si vous changez ce secret, chaque entreprise devra reconnecter son compte.
:::

## Connecter le compte d'une entreprise

1. Dans le menu latéral, cliquez sur `Facturation électronique`.
2. À côté de SUPER PDP, cliquez sur `Configurer`, donnez un nom au fournisseur, puis cliquez sur `Continuer vers SUPER PDP`.
3. Sur SUPER PDP, connectez-vous ou créez le compte de l'entreprise. L'e-mail de l'utilisateur et le SIREN de l'entreprise sont préremplis quand Augias les connaît.
4. Terminez la vérification de l'entreprise et donnez l'accès à Augias.

SUPER PDP renvoie l'utilisateur vers Augias, qui affiche `Compte SUPER PDP connecté.` Si l'entreprise attendait encore sa vérification, Augias le signale : aucune facture ne part tant que SUPER PDP n'a pas vérifié l'entreprise.

Le premier fournisseur configuré par une entreprise devient le fournisseur actif, qui envoie ses factures.

## Reconnecter

Ouvrez le fournisseur SUPER PDP depuis `Facturation électronique`. La carte `Compte SUPER PDP` indique si le compte est connecté.

- Pour passer à un autre compte SUPER PDP, cliquez sur `Reconnecter`. L'accès précédent est retiré sur SUPER PDP.
- Si la carte indique `Non connecté`, Augias n'a plus accès au compte et aucune facture ne part. Cliquez sur `Connecter le compte` pour le rétablir.

Une connexion s'arrête quand l'entreprise retire l'accès d'Augias sur SUPER PDP, ou quand le compte reste inutilisé un an. Augias interroge chaque compte actif toutes les heures, à condition que son [processus de fond](../installation-guide/distribution-package/cron-job-setup.md) tourne : une connexion active ne s'éteint donc pas faute d'usage.

Supprimer le fournisseur dans Augias retire aussi son accès sur SUPER PDP.

## Dépannage

### SUPER PDP refuse l'adresse de redirection

L'adresse de redirection de l'application SUPER PDP ne correspond pas exactement à celle qu'envoie Augias. Comparez le protocole, l'hôte et le chemin avec l'adresse publique de votre installation.

### « Cette connexion à SUPER PDP a expiré ou a déjà servi. »

L'utilisateur est revenu de SUPER PDP plus d'une heure après avoir quitté Augias, dans un autre navigateur, ou a rechargé la page de retour. Recommencez depuis `Facturation électronique`.

### Les champs identifiant et secret apparaissent encore

Les deux variables doivent être renseignées et non vides. Après les avoir changées, videz le cache de l'application avec `bin/console cache:clear`.
