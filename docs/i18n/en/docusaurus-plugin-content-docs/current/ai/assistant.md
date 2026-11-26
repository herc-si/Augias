---
title: Built-in assistant
description: Ask about your business by text or voice, and have Augias's assistant prepare quotes, invoices and records for you.
sidebar_position: 1
---

# Built-in assistant

The assistant answers questions about your company and prepares changes when you ask. Every change is shown to you before it is made.

## Opening the assistant

Click `Assistant` in the top bar. On a phone, tap the round button at the bottom right of the screen.

A window opens over the page. It stays open as you move from page to page, with the conversation and whatever you were typing. To close it, click the cross or press Escape.

Type your question and press Enter, or click `Ask`. Use Shift+Enter for a new line. `New conversation` clears the exchange and starts again.

## Turning the assistant on

The assistant starts off. Only the company's **owner** can turn it on: the first time they open it, they are told what is sent and to whom, then click `Turn the assistant on`.

Other members see "The assistant is not turned on for this company" until the owner has done so. The owner can turn it off at any time with the `Turn the assistant off` link under the input. This applies to the whole company and clears the current conversation.

:::info
Your questions, and the company data needed to answer them (clients, documents, amounts), are processed by Scaleway's AI service in Paris. Scaleway does not use them to train the models, and the model makers have no access to them. See the [privacy policy](/legal/politique-de-confidentialite) (in French).
:::

## Asking a question

The assistant reads the open company's data to answer. A few examples:

- "Who owes me money, and since when?"
- "What is my turnover this quarter?"
- "Which e-invoices did I receive this week?"

It follows your role in the company: it reads nothing you could not open yourself.

:::warning
The assistant is an AI model: it can be wrong. Check an important figure on the matching screen before relying on it.
:::

## Making a change

Ask for what you want done, for example "Add Consulting to the catalogue at €80 an hour" or "Create a quote for Dupont, 3 hours of consulting". Before creating anything, the assistant shows a card detailing what will be done: the client's name, every line, the amounts, the unit.

- `Confirm` makes the change, then the assistant reports on it.
- `Cancel` does nothing.
- Asking something else leaves the proposed change unmade.

The assistant makes one change at a time. It can:

- create, change or duplicate a quote or an invoice, turn a quote into an invoice, create a deposit invoice: documents it creates stay drafts;
- add or change a catalogue item or a category;
- create or change a client or a contact, add an address or a tax identifier;
- send a quote, an invoice or a reminder, record a payment.

It deletes nothing and does not touch credit notes, purchases, tax rates or the books. A member whose role does not allow changing documents, such as an accountant, cannot have the assistant change anything, even by confirming.

:::tip
Look at the amounts on the card above all: that is where a misunderstanding shows, such as €0.80 instead of €80.
:::

## Speaking to the assistant

Tap the microphone button next to `Ask`, speak, then tap it again to finish. Your question is written out and sent automatically. A recording stops by itself after 30 seconds.

The first time, your browser asks permission to use the microphone. Speech is transcribed by Scaleway in Paris, like typed questions; HERC SI does not keep the recording. A change asked for by voice also goes through the confirmation card.

## Questions per month

On the hosted service, each plan includes a number of questions per month for the company. The count is shown under the input, for example "12 / 200 questions this month". Once it is reached, the assistant says so and is back on the first day of the next month. The Découverte plan does not include the assistant.

## Self-hosted

The assistant only appears once the administrator has configured it: nothing goes to a third-party service otherwise. There is no question limit. It is set with environment variables, like any Augias variable (Docker's `-e` option, or the `.env` file at the application root for the distribution package):

| Variable | Purpose |
|---|---|
| `AUGIAS_ASSISTANT_BASE_URL` | The address of an OpenAI-compatible API, without `/chat/completions`: Scaleway, Infomaniak, Mistral, or a local model served by Ollama. |
| `AUGIAS_ASSISTANT_API_KEY` | That API's key. |
| `AUGIAS_ASSISTANT_MODEL` | The model's name. It must be able to call tools. |
| `AUGIAS_ASSISTANT_TRANSCRIPTION_URL` | Optional: the full address of an OpenAI-compatible transcription service (`…/audio/transcriptions`). Without it, there is no microphone. |
| `AUGIAS_ASSISTANT_TRANSCRIPTION_MODEL` | The transcription model, for example `whisper-large-v3`. |

The microphone only works over HTTPS: browsers do not allow it elsewhere.

## See also

- [MCP server](./mcp-server.mdx): connect an outside AI agent, such as Claude, to Augias.
