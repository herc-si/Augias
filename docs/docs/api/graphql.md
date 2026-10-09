---
title: API GraphQL
description: Interroger et modifier les données d'Augias avec l'API GraphQL.
sidebar_position: 2
---

# API GraphQL

L'API GraphQL d'Augias donne une interface souple et typée aux mêmes données que l'API REST. Au lieu d'appeler plusieurs points d'accès fixes, vous écrivez une seule requête qui décrit exactement ce dont vous avez besoin, et le serveur renvoie précisément cela, rien de plus.

Le point d'accès GraphQL est `/api/graphql` sur votre installation, par exemple :

```text
https://votre-instance.example/api/graphql
```

:::tip
REST ou GraphQL ? Choisissez **REST** pour un outil d'automatisation comme Zapier ou n8n, ou pour des ressources simples une à une. Choisissez **GraphQL** pour récupérer des données liées en une seule requête, ou pour maîtriser finement la forme de la réponse.
:::

## Explorateur interactif (GraphiQL)

Ouvrir `/api/graphql` dans un navigateur charge **GraphiQL**, un environnement de travail dans le navigateur pour écrire et tester des requêtes. Il comprend :

- un éditeur de requêtes avec coloration et complétion ;
- la documentation de chaque type et de chaque champ ;
- un historique de vos requêtes récentes ;
- des éditeurs de variables et d'en-têtes.

GraphiQL est le moyen le plus rapide de découvrir ce qui est disponible : le panneau `Docs`, à droite, parcourt tous les types, requêtes et mutations.

## Authentification

GraphQL utilise les mêmes jetons d'API que l'API REST. Créez un jeton dans `Clés API` (voir [Créer un jeton d'API](./rest-api.md#créer-un-jeton-dapi)), puis envoyez-le dans l'en-tête `X-API-TOKEN` de chaque requête.

```bash
curl -X POST https://votre-instance.example/api/graphql \
     -H "X-API-TOKEN: <votre-jeton>" \
     -H "Content-Type: application/json" \
     -d '{"query": "{ invoices { edges { node { id status } } } }"}'
```

Dans GraphiQL, ajoutez l'en-tête dans l'onglet `Headers`, en bas de l'éditeur :

```json
{
  "X-API-TOKEN": "<votre-jeton>"
}
```

Une requête sans jeton valide reçoit `401 Unauthorized`.

:::info
Un jeton vaut pour un utilisateur et une entreprise. Si votre compte a plusieurs entreprises, créez un jeton par entreprise en passant sur chacune avant de créer son jeton.
:::

## Lire des données

Les requêtes GraphQL partent en `POST` HTTP vers `/api/graphql`, avec un corps JSON qui contient un champ `query`.

### Une collection

Le nom de ressource au pluriel renvoie une liste. Chaque collection renvoie une [connexion de type Relay](#pagination), enveloppée dans `edges` :

```graphql
query {
  invoices {
    edges {
      node {
        id
        status
        total
      }
    }
  }
}
```

```bash
curl -X POST https://votre-instance.example/api/graphql \
     -H "X-API-TOKEN: <votre-jeton>" \
     -H "Content-Type: application/json" \
     -d '{
       "query": "{ invoices { edges { node { id status total } } } }"
     }'
```

### Un seul élément

Le nom de ressource au singulier, avec un argument `id`. L'identifiant est l'IRI complète (par exemple `/api/invoices/01J...`) :

```graphql
query {
  invoice(id: "/api/invoices/01JDKR4XQ3NEVF8CNKQSJ5GPRT") {
    id
    status
    total
    client {
      name
    }
  }
}
```

### Des données liées

L'un des grands atouts de GraphQL est de demander des ressources liées en un seul aller-retour. Cette requête récupère les factures avec le nom de leur client et leurs lignes, en une fois :

```graphql
query {
  invoices {
    edges {
      node {
        id
        status
        total
        client {
          name
          currency
        }
        lines {
          edges {
            node {
              description
              qty
              price
            }
          }
        }
      }
    }
  }
}
```

## Filtrer les collections

Passez les filtres directement à la requête de collection. Les filtres disponibles sont ceux de l'API REST pour chaque ressource.

### Factures par statut

```graphql
query {
  invoices(status: "pending") {
    edges {
      node {
        id
        status
        total
      }
    }
  }
}
```

### Clients par nom

```graphql
query {
  clients(name: "Acme") {
    edges {
      node {
        id
        name
      }
    }
  }
}
```

### Avec des variables

Pour des requêtes dynamiques, passez les valeurs des filtres en variables GraphQL plutôt que de les écrire dans la requête :

```graphql
query GetInvoicesByStatus($status: String) {
  invoices(status: $status) {
    edges {
      node {
        id
        status
        total
      }
    }
  }
}
```

Envoyez les variables dans le champ `variables` du corps de la requête :

```bash
curl -X POST https://votre-instance.example/api/graphql \
     -H "X-API-TOKEN: <votre-jeton>" \
     -H "Content-Type: application/json" \
     -d '{
       "query": "query GetInvoicesByStatus($status: String) { invoices(status: $status) { edges { node { id status total } } } }",
       "variables": { "status": "pending" }
     }'
```

## Mutations

Les mutations créent, modifient ou suppriment des ressources. Leurs noms suivent un même modèle :

| Opération | Modèle de nom | Exemple |
| --- | --- | --- |
| Créer | `create{Resource}` | `createClient` |
| Modifier | `update{Resource}` | `updateInvoice` |
| Supprimer | `delete{Resource}` | `deleteQuote` |

### Créer une ressource

Passez les champs dans un argument `input`. La mutation renvoie la ressource créée :

```graphql
mutation {
  createClient(input: {
    name: "Acme Corp"
    currency: "EUR"
    website: "https://acme.example"
  }) {
    client {
      id
      name
    }
  }
}
```

### Modifier une ressource

Donnez l'`id` (IRI complète) et seulement les champs à changer :

```graphql
mutation {
  updateClient(input: {
    id: "/api/clients/01JDKR4XQ3NEVF8CNKQSJ5GPRT"
    website: "https://new-site.example"
  }) {
    client {
      id
      website
    }
  }
}
```

### Supprimer une ressource

```graphql
mutation {
  deleteInvoice(input: {
    id: "/api/invoices/01JDKR4XQ3NEVF8CNKQSJ5GPRT"
  }) {
    invoice {
      id
    }
  }
}
```

:::warning
La suppression est immédiate et ne s'annule pas par l'API. Vérifiez l'`id` avant de lancer une mutation de suppression. Une facture ou un avoir émis ne se supprime pas : la demande est refusée.
:::

## Pagination

Les collections GraphQL sont paginées **par curseur**, selon la spécification des connexions Relay. Chaque requête de collection accepte les arguments `first`, `last`, `before` et `after`, et renvoie `pageInfo` à côté des `edges` :

```graphql
query {
  invoices(first: 10, after: "cursor-value-from-previous-page") {
    pageInfo {
      hasNextPage
      hasPreviousPage
      startCursor
      endCursor
    }
    edges {
      cursor
      node {
        id
        status
        total
      }
    }
  }
}
```

Pour avancer page par page :

1. Lancez la requête sans `after` pour obtenir la première page.
2. Regardez `pageInfo.hasNextPage`. S'il vaut `true`, passez `pageInfo.endCursor` comme argument `after` à la requête suivante.
3. Recommencez jusqu'à ce que `hasNextPage` vaille `false`.

La taille de page par défaut est de **30 éléments**. Passez un argument `first` pour en demander moins (30 au plus par page) :

```graphql
query {
  invoices(first: 5) {
    edges {
      node { id status }
    }
  }
}
```

## Ressources disponibles

Toutes les ressources principales sont accessibles en GraphQL. La gestion des jetons d'API n'existe qu'en REST.

| Ressource | Requête (collection) | Requête (élément) | Mutations |
| --- | --- | --- | --- |
| Clients | `clients` | `client(id:)` | `createClient`, `updateClient`, `deleteClient` |
| Contacts | `contacts` | `contact(id:)` | `createContact`, `updateContact`, `deleteContact` |
| Adresses | `addresses` | `address(id:)` | `createAddress`, `updateAddress`, `deleteAddress` |
| Factures | `invoices` | `invoice(id:)` | `createInvoice`, `updateInvoice`, `deleteInvoice` |
| Lignes de facture | `invoiceLines` | `invoiceLine(id:)` | `createInvoiceLine`, `updateInvoiceLine`, `deleteInvoiceLine` |
| Factures récurrentes | `recurringInvoices` | `recurringInvoice(id:)` | `createRecurringInvoice`, `updateRecurringInvoice`, `deleteRecurringInvoice` |
| Devis | `quotes` | `quote(id:)` | `createQuote`, `updateQuote`, `deleteQuote` |
| Lignes de devis | `quoteLines` | `quoteLine(id:)` | `createQuoteLine`, `updateQuoteLine`, `deleteQuoteLine` |
| Paiements | `payments` | `payment(id:)` | `createPayment` |
| Taxes | `taxes` | `tax(id:)` | `createTax`, `updateTax`, `deleteTax` |

:::info
Les montants (totaux, prix, soldes) sont toujours des entiers dans la **plus petite unité de la devise** : les centimes pour l'euro, les pence pour la livre, etc. Par exemple, `1000` représente `10,00 €`. La devise vient du client concerné.
:::

## Introspection

L'introspection de GraphQL permet d'interroger le schéma lui-même pour découvrir tous les types, champs et opérations. GraphiQL s'en sert automatiquement, mais vous pouvez aussi l'interroger directement :

```graphql
query {
  __schema {
    types {
      name
      kind
    }
  }
}
```

Pour examiner un type précis :

```graphql
query {
  __type(name: "Invoice") {
    fields {
      name
      type {
        name
        kind
      }
    }
  }
}
```

## Dépannage

### `401 Unauthorized`

L'en-tête `X-API-TOKEN` manque, est faux, ou le jeton a été révoqué. Vérifiez le nom et la valeur de l'en-tête : il doit s'appeler `X-API-TOKEN` (et non `Authorization` ou `Bearer`). Si le jeton ne fonctionne plus, créez-en un autre dans `Clés API`.

### Une requête renvoie `null` pour une ressource

La ressource n'existe pas, a été supprimée, ou n'appartient pas à l'entreprise du jeton. Les jetons sont propres à une entreprise : si vous en avez plusieurs, vérifiez que le jeton a été créé quand la bonne était active.

### Une mutation échoue sur une erreur de validation

Regardez le tableau `errors` de la réponse. Chaque erreur a un `message` et un tableau `extensions.violations` qui indique le champ refusé et pourquoi :

```json
{
  "errors": [{
    "message": "name: This value should not be blank.",
    "extensions": {
      "violations": [
        { "path": "name", "message": "This value should not be blank." }
      ]
    }
  }]
}
```

### GraphiQL affiche une page blanche ou ne se charge pas

GraphiQL est servi sur `/api/graphql` et demande un navigateur. Devant une page blanche, regardez la console du navigateur pour des erreurs JavaScript, et vérifiez qu'aucune politique de sécurité du contenu de votre instance ne bloque la page.

### La gestion des jetons d'API échoue

La gestion des jetons (lister, créer, révoquer) n'existe pas en GraphQL : utilisez l'[API REST](./rest-api.md) ou la page `Clés API` de l'application.
