---
title: Sending emails
description: Configure how Augias sends invoices, reminders and notifications by email.
sidebar_position: 9
---

# Sending emails

Augias sends invoices, quotes, payment reminders and account emails through a mail transport you configure: your own SMTP server or a delivery service. Until one is configured, nothing is sent.

## Two levels

- **A default for the whole server**, set with the `AUGIAS_MAILER_DSN` environment variable. Every company that has not configured its own delivery uses it.
- **Per company**, in `Settings` > `Email`: the `Sender Information` (address and name shown in "From") and, optionally, an `Email Delivery` service with its credentials. A company's own delivery replaces the server default for its emails.

## The server default

`AUGIAS_MAILER_DSN` takes a Symfony Mailer DSN. Some examples:

```ini
# Any SMTP server — port 587 uses STARTTLS, port 465 implicit TLS
AUGIAS_MAILER_DSN=smtp://user:password@smtp.example.com:587

# An address as the user name: write its @ as %40
AUGIAS_MAILER_DSN=smtp://invoices%40example.com:password@mail.example.com:587

# Amazon SES
AUGIAS_MAILER_DSN=ses+smtp://ACCESS_KEY:SECRET_KEY@default?region=eu-west-3
```

Characters such as `@`, `:` or `/` in the password must be URL-encoded too.

Set it where your installation reads its environment: the `environment` of the containers with Docker, the chart's values with Helm, or the server's environment. You can also keep it out of files entirely as a secret:

```bash
bin/console secrets:set AUGIAS_MAILER_DSN
```

## Per company

In `Settings` > `Email`:

1. Under `Sender Information`, set the address emails are sent from and the name shown beside it. Replies go to that address.
2. Under `Email Delivery`, leave the service empty to use the server default, or choose one — SMTP, Gmail, Mailgun, Mailchimp, Postmark, SendGrid or Amazon SES — and fill in its credentials.

:::warning
The sender address must belong to a domain that allows your transport to send for it, or your emails will land in spam. See below.
:::

## Getting emails delivered

Receiving servers check that the sender's domain authorises the server that sent the email. On the domain of your sender address, publish:

- an **SPF** record listing your transport (your provider gives the `include:` to add);
- the **DKIM** key your provider gives you, so emails are signed;
- a **DMARC** policy, starting with `p=none` while you check the results.

Then send an invoice to the address [mail-tester.com](https://www.mail-tester.com) gives you: it scores the email and says what is missing.

## Checking the configuration

Send a test email with the server default:

```bash
bin/console mailer:test you@example.com --from=invoices@example.com
```

Emails are sent by the background worker. If the test arrives but invoices do not, check that the worker (the `messenger:consume` process) is running.

## In development

The development stack (`docker-compose.dev.yml`) runs [Mailpit](https://mailpit.axllent.org/), which catches every email the application sends and shows it at `http://localhost:8025`. Nothing reaches a real address. The tests send nothing at all.

## Troubleshooting

### Nothing is sent and there is no error

No transport is configured: `AUGIAS_MAILER_DSN` still has its default, `null://null`, which discards every email, and the company has no delivery service of its own. Set one of the two.

### Emails arrive in spam

The sender's domain does not authorise your transport. Publish SPF, DKIM and DMARC records on that domain as above, or send from an address on a domain your transport is set up for.

### `Connection could not be established with host`

The host or port is wrong, or the server cannot reach it: many hosting providers block outgoing port 25. Use port 587 or 465, which your provider documents.
