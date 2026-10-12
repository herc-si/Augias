---
title: Setting up accounting
description: Choose your tax regime so Augias can keep your books and work out what you owe.
sidebar_position: 1
---

# Setting up accounting

Accounting is off until you pick a tax regime. The regime decides which books you have to keep, which turnover limits apply to you, and how your contributions are worked out — so nothing else in this section does anything until it is set.

Click `Accounting` in the sidebar. Until a regime is chosen the page shows `Accounting is not set up yet` and a `Go to settings` button.

## Choose a regime

In the sidebar, expand `System`, click `Settings`, and open the `Accounting` tab.

`Tax regime` is the only field that matters to begin with. One regime ships today:

- `France — Micro-entreprise` — cash-basis bookkeeping: a revenue book, a purchase register for resale activities, and turnover declared to URSSAF.

The remaining fields have working defaults, so you can save after picking a regime and come back to the rest.

## When a regime is required

If you charge VAT (`Not liable for VAT` is unticked) and you invoice private customers, you have to choose a regime before you can record their payments.

French law treats any software that records payments outside the books as a cash register, and requires cash registers to be certified (article 286 of the French tax code). Augias is not a certified cash register. Once your books are kept in Augias, every payment you record goes into the revenue book straight away, without anyone having to do it by hand, and the obligation no longer applies.

Until a regime is chosen:

- a client saved with no company name, which marks them as a private customer, is refused;
- recording a payment from an existing private customer is refused, on the payment screen, through the API and through MCP;
- the `Attention Required` card on the dashboard shows `Books to keep`.

Customers paying online are not turned away.

:::info
This does not apply if you are not liable for VAT (franchise en base), or if all your customers are businesses.
:::

## Fields on the Accounting tab

| Field | What it does |
|---|---|
| `Tax regime` | Decides which books you keep, which limits apply and how your contributions are worked out. |
| `Main activity` | Used as the default for entries created automatically: `Sale of goods`, `Services (BIC)` or `Services (BNC)`. You can change it on any individual entry. |
| `Start of activity` | Used to scale a first, partial year's limits down pro rata, and to work out how long ACRE runs. |
| `Declaration frequency` | `Monthly` or `Quarterly`. Also the rhythm your books are closed on. |
| `Not liable for VAT` | Suppresses VAT on invoices and quotes, and prints the legal wording below on them. |
| `VAT exemption wording` | Printed on every invoice and quote while you are not liable for VAT. |
| `Flat-rate income tax option` | Pay income tax as a percentage of turnover alongside your contributions, instead of on your annual return. |
| `ACRE relief` | Reduces the social contribution rate for the first months of activity. |
| `Pension fund` | `SSI` or `CIPAV`. The two charge different rates on the same BNC turnover. |

:::info
`Main activity` is a default, not a constraint. A business that sells goods *and* bills for services records the activity per entry, and each is measured against its own ceiling.
:::

:::warning
`ACRE relief` needs a `Start of activity` date to work out how long the relief runs. Leave the date blank and the relief cannot be applied.
:::

## What changes once a regime is set

- `Accounting` in the sidebar shows your turnover for the year, your books, and the period you are currently in.
- Every payment you record from now on writes itself into the right book. See [Your books](./your-books.md).
- Turnover is compared with the limits of your regime once a day, and you are told the first time you approach or pass one. See [Declaring your turnover](./declaring-your-turnover.md).

## Taking in documents from before

Invoices, credit notes, payments and supplier invoices recorded in Augias *before* you set a regime are not in your books yet. While some from the current financial year are missing, the `Accounting` page says so and offers `Review and take in`. You can also open the page at any time with `Take in earlier documents`, at the bottom of the regime card.

1. Pick the day to start from. It defaults to the first day of your current financial year. It cannot be on or before the date your books are locked to.
2. Click `Show` to list the entries that would be added, with their date, book, document and amount.
3. Click `Add these entries` to write them into your books.

Each entry goes into the period its date falls in, as if it had been written on the day. Anything already in your books is left alone, so running it twice adds nothing.

:::note
This only covers what was recorded in Augias. Money received outside it — before you started using Augias, or never entered here — still has to be added by hand: see [Adding an entry by hand](./your-books.md#adding-an-entry-by-hand).
:::

## Related

- [Your books](./your-books.md)
- [Closing a period](./closing-a-period.md)
- [Setting up tax rates](../taxes/tax-rates.md)
