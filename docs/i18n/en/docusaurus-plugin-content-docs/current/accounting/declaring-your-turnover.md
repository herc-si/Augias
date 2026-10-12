---
title: Declaring your turnover
description: Watch your turnover against the limits of your regime and prepare the figures you have to file.
sidebar_position: 4
---

# Declaring your turnover

Augias works out what you have to report and what it will cost you. It files nothing on your behalf — you copy the figures onto the collecting body's own site, then record here that you did.

## Where you stand this year

Click `Accounting` in the sidebar. The turnover card, headed with the current year (`2026 turnover`), shows what you have received since 1 January, split by activity when there is more than one, and a bar for every limit that has something to measure.

Three limits apply under the French micro-entreprise regime:

| Limit | What it means |
|---|---|
| `Regime ceiling` | Pass it two years running and you leave the micro regime. |
| `VAT threshold` | Pass it and you become liable for VAT. |
| `VAT threshold (upper limit)` | The tolerance above the threshold. |

A limit marked `pro rata` has been scaled down because you started trading part-way through the year — that only applies to the regime ceiling, never to the VAT thresholds.

:::warning
The rates and thresholds shipped with Augias have not been verified against an official source. Check them on the collecting body's site before acting on them. Every screen that shows a figure repeats this.
:::

## Threshold alerts

Once a day, Augias compares your turnover for the year with each limit and raises an alert the first time you reach 80% of one, and again the first time you pass it. Alerts appear on the accounting page, under a card headed with the year (`2026 threshold alerts`), and are emailed to anyone who has subscribed to the `Turnover Threshold Reached` notification in their profile.

Each milestone is raised once per year. Falling back below a limit clears nothing — annual turnover does not go down, and the crossing happened.

:::info
The daily check runs as a scheduled task. On Docker and the single binary it runs by itself; on a manual installation, set up the [background worker](../installation-guide/distribution-package/cron-job-setup.md) or the alerts will never fire.
:::

## Open a declaration

Click `Declarations` at the top of the accounting page. The list shows every period of the current year with:

- `Books` — whether the period is `Open` or `Closed`.
- `Declaration` — `Not computed`, `Draft`, `Ready to file` or `Filed`.
- `Due` — what the period will cost.

The list is of periods rather than declarations, so a quarter you closed and then forgot is visible as one you have not declared.

Click `Open` on a period to see its figures.

## The figures to report

The declaration itemises what you owe rather than giving one total, because that is how you have to type it into the collecting body's form:

| Column | Meaning |
|---|---|
| `Line` | The charge — `Social contributions`, `Professional training levy`, `Flat-rate income tax`. |
| `Base` | The turnover the rate was applied to. |
| `Rate` | The percentage used. |
| `Amount` | What that line costs. |

`Turnover for the period` sits above them and `Total due` below. When ACRE applies, the social line reads `Social contributions (ACRE relief applied)`.

A period with no receipts says so: *Nothing was received in this period, so there is nothing to declare.*

## File it, then record that you did

While the period is still open the declaration is a `Draft` that refreshes as entries arrive, and the page tells you *The period is still open — close it to freeze the figures before filing.*

[Close the period](./closing-a-period.md) and the declaration becomes `Ready to file`. The `Filing` card then offers `File online`, which opens the collecting body's site, and a short form:

1. Copy the figures onto that site.
2. Enter the `Reference` it gave you back.
3. Add `Notes` if you want to.
4. Click `Record as filed`.

:::info
Recording a filing is one-way. From that point the declaration is the record of what you actually sent, and Augias stops recalculating it — so later entries, or a change of rates, can never rewrite a return that has already gone in.
:::

## Related

- [Closing a period](./closing-a-period.md)
- [Setting up accounting](./setting-up-accounting.md)
