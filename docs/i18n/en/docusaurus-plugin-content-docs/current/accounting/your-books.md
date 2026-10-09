---
title: Your books
description: How the revenue book and the purchase register fill themselves, and how to add an entry by hand.
sidebar_position: 2
---

# Your books

Your statutory books are written for you. Every payment Augias records — an invoice paid, a supplier bill settled — becomes an entry in the right book, on the day the money moved.

Click `Accounting` in the sidebar, then pick a book from the `Books` card.

## Which books you keep

| Book | Holds |
|---|---|
| `Revenue book` | Money received. Everyone keeps this one. |
| `Purchase register` | Money paid to suppliers. Only kept when your regime requires it. |

Under the French micro-entreprise regime the purchase register is only required of resale and accommodation activities, so a business whose `Main activity` is `Services (BIC)` or `Services (BNC)` sees the revenue book alone.

:::info
Books are cash-basis. An invoice appears in the revenue book when it is **paid**, not when it is issued — so an unpaid invoice is not turnover and is nowhere in your books.
:::

## What each entry holds

| Column | Meaning |
|---|---|
| `No.` | The entry's sequence number. Blank until the period is closed — see [Closing a period](./closing-a-period.md). |
| `Date` | The date the money moved, not the invoice date. |
| `Nature` | What the money was for. |
| `Counterparty` | The client or supplier, as they were named when the entry was made. |
| `Document` | The invoice or receipt number. |
| `Amount` | Signed: a reversing entry is negative. |

## Entries Augias writes itself

A payment recorded anywhere in the application produces exactly one entry — through the payment screen, the REST API, the MCP tools or a payment gateway alike. Recording the same payment twice, or a gateway retrying its callback, does not produce a second entry.

Open such an entry and it says so: *This entry mirrors a payment recorded in the application. Only the activity and the notes can be changed here; the rest follows the payment.*

`Activity` is the field worth checking. Automatic entries are filed under your `Main activity`, and if that particular receipt belongs to another one, change it here — it decides which ceiling and contribution rate the receipt counts towards.

:::warning
Refunds are not booked automatically. A refunded payment leaves its original receipt in the book; record the reversal yourself as a negative entry, so both the receipt and its reversal stay visible.
:::

## Adding an entry by hand

Some money never passes through an invoice or a supplier bill. Click `New entry` on the book to record it.

| Field | Notes |
|---|---|
| `Date` | The date the money moved, not the invoice date. |
| `Amount` | |
| `Nature of the operation` | What the money was for. |
| `Counterparty` | Who paid you, or who you paid. |
| `Supporting document` | The invoice or receipt number. |
| `Settlement method` | `Bank transfer`, `Cheque`, `Credit card`, `Direct debit`, `Cash`, `Online payment` or `Other`. |
| `Activity` | Revenue book only. |
| `Notes` | |

Hand-written entries can be edited and deleted freely — until their period is closed.

:::tip
Use a hand-written entry with a negative `Amount` to correct something that was already sealed. Give it a `Nature of the operation` that says what it reverses.
:::

## Entries in another currency

Your books are kept in your company's currency. An entry booked in a different one is counted separately and left out of your turnover totals, with a note on the accounting page: *Some entries are booked in another currency and are not included in these totals.*

Nothing is converted, because the books never recorded an exchange rate. Convert the amount yourself and record it in your own currency if it should count towards your turnover.

## Related

- [Closing a period](./closing-a-period.md)
- [Declaring your turnover](./declaring-your-turnover.md)
- [Invoice statuses](../invoices/invoice-statuses.md)
