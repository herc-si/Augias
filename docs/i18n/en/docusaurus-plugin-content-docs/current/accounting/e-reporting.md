---
title: Following e-reporting
description: See what Augias reports of your sales to private individuals, and what your platform has sent the tax administration.
sidebar_position: 7
---

# Following e-reporting

A sale to a private individual does not go through electronic invoicing: it is reported. Augias sends every sale, credit note, payment and refund to your electronic invoicing platform, which aggregates them and sends them to the tax administration on the schedule of your VAT regime.

In the sidebar, open `Accounting`, then `E-reporting`.

:::info
E-reporting goes through your platform. Until one is connected, the page shows `No platform connected`: click `Choose a platform`. See [SUPER PDP account connection](../integrations/super-pdp.md).
:::

## What is reported

What makes a sale one to a private individual is the client type chosen on their record: `Private individual`. See [Creating a client](../managing-clients/create-new-client.md).

Augias reports:

- every invoice issued to a private individual;
- every credit note against one, and every refund;
- every payment received on a private individual's invoice.

For an invoice that went out by electronic invoicing, Augias also tells the platform it was paid (`Invoice paid (B2B)`), and likewise when you pay a purchase invoice received through the platform (`Purchase paid (B2B)`).

Only documents issued after the platform was connected are reported. Augias files what is waiting every hour; click `Synchronise now` not to wait.

On the page of an invoice or credit note to a private individual, an `E-reporting` card says where its report stands: `Reported on …`, `Will be reported on the next run, within the hour.`, or the error message if filing failed.

## Current period

The `Current period` card shows what the platform will send at the next deadline, from what Augias has reported so far:

- `Sales`: amount excluding VAT and VAT, per day and category (`Goods`, `Services`…).
- `Payments`: amount received including VAT, per day and VAT rate.

If the platform does not answer, the card shows `The platform could not be reached:` followed by its reply.

## Declarations sent

The `Declarations sent` card lists what the platform sent the tax administration, with the period and status: `Sent to the administration`, `Received`, `Accepted`, `Rejected` or `Unreadable`. It is updated every hour.

The platform sends a declaration at the end of each period; until the first deadline, the card stays empty.

:::warning
A `Rejected` or `Unreadable` declaration was not taken into account by the administration. Augias tells you once, with an alert. Check with your platform what was refused.
:::

## What Augias reported

The `What Augias reported` card lists each filing: its date, kind (`Sale`, `Credit note`, `Payment`, `Refund`…), the document concerned and its status, `Filed` or `Failed`.

A failed filing is tried again every hour, or right away with `Synchronise now`.

## See also

- [Declaring your turnover](./declaring-your-turnover.md)
- [Credit notes](../invoices/credit-notes.md)
