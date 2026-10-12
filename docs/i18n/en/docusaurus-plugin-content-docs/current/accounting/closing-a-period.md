---
title: Closing a period
description: Seal a month or quarter of bookkeeping so its entries can no longer be changed.
sidebar_position: 3
---

# Closing a period

Closing is what turns a list of entries into books you can stand behind. It numbers them, seals them and freezes the totals — and it cannot be undone.

Periods follow your `Declaration frequency`: monthly or quarterly. They are created by the first entry filed into them, so a company that has booked nothing has no periods at all.

## Close the current period

Click `Accounting` in the sidebar. The `Current period` card shows the period you are in — `2026-Q1`, `2026-03` and so on — with its state, `Open` or `Closed`.

While it is open you get a `Close 2026-Q1` button, under the warning *Closing numbers the entries, seals them and freezes the totals. It cannot be undone.*

Close it once the last receipt of the period is in.

:::danger
There is no reopening. After closing, a mistake can only be corrected by a reversing entry in a later period — see [Correcting a sealed entry](#correcting-a-sealed-entry).
:::

## What closing does

- Every entry gets a gapless `No.` within its book, in date order.
- Each entry is fingerprinted, and each fingerprint includes the one before it, so the entries form a chain that cannot be reordered or added to.
- The entries are locked. Editing or deleting one is refused from that point on, whatever it is attempted from.
- The period's totals are stored as they stood at that moment, rather than recalculated later.

Open a sealed entry and you are sent back to the book with *This entry belongs to a closed period and can no longer be changed. Record a reversing entry in an open period instead.*

## Periods close in order

A period cannot be closed while an earlier one is still open — that would leave a hole in the numbering and break the chain. Attempting it gives *This period cannot be closed: it already is, or an earlier one is still open.*

Close the earlier period first.

## A payment that arrives late

A payment dated inside a period you have already closed cannot go into that period. Augias keeps the true date, files the entry into the earliest period still open, and flags it as late. Nothing is silently dropped and no date is quietly rewritten.

## Correcting a sealed entry

Add a reversing entry in an open period:

1. Open the book and click `New entry`.
2. Enter the amount that cancels the mistake — negative to undo a receipt.
3. Say what it reverses in `Nature of the operation`, and name the original entry's number in `Notes`.

Both the original and its reversal stay visible, which is the point.

## Checking the books are intact

Self-hosters can re-walk every sealed book and confirm that nothing has changed underneath the application:

```bash
bin/console augias:accounting:verify-ledger
```

It prints one row per company and book with the number of entries checked and the result, and exits with an error if any book no longer matches what it was sealed as — naming the entry where the chain breaks. It only reads and reports; it never repairs, because rewriting a fingerprint is exactly what sealing exists to prevent.

## Related

- [Your books](./your-books.md)
- [Declaring your turnover](./declaring-your-turnover.md)
