---
title: Credit notes
description: Correct an issued invoice with a credit note, settle it by offset or refund, and send it to the client.
sidebar_position: 8
---

# Credit notes

A credit note corrects an invoice that has already been issued: a cancellation, a return, a rebate, a commercial gesture or a mistake. It has its own number, is kept like an invoice, and is settled either by setting it against an invoice or by refunding the client.

## Why a credit note rather than a cancellation

An issued invoice cannot be withdrawn: it took its number in a sequence that must stay unbroken, and the client has received it. To cancel it, even before any payment, you raise a credit note against it. Only a draft can be cancelled or edited freely.

When an [accounting regime](../accounting/setting-up-accounting.md) is set up, Augias enforces this: a pending or overdue invoice can no longer be cancelled or edited, and the message points to the credit note.

## Raise a credit note from an invoice

This is the most common case.

1. Open the invoice. It must be pending, overdue or paid.
2. In the `More Actions` menu, click `Raise a credit note`.

The form opens already filled in: the client, the invoice under `Invoice being credited`, the reason `Cancellation`, the invoice's lines and its discount. Adjust the lines to credit only part of the invoice.

:::info
The invoice's discount is carried over because the client paid the discounted amount: crediting the full amount would give back more than they paid. The `Discount` field only appears once an invoice is chosen.
:::

## Raise a credit note with no original invoice

For a rebate or a commercial gesture that relates to no particular invoice:

1. In the sidebar, open `Credit Notes`, then click `Create Credit Note`.
2. Choose the client, then the `Reason`: `Cancellation`, `Return`, `Rebate`, `Commercial gesture` or `Error correction`.
3. Leave `Invoice being credited` on `No specific invoice`, or choose an invoice. Only the client's issued invoices are offered: pending, overdue or paid.
4. Add one line per item credited.

The credit note's terms are filled in with the credit notes' text, not the invoices'. See [Default terms](./default-terms.md#credit-notes).

## Save or issue

At the bottom of the form:

- `Save draft` keeps the credit note editable. A draft has no number yet.
- `Issue` gives it its number and fixes it.
- `Issue and send` does the same, then emails it to the chosen contacts.

The number follows the prefix and suffix set under `Settings`, `Credit notes` tab (by default `AV-`, then the number, then the year).

:::warning
An issued credit note can no longer be edited or deleted: it is kept like an invoice. If it is wrong, raise another one.
:::

When issued, the credit note's amount is added to the [client's credit](../managing-clients/client-credit.md).

## Settle a credit note

The page of an issued credit note shows a `Settlement` box with what is `Still owed`. To record what was done with it:

1. Choose `How`:
   - `Set against an invoice`: the amount is deducted from what the client owes on another of their invoices. Choose that invoice.
   - `Refunded`: you gave the money back to the client. No invoice to choose.
2. Enter the `Amount`, at most what is still owed, the `Date` and, if needed, `Notes`.
3. Click `Record`.

A credit note can be settled in several goes. When nothing is still owed, it moves to `Settled`. Each settlement is deducted from the client's credit.

:::info
In your books, a refund is money going out: it is recorded on the date entered. An offset is not recorded on its own, since the payment that follows is simply smaller. See [Your books](../accounting/your-books.md).
:::

## Send a credit note

If you did not send it when issuing, open the credit note and click `Send to the client`. The button only appears when the credit note has at least one contact. The PDF is attached to the email and names the invoice being credited.

## Find credit notes

- `Credit Notes` in the sidebar lists every credit note with its status: `Draft`, `Issued` or `Settled`.
- The `Credit Notes` tab on a client's page lists theirs.

## See also

- [Invoice statuses](./invoice-statuses.md)
- [Client credit](../managing-clients/client-credit.md)
