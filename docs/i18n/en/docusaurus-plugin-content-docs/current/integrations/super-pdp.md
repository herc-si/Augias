---
title: SUPER PDP account connection
description: Let companies connect their SUPER PDP account to Augias in a few clicks, instead of pasting API credentials.
sidebar_position: 5
---

# SUPER PDP account connection

Augias sends electronic invoices through [SUPER PDP](https://www.superpdp.tech), a French approved platform (PA/PDP). By default, each company creates an application in its own SUPER PDP account and pastes its client ID and secret into Augias.

If your installation registers its own application with SUPER PDP, companies connect their account instead. They are sent to SUPER PDP, where they sign in or create an account, have the company verified, and give Augias access. They never copy credentials.

The integration is optional. With no application configured, the SUPER PDP settings ask for the client ID and secret as before.

## Create the application on SUPER PDP

1. In your SUPER PDP account, create an OAuth application.
2. Set its redirect URL to the SUPER PDP callback of your installation:

   ```text
   https://your-augias-domain.example/electronic-invoicing/super-pdp/callback
   ```

   The path is always `/electronic-invoicing/super-pdp/callback`. The scheme and host must be the public URL of your installation.
3. Leave the scopes empty, and copy the client ID and client secret.

:::info
A sandbox account and a production account are separate on SUPER PDP. Companies connected through a sandbox application land in the sandbox, and their invoices go nowhere real.
:::

## Configure Augias

Set two environment variables, then restart the application:

| Variable | Description |
| --- | --- |
| `AUGIAS_SUPER_PDP_CLIENT_ID` | The client ID of the application. |
| `AUGIAS_SUPER_PDP_CLIENT_SECRET` | The client secret of the application. |

Both must be set for companies to be offered the connection. Leaving either empty brings back the client ID and secret fields.

For the distribution package and source installs, add the values to `.env` at the root of the application:

```ini title=".env"
AUGIAS_SUPER_PDP_CLIENT_ID=your-client-id
AUGIAS_SUPER_PDP_CLIENT_SECRET=your-client-secret
```

:::warning
The tokens of connected companies are encrypted with the application secret (`AUGIAS_APP_SECRET`). If you change that secret, every company has to connect its account again.
:::

## Connecting a company's account

1. In the sidebar, click `Electronic Invoicing`.
2. Next to SUPER PDP, click `Configure`, give the provider a name, then click `Continue to SUPER PDP`.
3. On SUPER PDP, sign in or create the company's account. The user's email and the company's SIREN are filled in when Augias knows them.
4. Complete the company verification and give Augias access.

SUPER PDP sends the user back to Augias, which shows `SUPER PDP account connected.` If the company was still waiting for verification, Augias says so: no invoice is sent until SUPER PDP has verified the company.

The first provider a company sets up becomes the active one, used to send its invoices.

## Reconnecting

Open the SUPER PDP provider from `Electronic Invoicing`. The `SUPER PDP account` card shows whether the account is connected.

- To switch to another SUPER PDP account, click `Connect again`. The previous access is withdrawn on SUPER PDP.
- If the card says `Not connected`, Augias has no access to the account anymore and no invoice goes out. Click `Connect the account` to restore it.

A connection stops when the company withdraws Augias's access on SUPER PDP, or when the account goes unused for a year. Augias checks every active account each hour, provided its [background worker](../installation-guide/distribution-package/cron-job-setup.md) is running, so an active connection does not lapse for lack of use.

Deleting the provider in Augias also withdraws its access on SUPER PDP.

## Troubleshooting

### SUPER PDP refuses the redirect URL

The redirect URL set on the SUPER PDP application does not exactly match the one Augias sends. Check the scheme, host and path against the public URL of your installation.

### "This SUPER PDP connection has expired or was already used."

The user came back from SUPER PDP more than an hour after leaving Augias, came back in another browser, or reloaded the return page. Start again from `Electronic Invoicing`.

### The client ID and secret fields still appear

Both variables must be set and non-empty. After changing them, clear the application cache with `bin/console cache:clear`.
