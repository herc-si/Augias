<div align="center">

<img src="https://github.com/user-attachments/assets/6f45c11d-d73e-423e-be4a-30cdf2fe819d" alt="Augias" width="100%" />

# Augias

**The open-source invoicing platform for freelancers and small businesses.**

Send beautiful quotes and invoices, accept online payments, automate recurring billing — and own every byte of your data.

<p>
  <a href="https://github.com/herc-si/Augias/blob/HEAD/LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" /></a>
  <a href="https://github.com/herc-si/Augias/releases"><img alt="Latest Release" src="https://img.shields.io/github/v/release/herc-si/Augias?include_prereleases&style=flat-square" /></a>
  <a href="https://www.php.net/"><img alt="PHP 8.4+" src="https://img.shields.io/badge/php-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white" /></a>
  <a href="https://symfony.com/"><img alt="Symfony 7" src="https://img.shields.io/badge/symfony-7.1-000000?style=flat-square&logo=symfony" /></a>
  <a href="https://github.com/herc-si/Augias/stargazers"><img alt="GitHub Stars" src="https://img.shields.io/github/stars/herc-si/Augias?style=flat-square" /></a>
</p>

<p>
  <a href="https://github.com/herc-si/Augias"><img src="https://img.shields.io/badge/Star-on%20GitHub-181717?style=for-the-badge&logo=github" alt="Star on GitHub" /></a>
</p>

<img src="docs/static/img/dashboard.png" alt="Augias Dashboard" width="100%" />

</div>

---

## Why Augias?

Most invoicing tools force a trade-off: easy to use *or* respectful of your data. Augias gives you both. It's a mature, production-ready billing platform you can run on your own server for free, or let us host for a flat **$8/month** — no per-client limits, no surprise tiers, no lock-in. Built on Symfony 7 and PHP 8.4, it's designed to be extended, integrated, and trusted.

---

## ✨ Features

### 💼 Billing & Invoicing
- Quotes that convert into invoices in one click
- Recurring invoices on flexible schedules
- Multi-currency support (real `Money` objects — no float rounding)
- Multi-tax support with invoice-level tax and automatic rate snapshot on issue
- Flat-rate and percentage tax types, plus per-line discounts
- 8 built-in PDF templates for invoices and quotes
- Automatic overdue detection with configurable notifications
- Payment reminders sent on a schedule you define
- Create a new client directly from the invoice or quote form
- Invoice state machine (draft → pending → paid)

### 👥 Clients & Contacts
- Full client and contact management
- Custom fields for clients and contacts
- Per-client currency, addresses and contact channels
- Multi-tenancy out of the box (run multiple companies from one install)

### 🔐 User & Security
- Two-Factor Authentication (2FA) via TOTP
- Google OAuth login
- User email verification
- Role-based access control with Symfony Security & Voters
- Guided onboarding flow with a checklist for new users

### 💳 Payments
- Bring-your-own Stripe, PayPal and other gateways via [Payum](https://payum.gitbook.io/payum/)
- Online payment links sent with invoices
- PCI-compliant — no card data ever touches your server

### 🔌 Integrations & API
- REST API (JSON-LD, JSON-HAL, JSON, XML) powered by [API Platform 4](https://api-platform.com/)
- Token-based auth (`X-API-TOKEN`)
- Built-in MCP server with OAuth2 for AI agent automation
- Meilisearch integration for fast full-text search across all data
- Grid export and full company data export
- Notifications via email, SMS and chat channels

### 🛡 Privacy & Ownership
- 100% self-hostable — your database, your rules
- Encrypted secrets, Doctrine multi-tenancy filters
- MIT licensed — fork it, modify it, ship it

### 🚀 Modern Stack
- Symfony 7.1, PHP 8.4, Doctrine ORM, API Platform 4
- Tabler UI on Bootstrap 5.3 — fully responsive, mobile-friendly
- Stimulus, Webpack Encore, Bun, Sass
- Helm charts for Kubernetes, opt-in Prometheus metrics
- Symfony Messenger for async task processing
- ULID primary keys, PHPStan level 6, ECS, Rector

---

## 🏠 Self-Hosted vs. ☁️ Hosted

Both versions ship the same codebase and feature set. Pick whichever fits your workflow.

|                              | 🏠 **Self-Hosted** (Free, MIT) | ☁️ **Hosted** ($8/month)         |
| ---------------------------- | ------------------------------ | --------------------------------- |
| Price                        | Free forever                   | Flat $8/mo — no per-client fees   |
| Setup                        | You install & maintain         | Zero setup — sign up and send     |
| Updates                      | Manual                         | Automatic                         |
| Backups                      | You manage                     | Daily, managed for you            |
| Branding                     | Yours                          | Augias branding removed     |
| Early access to new features | —                              | ✅                                |
| Data ownership               | Full                           | Full — export anytime             |
| Best for                     | Tinkerers, privacy-first teams | Anyone who wants to invoice today |

---

## 📸 Screenshots

| | |
| :---: | :---: |
| <img src="docs/static/img/dashboard.png" alt="Dashboard" /><br/>**Dashboard** | <img src="docs/static/img/managing-clients/client-view-overview.png" alt="Client View" /><br/>**Client View** |
| <img src="docs/static/img/invoices/invoice-list.png" alt="Invoice List" /><br/>**Invoice List** | <img src="docs/static/img/invoices/create-invoice-form.png" alt="Invoice Editor" /><br/>**Invoice Editor** |
| <img src="docs/static/img/recurring-invoices/recurring-invoices-list-page.png" alt="Recurring Invoices" /><br/>**Recurring Invoices** | <img src="docs/static/img/payments.png" alt="Payments" /><br/>**Payments** |

---

## 🚀 Quick Start

### Option 1 — Docker Compose from source (recommended)

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
docker compose -f docker-compose.dev.yml up
```

Composer and frontend dependencies install themselves into named volumes on the
first run, so nothing needs to be present on the host. The application is served
on `http://localhost:8765`.

### Option 2 — Single binary

Get up and running in seconds with a self-contained binary — no PHP, no web server, no extensions to install.

**Direct binary download:**

Grab the latest binary for your platform from the [releases page](https://github.com/herc-si/Augias/releases), make it executable, and run it:

```bash
chmod +x augias
./augias run
```

That's it — open `http://localhost:8765` and you're invoicing.

### Option 4 — From source (for developers)

```bash
git clone https://github.com/herc-si/Augias.git
cd Augias
composer install
bun install && bun run dev
```

For production builds:

```bash
bun run build
```

**Requirements:** PHP 8.4+, ext-curl, ext-gd, ext-intl, ext-openssl, ext-pdo, ext-soap, ext-xsl, MySQL/MariaDB or PostgreSQL.

---

## 🛠 Tech Stack

**Backend:** Symfony 7.1 · PHP 8.4 · Doctrine ORM · API Platform 4 · Payum · MoneyPHP
**Frontend:** Tabler · Bootstrap 5.3 · Stimulus · Webpack Encore · Bun · Sass
**Quality:** PHPStan (level 6) · ECS · Rector · PHPUnit · Foundry · GitHub Actions

---

## 📚 Documentation

- 📖 Docs & guides — the `docs/` directory in this repository
- 🔄 Upgrading — [`UPGRADE.md`](UPGRADE.md)
- 📝 Changelog — [`CHANGELOG.md`](CHANGELOG.md)

---

## 🤝 Contributing

We love contributions of every shape — code, docs, translations, bug reports, ideas. Look for the [`good first issue`](https://github.com/herc-si/Augias/labels/good%20first%20issue) label to get started, then read the [contributing guide](CONTRIBUTING.md) and our [code of conduct](CODE_OF_CONDUCT.md).

---

## 🔒 Security

Found a vulnerability? Please **do not** open a public issue. See [`SECURITY.md`](SECURITY.md) for our responsible disclosure process.

---

## 💖 Acknowledgements

Augias is a fork of **[SolidInvoice](https://github.com/SolidInvoice/SolidInvoice)**
by Pierre du Plessis / SolidWorx, released under the MIT License. Essentially all
of the application below the rebrand is their work.

The upstream project is supported by **[JetBrains](https://www.jetbrains.com/)**
(PhpStorm licenses), **[Docker](https://www.docker.com/)** (Docker Hub) and
**[Sentry](https://sentry.io/)** (Business plan). Those sponsorships are theirs,
not this fork's — they are listed here as credit, not as a claim.

---

## 📄 License

Augias is open-source software released under the [MIT License](LICENSE),
inherited from SolidInvoice. The copyright notice of the original author is
retained in `LICENSE` and in every source file, as the licence requires.

---

<div align="center">

**[Releases](https://github.com/herc-si/Augias/releases)** · **[Docs](docs/)** · **[Upstream project](https://github.com/SolidInvoice/SolidInvoice)**

Built on [SolidInvoice](https://github.com/SolidInvoice/SolidInvoice) by [SolidWorx](https://solidworx.co) and its [contributors](https://github.com/SolidInvoice/SolidInvoice/graphs/contributors).

</div>

<!-- GitAds-Verify: 5A777YN6A52PDTET1VL1VHZGIO89ZZT5 -->
