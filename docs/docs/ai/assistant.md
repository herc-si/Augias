---
title: Assistant intégré
description: Poser des questions sur votre activité, à l'écrit ou à la voix, et faire préparer devis, factures et fiches par l'assistant d'Augias.
sidebar_position: 1
---

# Assistant intégré

L'assistant répond à vos questions sur votre entreprise et prépare des modifications à votre demande. Chaque modification vous est montrée avant d'être faite.

## Ouvrir l'assistant

Cliquez sur `Assistant` dans la barre du haut. Sur téléphone, touchez le bouton rond en bas à droite de l'écran.

Une fenêtre s'ouvre par-dessus la page. Elle reste ouverte quand vous changez de page, avec la conversation et le texte en cours de saisie. Pour la fermer, cliquez sur la croix ou appuyez sur Échap.

Tapez votre question puis appuyez sur Entrée, ou cliquez sur `Demander`. Pour aller à la ligne, utilisez Maj+Entrée. `Nouvelle conversation` efface l'échange et repart de zéro.

## Activer l'assistant

L'assistant est désactivé au départ. Seul le **propriétaire** de l'entreprise peut l'activer : à la première ouverture, il voit ce qui est envoyé et à qui, puis clique sur `Activer l'assistant`.

Les autres membres voient le message « L'assistant n'est pas activé pour cette entreprise » tant que le propriétaire ne l'a pas fait. Le propriétaire peut le désactiver à tout moment avec le lien `Désactiver l'assistant`, sous le champ de saisie. Cela vaut pour toute l'entreprise et efface la conversation en cours.

:::info
Vos questions, et les données de l'entreprise nécessaires pour y répondre (clients, documents, montants), sont traitées par le service d'IA de Scaleway, à Paris. Scaleway ne s'en sert pas pour entraîner les modèles, et les éditeurs des modèles n'y ont pas accès. Voir la [politique de confidentialité](/legal/politique-de-confidentialite).
:::

## Poser une question

L'assistant lit les données de l'entreprise ouverte pour répondre. Quelques exemples :

- « Qui me doit de l'argent, et depuis quand ? »
- « Quel est mon chiffre d'affaires du trimestre ? »
- « Quelles factures électroniques ai-je reçues cette semaine ? »

Il respecte votre rôle dans l'entreprise : il ne lit rien que vous ne pourriez pas ouvrir vous-même.

:::warning
L'assistant est un modèle d'intelligence artificielle : il peut se tromper. Vérifiez un chiffre important dans l'écran correspondant avant de vous en servir.
:::

## Faire une modification

Demandez ce que vous voulez faire, par exemple « Ajoute au catalogue Conseil à 80 € de l'heure » ou « Crée un devis pour Dupont, 3 heures de conseil ». Avant de créer quoi que ce soit, l'assistant affiche une carte qui détaille ce qui sera fait : le nom du client, chaque ligne, les montants, l'unité.

- `Confirmer` fait la modification, puis l'assistant vous en rend compte.
- `Annuler` ne fait rien.
- Poser une autre question abandonne la modification proposée.

L'assistant ne fait qu'une modification à la fois. Il peut :

- créer, modifier ou dupliquer un devis ou une facture, transformer un devis en facture, créer une facture d'acompte : les documents créés restent en brouillon ;
- ajouter ou modifier un article du catalogue, une catégorie ;
- créer ou modifier un client ou un contact, ajouter une adresse ou un identifiant fiscal ;
- envoyer un devis, une facture ou une relance, enregistrer un paiement.

Il ne supprime rien et ne touche ni aux avoirs, ni aux achats, ni aux taux de taxe, ni aux livres comptables. Un membre dont le rôle ne permet pas de modifier les documents, comme un comptable, ne peut rien faire faire à l'assistant, même en confirmant.

:::tip
Regardez surtout les montants sur la carte : c'est là qu'une erreur de compréhension se voit, par exemple 0,80 € au lieu de 80 €.
:::

## Parler à l'assistant

Touchez le bouton micro à côté de `Demander`, parlez, puis touchez-le à nouveau pour terminer. Votre question est transcrite et envoyée automatiquement. Un enregistrement s'arrête de lui-même au bout de 30 secondes.

La première fois, le navigateur vous demande l'autorisation d'utiliser le micro. La voix est transcrite par Scaleway, à Paris, comme les questions écrites ; HERC SI ne conserve pas l'enregistrement. Une modification demandée à la voix passe, elle aussi, par la carte de confirmation.

## Questions par mois

Sur le service hébergé, chaque offre comprend un nombre de questions par mois pour l'entreprise. Le compteur est affiché sous le champ de saisie, par exemple « 12 / 200 questions ce mois-ci ». Une fois le nombre atteint, l'assistant le dit et reprend le premier jour du mois suivant. L'offre Découverte ne comprend pas l'assistant.

## En auto-hébergé

L'assistant n'apparaît que si l'administrateur l'a configuré : rien ne part vers un service tiers sinon. Il n'y a pas de limite de questions. Il se règle par des variables d'environnement, comme toute variable d'Augias (option `-e` de Docker, ou fichier `.env` à la racine de l'application pour le paquet de distribution) :

| Variable | Rôle |
|---|---|
| `AUGIAS_ASSISTANT_BASE_URL` | L'adresse d'une API compatible OpenAI, sans `/chat/completions` : Scaleway, Infomaniak, Mistral, ou un modèle local servi par Ollama. |
| `AUGIAS_ASSISTANT_API_KEY` | La clé de cette API. |
| `AUGIAS_ASSISTANT_MODEL` | Le nom du modèle. Il doit savoir appeler des outils. |
| `AUGIAS_ASSISTANT_TRANSCRIPTION_URL` | Facultatif : l'adresse complète d'un service de transcription compatible OpenAI (`…/audio/transcriptions`). Sans elle, pas de micro. |
| `AUGIAS_ASSISTANT_TRANSCRIPTION_MODEL` | Le modèle de transcription, par exemple `whisper-large-v3`. |

Le micro ne fonctionne qu'en HTTPS : les navigateurs ne l'autorisent pas ailleurs.

## Voir aussi

- [Serveur MCP](./mcp-server.mdx) : relier un agent d'IA externe, comme Claude, à Augias.
