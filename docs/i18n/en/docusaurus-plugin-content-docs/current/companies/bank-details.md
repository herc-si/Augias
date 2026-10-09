---
title: Bank details
description: Enter your bank, IBAN and BIC once, so clients can pay your invoices by transfer.
sidebar_position: 6
---

# Bank details

Give your bank details once, and every invoice your clients still have to pay tells them how to pay you by transfer.

## Enter your bank details

1. Open `Settings` and the `Company` tab.
2. In the `Bank details` box, fill in:
   - `Bank`: your bank's name, such as `Crédit Agricole`. Optional.
   - `IBAN`: your account number. Paste it with or without spaces; it is checked and kept in groups of four, as on a bank statement.
   - `BIC`: your bank's identifier, 8 or 11 characters. Optional, but recommended for transfers from abroad.
3. Click `Save settings`.

An IBAN with a typing mistake is refused when you save: the check digits are verified.

## Where they appear

Once an invoice is finalised and until it is paid, its PDF carries a `Payment by bank transfer` box next to the totals: your bank, IBAN, BIC, and the invoice number your client should quote as the reference of the transfer, so the payment finds its invoice.

The box is not printed on:

- a draft, which has no number yet;
- an invoice already paid or cancelled;
- quotes and credit notes.

A disbursement note still to be paid carries the same box, with its own number as the reference.

When you send invoices electronically, the same details travel in the Factur-X data as a SEPA credit transfer, so your client's software can prepare the payment on its own.

Leave the IBAN empty to print nothing.

## Use an account from the bank page

If you import statements under `Accounting` › `Bank`:

- the form to add an account is filled in with the bank details of your invoices, as long as no account has that IBAN yet;
- the account whose IBAN is on your invoices is marked `On your invoices`;
- on another account with an IBAN, `Put on my invoices` makes it the one your invoices print. Its name becomes the bank's name and, since the BIC belongs to the bank, the BIC is cleared: check it under the `Company` tab, where you are taken.

Only members allowed to change the settings can do this.

## Troubleshooting

### My bank details are not on an invoice

Check that the invoice is finalised, not a draft, and that it is not paid yet. Check also that the IBAN is filled in: without it nothing is printed, even if the bank and BIC are.

### I used to enter my IBAN under Design

Your IBAN and BIC have moved to the `Company` tab with their values. Nothing to enter again.
