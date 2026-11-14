# Changelog

Changes in this fork ([rhisijmuiden/spotweb](https://github.com/rhisijmuiden/spotweb))
on top of upstream Spotweb, newest first. For a guided tour with screenshots
see **[docs/CHANGES.md](docs/CHANGES.md)** (🇳🇱 [Nederlands](docs/CHANGES.nl.md)).

"Schema" means the database changes: run `php bin/upgrade-db.php` (or update
from *Admin → About & updates*, which does it for you) after updating.

## 2026-11-14

- [#22](https://github.com/rhisijmuiden/spotweb/pull/22) Keyboard shortcuts ignore typing in every text field.
- [#21](https://github.com/rhisijmuiden/spotweb/pull/21) Radarr & Sonarr: library labels on spots and add missing titles on download. **Schema 0.75.**
- [#20](https://github.com/rhisijmuiden/spotweb/pull/20) Spot list: resizable columns, remembered per user, with reset.

## 2026-11-13

- [#19](https://github.com/rhisijmuiden/spotweb/pull/19) Admin → About & updates: version, update check, update from the browser, production (`main`) / test (`develop`) channel.
- [#18](https://github.com/rhisijmuiden/spotweb/pull/18) Own SMTP mail server (STARTTLS/SSL, DKIM), test mail and password reset by e-mail.
- [#17](https://github.com/rhisijmuiden/spotweb/pull/17) Fast API and RSS feed for Prowlarr on large databases: response cache, time-window queries, new index. **Schema 0.74.**
- [#16](https://github.com/rhisijmuiden/spotweb/pull/16) Admin sets the default spot list view and can lock it.
- [#15](https://github.com/rhisijmuiden/spotweb/pull/15) *My account* moved to the user menu; *Config* renamed to *Admin*.
- [#14](https://github.com/rhisijmuiden/spotweb/pull/14) No error popup when a spot cannot be opened.
- [#13](https://github.com/rhisijmuiden/spotweb/pull/13) Spot page redesign: split view next to the list, one action bar, tabs, `j`/`k` browsing.
- [#12](https://github.com/rhisijmuiden/spotweb/pull/12) Phones: compact top bar, slide-in menu, list with the key columns; fix saving the admin settings.
- [#11](https://github.com/rhisijmuiden/spotweb/pull/11) Spot page: calm header with labelled actions, plain fact list, tidy sender row.
- [#10](https://github.com/rhisijmuiden/spotweb/pull/10) Database settings outside the webroot or from environment variables; SAB button always visible in the list.
- [#9](https://github.com/rhisijmuiden/spotweb/pull/9) Modern statistics page with interactive charts.
- [#8](https://github.com/rhisijmuiden/spotweb/pull/8) PHP 8.5 clean-up, no-cache error pages, calmer table view.

## 2026-11-12

- [#7](https://github.com/rhisijmuiden/spotweb/pull/7) API limits per user, *My account* with download history, retrieve and backfill from the admin centre.
- [#6](https://github.com/rhisijmuiden/spotweb/pull/6) Admin activity: requests per channel, most active users, spot range.
- [#5](https://github.com/rhisijmuiden/spotweb/pull/5) API and RSS switches in the admin centre.
- [#4](https://github.com/rhisijmuiden/spotweb/pull/4) Five extra colour themes, picked in *Admin → Appearance*.
- [#3](https://github.com/rhisijmuiden/spotweb/pull/3) Admin centre with a left menu and a registration setting.
- [#2](https://github.com/rhisijmuiden/spotweb/pull/2) New house style, Cloudflare support and an upgrade guide.
- [#1](https://github.com/rhisijmuiden/spotweb/pull/1) Faster Newznab API; `/api` works without `.htaccess`.

Older history of upstream Spotweb: see [`changelog`](changelog) and [`change.md`](change.md).
