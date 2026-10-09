---
title: Rechercher
description: Retrouver clients, factures, devis et paiements avec la barre de recherche.
sidebar_position: 1
---

# Rechercher

La barre de recherche, en haut de chaque page, retrouve n'importe quelle fiche de votre compte : clients, contacts, factures, factures récurrentes, devis et paiements. Tapez quelques caractères : les résultats s'affichent groupés par type. `↵` (ou un clic sur une ligne) ouvre directement la fiche.

Le raccourci `Ctrl+K` (`Cmd+K` sur macOS) place le curseur dans la barre depuis n'importe où, et l'icône `?` au bout de la barre ouvre un rappel de la syntaxe.

:::info[Hébergé ou auto-hébergé]
La recherche s'appuie sur Meilisearch.

En **auto-hébergement**, elle est facultative et s'active en configurant un moteur de recherche : voir l'[intégration Meilisearch](../integrations/meilisearch.md). Sans moteur, la barre est masquée et le reste d'Augias fonctionne normalement.
:::

## Ce qui se recherche

La barre couvre six types de fiches, dans l'entreprise où vous travaillez :

- `Clients` : par nom, site web.
- `Contacts` : par nom et e-mail.
- `Factures` : par numéro, nom du client, statut, total.
- `Factures récurrentes` : mêmes champs que les factures.
- `Devis` : par numéro, nom du client, statut, total.
- `Paiements` : par référence, nom du client, statut, total.

Les résultats des autres entreprises dont vous êtes membre n'apparaissent jamais : changez d'entreprise d'abord pour chercher dans une autre.

## Recherche libre

Des mots simples, comme `acme` ou `facture 1024`, sont recherchés dans les six types, en tolérant les fautes de frappe. Quelques repères :

- **Plusieurs mots** doivent tous correspondre dans un même type : `klein 5000` montre les clients, factures, etc. qui contiennent les deux.
- **Fautes de frappe et mots partiels** sont acceptés : `klien` trouve encore `Klein-Lehner`.
- **Des guillemets autour d'une expression** imposent l'expression exacte : `"Acme Corp"` ne trouve pas `Acme Holdings`.
- La recherche se lance pendant la saisie, avec un court délai, et se met à jour à chaque frappe.

## Filtres

Au-delà du texte libre, la barre comprend une petite syntaxe de filtres, proche de celle de GitHub, sous la forme `clé:valeur`, que l'on peut combiner avec du texte libre. Les clés et les valeurs restent en anglais.

| Filtre | Effet | Exemple |
| --- | --- | --- |
| `in:` | Limite les résultats à un ou plusieurs types : `clients`, `contacts`, `invoices`, `recurring_invoices`, `quotes`, `payments`. | `in:invoices,quotes overdue` |
| `status:` | Filtre par statut (par exemple `paid`, `pending`, `draft`, `overdue`). | `status:paid acme` |
| `client:` | Filtre par nom de client. Guillemets pour un nom en plusieurs mots. | `client:"Acme Corp"` |
| `amount:` | Filtre par montant total. | `amount:1000` |
| `created:` | Filtre par date de création. | `created:2026-01-15` |
| `sort:` | Trie les résultats : `amount`, `amount_desc`, `date`, `date_desc`. | `unpaid sort:amount_desc` |

Un filtre qui ne s'applique pas à un type est ignoré pour ce type. Par exemple, `client:Acme` filtre les factures, les devis, les paiements et les factures récurrentes, mais pas les contacts : là, `client:Acme` est traité comme du texte libre.

Quelques exemples :

```text
in:invoices status:overdue sort:date_desc
client:"Acme Corp" amount:5000
in:clients,contacts jean
```

Le premier trouve les factures en retard les plus récentes. Le deuxième trouve tout ce qui concerne le client `Acme Corp` pour un total de `5000`, tous types confondus. Le troisième cherche `jean` dans les clients et les contacts seulement.

## Mise à jour en temps réel

Quand vous créez, modifiez ou supprimez une fiche, par l'application, l'API ou une intégration, la recherche est mise à jour aussitôt. Pas de réindexation programmée ni rien à rafraîchir : une fiche créée se retrouve dès la frappe suivante.

Si les résultats semblent décalés (par exemple après la restauration d'une base en auto-hébergement), les index peuvent être reconstruits : voir [Intégration Meilisearch → Indexation initiale](../integrations/meilisearch.md#initial-indexing).

## Dépannage

### La barre de recherche n'apparaît pas

Le moteur de recherche n'est pas configuré pour votre installation. Sur le service hébergé, cela ne devrait pas arriver : contactez le support. En auto-hébergement, suivez le guide [Intégration Meilisearch](../integrations/meilisearch.md) pour configurer et relier un moteur. Après avoir réglé les variables d'environnement, videz le cache de l'application et rechargez la page.

### Une fiche que je viens de créer ou de modifier n'apparaît pas

L'indexation se fait dès l'enregistrement, ce cas devrait donc être rare. S'il se produit, en général après une modification directe de la base ou une restauration de sauvegarde, relancez l'import du moteur de recherche en ligne de commande pour reconstruire les index. La commande et ses options sont dans [Intégration Meilisearch → Indexation initiale](../integrations/meilisearch.md#initial-indexing).

### Ma recherche libre ne renvoie rien

Vérifiez que vous êtes dans la bonne entreprise (le sélecteur est en haut à droite). Les résultats se limitent à l'entreprise active : une fiche d'une autre entreprise n'apparaît qu'après être passé sur celle-ci.
