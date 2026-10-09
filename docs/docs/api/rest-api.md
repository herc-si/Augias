---
title: API REST
description: S'authentifier et utiliser l'API REST d'Augias.
sidebar_position: 1
---

# API REST

Augias expose une API REST sous `/api/*` qui reprend l'interface web : clients, factures, devis, paiements, factures récurrentes, taxes, etc. L'authentification passe par un jeton d'API propre à chaque utilisateur. La référence complète des points d'accès est générée automatiquement et servie par votre instance sur `/api/docs`.

:::tip
Vous préférez un langage de requête souple à des points d'accès fixes ? L'[API GraphQL](./graphql.md) donne accès aux mêmes données autrement.
:::

## Créer un jeton d'API

Connectez-vous à Augias et ouvrez `Clés API` dans le menu de votre profil (ou allez directement sur `/profile/api`). Cliquez sur `Créer un jeton` en haut à droite de la liste.

![La page des jetons d'API avec les statistiques, le bandeau d'information, le bouton de création et un jeton existant](/img/api/api-tokens-page.png)

Dans la fenêtre `Créer un nouveau jeton API`, renseignez :

- **`Nom`** *(obligatoire)* : un libellé pour le jeton. Choisissez de quoi reconnaître son usage, par exemple `Intégration comptable` ou `Zapier`.
- **`Description`** *(facultative)* : une note plus longue sur le rôle du jeton.

![La fenêtre de création d'un jeton avec les champs Nom et Description](/img/api/create-token-modal.png)

Cliquez sur `Enregistrer`. La fenêtre affiche alors la valeur du jeton :

![La fenêtre de confirmation avec la valeur du jeton, le bouton de copie et l'avertissement](/img/api/token-created-modal.png)

:::warning
Le jeton n'est affiché **qu'une fois**, juste après sa création. Cliquez sur `Copier` et rangez-le dans un gestionnaire de mots de passe ou le coffre de secrets de votre intégration avant de cliquer sur `J'ai copié le jeton`. S'il est perdu, révoquez-le et créez-en un autre : la valeur d'origine ne peut pas être retrouvée.
:::

## Consulter vos jetons

La liste montre tous vos jetons, avec ces colonnes :

- `Nom`, `Description` : ce que vous avez saisi à la création ;
- `Nombre d'utilisations` : le nombre total de requêtes faites avec ce jeton ;
- `Dernière utilisation` : la date de la dernière requête, vide s'il n'a jamais servi ;
- `Créé le` : la date de création.

Les quatre cartes au-dessus de la liste résument ces données pour tous vos jetons : `Jetons actifs`, `Appels API ce mois-ci`, `Dernière activité` et `Jeton le plus utilisé`.

La liste se recherche et se trie. La valeur du jeton n'est plus jamais affichée après sa création : seul son nom l'est.

## Consulter l'historique des requêtes

Chaque requête authentifiée avec succès par un jeton est enregistrée pour ce jeton. Cliquez sur `Voir l'historique` sur la ligne du jeton pour ouvrir la liste des requêtes.

![L'historique des requêtes avec méthode, point de terminaison, statut, adresse IP et agent utilisateur](/img/api/token-history-modal.png)

Chaque ligne indique :

- `Date` : l'arrivée de la requête ;
- `Méthode` : `GET`, `POST`, `PATCH`, `PUT` ou `DELETE` ;
- `Point de terminaison` : le chemin appelé (par exemple `/api/invoices`) ;
- `Statut` : le code HTTP renvoyé ;
- `Adresse IP` : l'adresse du client au moment de la requête ;
- `Agent utilisateur` : l'en-tête `User-Agent` envoyé par le client.

L'historique se filtre par période, par méthode et par plage de statut, et affiche au plus les 100 requêtes les plus récentes. Les authentifications manquées (sans jeton ou avec un mauvais jeton) **ne sont pas** enregistrées : seules les réussies le sont.

## Révoquer un jeton

Pour révoquer un jeton, cochez-le dans la liste, puis choisissez `Révoquer` dans les actions groupées.

:::warning
La révocation est **immédiate**, sans fenêtre de confirmation. Le jeton est supprimé avec tout son historique. Toute application qui l'utilise reçoit `401 Unauthorized` dès sa requête suivante : mettez vos intégrations à jour avant de révoquer.
:::

Pour remplacer un jeton sans interruption, créez d'abord le nouveau, basculez votre intégration dessus, vérifiez qu'il fonctionne (sa `Dernière utilisation` se met à jour), puis seulement révoquez l'ancien.

## Authentifier les requêtes

Envoyez le jeton dans l'en-tête HTTP `X-API-TOKEN` de chaque requête :

```bash
curl -H "X-API-TOKEN: <votre-jeton>" \
     -H "Accept: application/ld+json" \
     https://votre-instance.example/api/invoices
```

L'API est **sans état** : pas de session, pas de jeton CSRF, pas d'aller-retour de connexion. Envoyez l'en-tête à chaque requête. Un jeton vaut pour un utilisateur *et* une entreprise ; si votre compte appartient à plusieurs entreprises, créez un jeton par entreprise en passant sur chacune dans l'application avant de créer son jeton.

Sans jeton valide, le serveur répond `401 Unauthorized` avec un corps JSON :

```json
{ "message": "No API token provided" }
```

## Formats de réponse

L'API négocie le format par l'en-tête `Accept`. Formats disponibles :

| Valeur d'`Accept` | Format |
| --- | --- |
| `application/ld+json` (défaut) | JSON-LD avec hypermédia Hydra |
| `application/json` | JSON simple |
| `application/hal+json` | JSON HAL |
| `application/vnd.api+json` | JSON:API |
| `application/xml` ou `text/xml` | XML |

Les collections sont paginées par **30 éléments par page** par défaut. Le paramètre `itemsPerPage` change ce nombre :

```bash
curl -H "X-API-TOKEN: <votre-jeton>" \
     "https://votre-instance.example/api/invoices?page=2&itemsPerPage=50"
```

Les erreurs sont renvoyées au format [RFC 7807](https://datatracker.ietf.org/doc/html/rfc7807) `application/problem+json`, avec un `title` et un `detail` lisibles et un `type` exploitable par programme.

## Limites de débit

L'API est limitée à **300 requêtes par minute**, en fenêtre glissante. Le compteur est tenu par jeton pour les requêtes authentifiées, et par adresse IP sinon.

Chaque réponse indique l'état de votre compteur dans ses en-têtes :

| En-tête | Signification |
| --- | --- |
| `X-RateLimit-Limit` | Le budget total par fenêtre (`300`). |
| `X-RateLimit-Remaining` | Les requêtes restantes dans la fenêtre en cours. |
| `X-RateLimit-Reset` | L'horodatage Unix de remise à zéro. |

Au-delà, la réponse est `429 Too Many Requests`, avec un en-tête `Retry-After` et un corps `application/problem+json`. Attendez le nombre de secondes indiqué par `Retry-After` avant de réessayer.

## Référence des points d'accès

L'interface Swagger interactive de votre installation fait foi : elle reflète toujours exactement les ressources et les champs de votre version.

```text
https://votre-instance.example/api/docs
```

Les principales ressources :

- `/api/invoices` et `/api/recurring-invoices`
- `/api/quotes`
- `/api/clients`, `/api/contacts` et `/api/addresses`
- `/api/payments`
- `/api/taxes`
- `/api/api-tokens` (gérer vos propres jetons par l'API)

Toutes les ressources acceptent les verbes habituels : `GET` pour les collections et les éléments, `POST` pour créer, `PATCH` pour modifier, `DELETE` pour supprimer. Les montants sont exprimés dans la **plus petite unité de la devise** (les centimes pour l'euro), et la devise vient du client concerné.

## Dépannage

### `401 Unauthorized` à chaque requête

Le jeton manque, est mal saisi ou a été révoqué. Comparez la valeur de l'en-tête `X-API-TOKEN` avec l'original : des espaces en début ou en fin, ou des guillemets parasites, se glissent souvent lors d'un copier-coller depuis un terminal ou un gestionnaire de mots de passe. Si le jeton ne fonctionne vraiment plus, créez-en un autre et mettez votre intégration à jour.

### `429 Too Many Requests`

Vous avez dépassé 300 requêtes par minute. Lisez l'en-tête `Retry-After` et attendez au moins ce nombre de secondes. Pour une intégration à fort volume, regroupez les requêtes, mettez en cache les lectures fréquentes et étalez les appels sur la fenêtre plutôt que de les enchaîner.

### L'authentification réussit mais la requête est refusée en `403`

Le jeton est valide, mais l'utilisateur n'a pas le droit de faire cette action. Vérifiez que la ressource appartient à l'utilisateur (ou qu'il a le bon rôle dans l'entreprise propriétaire) et que le jeton a été créé quand cette entreprise était active.

### L'historique n'enregistre pas vos appels

Seules les authentifications **réussies** sont enregistrées. Des requêtes qui renvoient `401` n'apparaissent pas dans `Voir l'historique`, même si elles atteignent le serveur. Faites au moins une requête qui renvoie `2xx` et rafraîchissez l'historique pour vérifier que le jeton fonctionne.
