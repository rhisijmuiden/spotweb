# What's new in this Spotweb build

🇳🇱 [Nederlandse versie](CHANGES.nl.md) · 📋 [Changelog per pull request](../CHANGELOG.md)

This fork of [Spotweb](https://github.com/spotweb/spotweb) adds a completely new
look, a full admin centre, a much faster API for Prowlarr/Sonarr/Radarr, its own
mail server settings, updates from the browser and Radarr & Sonarr integration.
Everything upgrades in place: your spots, users, filters and settings are kept
(see [upgrading.md](upgrading.md)).

All screenshots below come from a test installation with sample data.

**Contents**

1. [New house style and themes](#1-new-house-style-and-themes)
2. [Spot list: cards or table](#2-spot-list-cards-or-table)
3. [Spot page redesign](#3-spot-page-redesign)
4. [Phones](#4-phones)
5. [Statistics page](#5-statistics-page)
6. [Admin centre](#6-admin-centre)
7. [User menu and My account](#7-user-menu-and-my-account)
8. [Fast API and RSS](#8-fast-api-and-rss)
9. [SABnzbd](#9-sabnzbd)
10. [Radarr & Sonarr](#10-radarr--sonarr)
11. [E-mail: own SMTP server and password reset](#11-e-mail-own-smtp-server-and-password-reset)
12. [About & updates: production and test channel](#12-about--updates-production-and-test-channel)
13. [Under the hood](#13-under-the-hood)

---

## 1. New house style and themes

A calm, modern look: dark first, one violet accent, menus on the left, Inter
and Space Grotesk fonts. The design tokens live in
`templates/modern/css/brand.css` and are documented in
[brand/README.md](brand/README.md).

Besides the automatic light/dark mode there are **five extra colour themes**:
Midnight, Daylight, Ocean, Forest, Ember and Paper. The admin picks the site
default in *Admin → Appearance*; every user can switch in the top bar.

![Spot list in the dark theme](screenshots/spot-list-dark.png)

| Daylight | Ocean |
|---|---|
| ![Daylight theme](screenshots/list-view-daylight.png) | ![Ocean theme](screenshots/list-view-ocean.png) |

## 2. Spot list: cards or table

- **Cards** (posters with title and details) or a compact **table** with one row per spot.
- The admin sets the default view and can **lock** it for everyone.
- **Resizable columns** in the table view: drag a column edge, widths are
  remembered per user, with a reset button in the header.
- Send to **SABnzbd** straight from the list (one click, check mark when done).

![Resizable columns](screenshots/resizable-columns.png)

![SAB button in the list](screenshots/list-sab-button.png)

## 3. Spot page redesign

- **Split view**: the spot opens next to the list on wide screens; browse with
  `j` / `k` or the arrows.
- One **action bar** (Send to SABnzbd, Download NZB, Watch, Search).
- Tabs for **Description**, **Comments** and **All details**, with a calm
  fact list and a tidy sender row ("Verified sender", spam reports).
- No more error popup when a spot cannot be opened.
- Keyboard shortcuts ignore every typing field (search, URL, e-mail, ...).

![Spot page next to the list](screenshots/spot-page-split-view.png)

| Daylight | Full page |
|---|---|
| ![Spot page daylight](screenshots/spot-page-daylight.png) | ![Spot page](screenshots/spot-page-full.png) |

## 4. Phones

Compact top bar, slide-in menu, a list with only the key columns and a spot
page with the actions at the bottom of the screen.

| List | Menu | Spot page |
|---|---|---|
| ![Phone list](screenshots/phone-list.png) | ![Phone menu](screenshots/phone-menu.png) | ![Phone spot page](screenshots/phone-spot-page.png) |

## 5. Statistics page

The old static PNG graphs are replaced by interactive charts: new spots per
day, per category, per hour, per weekday and per month, with periods from
"last 24 hours" to "everything". Works in every theme.

![Statistics](screenshots/statistics-dark.png)

## 6. Admin centre

All site settings in one place at `/admin`, with a menu on the left:

- **General, Users & registration** (open registration, or only accounts
  created by the admin), **Appearance** (default theme and view, custom CSS).
- **Retrieve**: start a retrieve from the browser with a live progress bar;
  **backfill** older spots when you move the first-spot date back, without
  emptying the database.
- **News servers, Retrieval & cleanup, Performance & cache.**
- **Activity**: requests per day per channel (API, web, RSS), the most
  active users and through which channel, and the range of spots in the
  database.
- **API & RSS**: switch the API and RSS feed on or off, optional request
  limits (throttling) per user, response cache.
- **E-mail** and **About & updates** (see below).

![Admin activity](screenshots/admin-activity.png)

![Admin appearance](screenshots/admin-appearance.png)

## 7. User menu and My account

*My account* moved into the user menu (top right). It has the personal
settings, the API key and a **download history** that shows whether you downloaded a spot yourself or an
API client (for example Prowlarr) grabbed it.

![User menu](screenshots/user-menu.png)

## 8. Fast API and RSS

Made for Prowlarr, Sonarr and Radarr on large databases (1M+ spots):

- `/api` works without `.htaccess` or rewrite rules.
- Searches for the newest spots look in time windows (1 day, 7 days,
  60 days, then everything) instead of walking the whole index.
- New database index for category searches.
- A **response cache** for API searches and the RSS feed (default 10 minutes,
  `X-Spotweb-Cache` header shows hits); it is cleared automatically when new
  spots arrive.

## 9. SABnzbd

The SAB button is always visible as a small pill in the list and in the
action bar of the spot page. One click sends the NZB to SABnzbd and the
button shows a check mark when it is done.

## 10. Radarr & Sonarr

Every user can connect their own Radarr and Sonarr under *My account*:

- Movie and series spots show whether the title is **already in your
  library**, missing or not monitored.
- Downloading a missing title first **adds it to Radarr/Sonarr**, then sends
  the NZB to SABnzbd in the matching category so Radarr/Sonarr import the
  result.
- The admin can switch the integration off site-wide.

Details: [arr.md](arr.md).

![Radarr and Sonarr labels in the list](screenshots/arr-list.png)

| Add to Radarr | Settings |
|---|---|
| ![Add dialog](screenshots/arr-add-dialog.png) | ![Radarr and Sonarr settings](screenshots/arr-settings.png) |

![Spot page with Radarr status](screenshots/arr-spot-page.png)

## 11. E-mail: own SMTP server and password reset

- Your **own SMTP server** with STARTTLS or SSL/TLS, login, certificate check
  and optional **DKIM** signing.
- A **test e-mail** button that shows the full conversation with the mail
  server when something goes wrong.
- **Forgot password** page with a signed, one-time link (valid for 60 minutes).
- Welcome mail with a link to choose a password, instead of a password in the mail.

Details: [email.md](email.md).

![Admin e-mail settings](screenshots/admin-email.png)

| Test mail | Reset mail | Forgot password (phone) |
|---|---|---|
| ![Test mail](screenshots/admin-email-test.png) | ![Password reset mail](screenshots/mail-password-reset.png) | ![Forgot password](screenshots/forgot-password-phone.png) |

## 12. About & updates: production and test channel

*Admin → About & updates* shows the version, build (git commit), channel and
PHP version, and:

- **Checks for updates** on GitHub and lists the new changes.
- **Updates from the browser**, including the database upgrade, with a
  backup and rollback. Works with git, or without it (ZIP download) on hosts
  where `exec` is disabled.
- Switch between **Production** (`main`, tested) and **Test** (`develop`,
  newest changes).

![About & updates](screenshots/admin-about-updates.png)

## 13. Under the hood

- **PHP 8.5** compatible, deprecations cleaned up.
- MySQL/MariaDB schema on **InnoDB / utf8mb4** (from upstream Spotweb).
- Database settings can live **outside the webroot** (or in environment variables).
- Works well **behind Cloudflare** and other proxies: real visitor IP,
  HTTPS detection, no caching of personal pages or error pages
  (see [webserver.md](webserver.md)).
- Error pages return `503` with `no-store`, so a proxy never caches them.
- English by default, fully translated into **Dutch**.
