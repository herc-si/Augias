4.0.0-beta1
===========

**The application was renamed from SolidInvoice to Augias.** This is a hard
break with no compatibility layer, so read this section before upgrading.

* Every `SOLIDINVOICE_*` environment variable is now `AUGIAS_*`. Rename them in
  your `.env`, systemd unit, Docker Compose file or Helm values before starting
  the new version; nothing falls back to the old names, so a missed variable
  silently reverts to its default.
* The PHP namespace `SolidInvoice\` is now `Augias\`, and every bundle class
  follows (`SolidInvoiceCoreBundle` → `AugiasCoreBundle`). Any code of your own
  that extends or references these classes must be updated.
* Data paths changed with the names: the default SQLite file is `augias.db`
  rather than `solidinvoice.db`, and the Meilisearch index prefix is `augias_`.
  **Rename the database file and reindex Meilisearch**, or the application will
  come up against an empty database and an empty search index.
* The `app_config.field_type` column stores fully-qualified class names. Rows
  written before the upgrade still name `SolidInvoice\…` form types and will
  not resolve; settings screens fail until they are rewritten to `Augias\…`.
* The npm package is `augias`, the Docker image `herc-si/augias`, and the Helm
  chart moved from `helm/solidinvoice` to `helm/augias`.
* The application was upgraded to **Symfony 8.1** and now requires **PHP 8.4.1 or
  higher**.
* The **Sms77** and **Gitter** notification transports were removed, as the
  underlying Symfony bridges are discontinued. If you had a notification
  transport configured for one of these services, configure a different
  transport after upgrading.
* All existing remember-me cookies are invalidated by the upgrade; users simply
  need to log in again.
* Client website URLs are now validated to require a proper domain (URLs such as
  `http://localhost` are rejected).

2.3.17
======

* API tokens are now stored as HMAC-SHA256 hashes (keyed by `SOLIDINVOICE_APP_SECRET`)
  instead of plaintext. The `Version20317` migration re-hashes all existing tokens
  in place, so previously issued tokens continue to work without user action.
* Existing tokens are no longer recoverable from the database or visible in the UI.
  After upgrading, the management page only lists token names; the value itself is
  shown exactly once at creation time and must be copied immediately.
* Rotating `SOLIDINVOICE_APP_SECRET` now invalidates all API tokens (previously it
  only invalidated sessions). After rotating the secret, users must generate new
  API tokens.

2.0.0
=====

* `SolidInvoice\NotificationBundle\Notification\ChainedNotificationInterface::addNotifications` and `SolidInvoice\NotificationBundle\Notification\ChainedNotification::addNotifications` has been renamed to `addNotification`
