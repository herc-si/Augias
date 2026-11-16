---
title: Importing clients
description: Bring a client list in from a spreadsheet or another tool, from a CSV file.
sidebar_position: 7
---

# Importing clients

Bring in, in one go, the client list you kept in a spreadsheet or in another tool, from a CSV file. You see what would be created before anything is imported.

## Prepare the file

From the client list, click `Import`, then `Download the template` to get an example file to fill in. You can also start from your previous tool's export.

The file holds one line per client, and its first line names the columns. The columns can come in any order and only the `Nom` (name) column is required.

| Column | What it holds |
|---|---|
| `Nom` | The client's name. Required. |
| `Type` | `Entreprise` (business, the default) or `Particulier` (private individual). `Company` and `Individual` work too. |
| `SIRET` | The client's SIRET. Required for a business. |
| `N° TVA` | Its VAT number. |
| `E-mail`, `Prénom`, `Nom du contact` | The contact to write to: e-mail address, first name, last name. The contact is created when the line carries an e-mail address. |
| `Site web` | The client's website. |
| `Adresse`, `Complément d'adresse`, `Code postal`, `Ville`, `Pays` | Its address: street, second line, postcode, city, country. The country is written in full or as its code (`France` or `FR`). |
| `Devise` | Its currency code (`EUR`). See [Client currency](./client-currency.md). |

The usual titles are recognised as well, in French and in English: "Raison sociale", "Company", "Zip"… A column that is not recognised is ignored, and the preview tells you so.

:::info
The separator (semicolon or comma) and the file's encoding are detected: a file saved by Excel as "CSV" works as it is. A file holds 2,000 lines and weighs 1 MB at most.
:::

## Check the preview

Choose your file, then click `See the preview`. Nothing is imported at this point. The preview shows:

- how many **clients would be created**;
- the clients **already there**: a line whose name already exists among your clients, archived ones included, is left out;
- the **lines refused**, with their number in the file and what stands in the way.

A line is refused when it breaks a rule of the client form: a business without a SIRET, a malformed e-mail address, an empty name. The rules are those of [creating a client](./create-new-client.md).

:::tip
To bring in a refused line, fix it in the file and import the file again: the clients already created are recognised and are not created twice.
:::

## Import

Click `Import these clients`. The account that follows gives the same figures, this time for what was done, and `See the clients` takes you back to the list.

The import only ever adds: it never changes or deletes an existing client.

## Related

- [Client list](./client-list.md)
- [Creating a client](./create-new-client.md)
