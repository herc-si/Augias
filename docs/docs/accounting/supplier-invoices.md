---
title: Recording supplier invoices
description: Record the invoices your suppliers send you, typed in or read from their Factur-X file.
sidebar_position: 6
---

# Recording supplier invoices

Record the invoices your suppliers send you so that what you owe, and the VAT you can deduct, are known. In the sidebar, open `Services`, click `Purchase Invoices`, then `Add Bill`.

Invoices your suppliers send through the electronic invoicing platform arrive on their own and need no typing. For the others, there are two ways in.

## From a Factur-X file

Many suppliers already send a `Factur-X` PDF: an ordinary-looking PDF with the invoice's data embedded in it.

1. At the top of the `Add Bill` page, in the `Import a Factur-X invoice` box, choose the supplier's PDF (or the invoice's XML file on its own).
2. Click `Import`.

Augias reads the supplier, the invoice number, the issue and due dates, the total and the VAT from the invoice itself, and nothing is guessed. The supplier is matched to an existing one by its SIREN or VAT number, then by its name, or created.

The bill opens as a draft for you to check, with the file attached. Save it, then confirm it as you would any bill. The supplier's file stays available from the bill's page, under `See the supplier's invoice`.

:::info
A PDF without embedded data, such as a scan or a photo of a receipt, cannot be read this way. Augias says so, and you type the invoice in below.
:::

## Typed in

Fill in the form: the supplier (pick one or type a new name), the invoice number and dates, the total and, if you charge VAT, the VAT amount and whether it is goods or services.

## Troubleshooting

### `This file holds no readable Factur-X invoice`

The PDF carries no invoice data. Ask your supplier whether they can send Factur-X, which becomes the rule for French businesses in September 2027, or type the invoice in.

### `This file is a supplier credit note`

Supplier credit notes are not handled yet. Record the refund as a negative entry in your books, or lower the corresponding bill.
