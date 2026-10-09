---
title: Installing Augias on your phone
description: Add Augias to your phone's home screen so it opens like an app.
sidebar_position: 6
---

# Installing Augias on your phone

Add Augias to your home screen so it opens full screen, like an app, with its own icon. There is nothing to download from a store.

## On Android

1. Open Augias in Chrome and sign in.
2. Tap the `⋮` menu, then `Install app` (or `Add to home screen`).
3. Confirm. The Augias icon appears on your home screen and in your app drawer.

## On iPhone and iPad

1. Open Augias in Safari and sign in.
2. Tap the share button, then `Add to Home Screen`.
3. Confirm with `Add`.

## What it does and does not do

The installed app is Augias itself, opened without the browser's address bar. It is always up to date: there is nothing to update.

It needs a network connection. Without one, it shows a message rather than an error page. Augias deliberately keeps no copy of your invoices or clients on the phone, so nothing can be read on a lost or shared device once you are signed out.

## Troubleshooting

### `Install app` does not appear in Chrome's menu

The browser only offers to install a site served over HTTPS. A hosted Augias always is. A self-hosted instance reached by a plain `http://` address, such as a local network or VPN address, cannot be installed: put it behind HTTPS, or use `Add to home screen`, which adds a shortcut without the full-screen window.

### The app still shows an old version

Close it completely and open it again. If that is not enough, open Augias once in the browser itself: the app picks up the change on its next start.
