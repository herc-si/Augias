---
title: Customising your documents
description: Choose a design for your invoices and quotes, set your brand colour, and add a footer to every page.
sidebar_position: 5
---

# Customising your documents

Make your invoices, quotes and credit notes look like your company: a design, your logo, your colour, and a footer on every page. Open `Settings` and the `Design` tab.

## Your logo and details

The logo, company name, address, email and phone number shown on documents come from the `System` tab of the settings. Upload your logo there. It appears on invoices, quotes, credit notes and disbursement notes.

## Choose a design

Under `Invoice & Quote Template`, pick one of the designs. Each card shows a preview; click it to open a full preview with sample data. The design applies to your invoice and quote PDFs, the emails that send them and the page clients open from the link.

Credit notes keep a layout of their own, matching the invoices they correct.

:::info
On the hosted service, the choice of design is part of the plans that include custom templates. On a self-hosted install it is always available.
:::

## Brand colour

Enter your colour as a hexadecimal code, such as `#1e4976`, in `Brand colour`. It colours the total and balance rows, the terms heading and the payment button on every design, and the heading of credit notes. The text on it turns white or dark automatically so that it stays readable.

Leave the field empty to keep each design's own colours.

## Footer

`Footer` is text repeated at the foot of every page, up to 400 characters. It is the place for what French law asks invoices to carry beyond the basics: legal form and share capital, registration number, late payment penalties, the fixed recovery fee.

The page leaves room for the footer, so longer text never runs over the content.

Your bank details are not in the footer: they have their own place under the `Company` tab, and are printed next to the totals of the invoices clients still have to pay. See [Bank details](./bank-details.md).

## Troubleshooting

### My colour does not show

The value must be a hexadecimal colour code: `#` followed by three or six characters from `0`-`9` and `a`-`f`, such as `#1e4976` or `#fc0`. Anything else is ignored and the design's own colours are used.

### The design I chose is not used

On the hosted service, the design applies only while your plan includes custom templates. If your plan changed, documents fall back to the default design until you choose a plan that includes them.
