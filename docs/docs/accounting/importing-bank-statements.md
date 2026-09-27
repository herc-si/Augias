---
title: Importing bank statements
description: Import the statements you download from your bank and match each operation to the payment it proves.
sidebar_position: 5
---

# Importing bank statements

Download your statements from your bank's website and import them into Augias, then match each operation to the invoice or supplier bill it settles. Augias never connects to your bank: you choose what to import.

Click `Accounting` in the sidebar, then `Bank`.

## Add a bank account

In the `Add a bank account` card, give the account a name, optionally its IBAN, and its currency, then click `Add the account`. Each account gets a tab at the top of the page.

## Import a statement

1. On your bank's website, download a statement in one of these formats:
   - `CAMT.053` (XML), the European standard, offered by most banks;
   - `OFX`, often labelled "Money" or "Quicken";
   - `CSV`, the spreadsheet export.
2. In the `Import a statement` card, choose the file and click `Import`.

Augias reports how many operations were added and how many it already knew. Importing the same statement twice, or two statements that overlap, adds nothing twice.

:::tip
Prefer `CAMT.053` or `OFX` when your bank offers them: they carry the bank's own reference for each operation and the name of the other party, which makes matching more reliable.
:::

## Reconcile the operations

The `To reconcile` tab lists the operations nothing in Augias accounts for yet. Next to each one, Augias suggests what it most likely is, among the documents with exactly the same amount:

- `Payment already recorded` — a payment you have already entered. Matching only ties the operation to it.
- `Collect invoice` — an invoice still owed. Matching records its payment as a bank transfer on the date the bank booked it, and the invoice is marked paid once nothing is left to pay.
- `Pay supplier invoice` — the same for a supplier bill.

Suggestions that mention the invoice number or the other party's name come first.

Click the suggestion that is right. When none is, record the payment from the invoice as usual, or click `Set aside` for an operation that has nothing to match, such as a transfer between your own accounts. `Undo` puts a reconciled or set-aside operation back in the list; it does not delete the payment that was recorded.

Payments recorded this way go into your books like any other, on the date of the bank operation.

:::info
If your company charges VAT and has not chosen a tax regime, a private customer's payment cannot be recorded from the bank page either, for the reason explained in [Setting up accounting](./setting-up-accounting.md#when-a-regime-is-required).
:::

## Troubleshooting

### `Columns not found in the CSV`

Augias recognises the usual column headings of French and English bank exports: a date, a label, and either an amount or separate debit and credit columns. Some banks use other headings. Download the `CAMT.053` or `OFX` version of the statement instead, or rename the columns in a spreadsheet before importing.

### `This statement is in USD, the account in EUR`

The file belongs to another account. Import it into the account in that currency, or add one.

### An operation has no suggestion

Only documents with exactly the same amount are suggested, and a recorded payment only within ten days of the bank's date. A partial payment, or a transfer covering several invoices, has to be recorded from the invoices themselves.
