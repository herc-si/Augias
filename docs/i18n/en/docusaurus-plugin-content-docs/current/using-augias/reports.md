---
title: Reading the reports
description: "Aged receivables and revenue per client: who owes you what, and what each client brought in."
sidebar_position: 8
---

# Reading the reports

The `Reports` page, in the menu, answers two questions: who owes you money and since when, and what each client brought in over a year. The figures are read straight from your invoices, credit notes and payments: they are up to date every time you open the page.

## Aged receivables

The `Aged receivables` tab lists, client by client, what is left to pay on the invoices you issued, sorted by how late each invoice is on its due date:

| Column | What it holds |
|---|---|
| `Not due` | Invoices whose due date has not passed, or that have no due date. |
| `1 to 30 days late`, `31 to 60 days late`, `61 to 90 days late` | Late invoices, by the number of days since the due date. |
| `Over 90 days` | The oldest debts, shown in red. |
| `Total` | Everything the client owes you. |

The client who owes you the most comes first. Click their name to open their page and find the invoices concerned.

:::info
Only what is left to pay counts: a partly paid invoice appears for its balance only. Drafts and paid invoices do not appear.
:::

## Revenue per client

The `Revenue per client` tab covers one calendar year, chosen among the last four.

| Column | What it holds |
|---|---|
| `Invoices` | How many invoices were issued in the year. |
| `Invoiced excl. tax` | Their amount before tax and without disbursements. |
| `Credit notes excl. tax` | The credit notes issued in the year, on the same basis. |
| `Net excl. tax` | Invoiced minus credit notes. Clients are ranked on this column. |
| `Received incl. tax` | The payments taken in the year, tax included. |

An invoice counts for the year of its invoice date, a payment for the year it was recorded. Drafts and cancelled invoices do not count, and settling an invoice from the [client's credit](../managing-clients/client-credit.md) is not money received.

:::note
If you invoice in several currencies, each report shows one table per currency: amounts in different currencies are never added together.
:::

## A client's statement of account

The statement of account lists, for one client, everything that moved what they owe you, in date order, with the balance after each line. Open the client's page, then the `⋮` menu and `Statement of account`.

| Movement | Effect on the balance |
|---|---|
| Invoice issued | Raises what the client owes you (`Debit`). |
| Payment received | Lowers it (`Credit`), whatever it was paid with. |
| Credit note issued | Lowers it (`Credit`). |
| Refund of a credit note | Raises it again (`Debit`). |

Choose `All` for the whole history, or a year: the statement then starts with the `Balance brought forward`, what was owed before 1 January. A negative balance means you owe the client money.

`Download as PDF` produces the statement under your name, to attach to a reminder or to send at year end.

:::info
Drafts and cancelled invoices do not appear. A credit note set against an invoice has no line of its own: it already lowered the balance when it was issued.
:::

## Export

The `Export as CSV` button downloads the report on screen, ready to open in a spreadsheet.

## Related

- [Exporting your data](./exporting-data.md)
