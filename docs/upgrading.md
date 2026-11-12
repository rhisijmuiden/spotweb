# Upgrading an existing Spotweb installation

You can upgrade an existing Spotweb installation in place. Your database
(spots, comments, users, filters and settings), `dbsettings.inc.php` and
`ownsettings.php` are kept.

## Before you start

1. **Make a database backup.** For MariaDB/MySQL this works while Spotweb
   keeps running:

   ```sh
   mysqldump --single-transaction --quick spotweb | gzip > spotweb-$(date +%F).sql.gz
   ```

2. **Keep a copy of your settings files:** `dbsettings.inc.php` and, if you
   have them, `ownsettings.php` and `reallymyownsettings.php`.
3. **Pause the retriever** (comment out the `retrieve.php` cron job or stop
   its timer), so it does not run halfway through the upgrade.

## Upgrade

### Installed with git

```sh
cd /path/to/spotweb
git fetch origin
git checkout develop          # or the branch you run
git pull --ff-only
php bin/upgrade-db.php
```

### Installed from a ZIP file

1. Download the ZIP of the branch you run from
   <https://github.com/rhisijmuiden/spotweb>.
2. Unpack it **over** your existing folder (this keeps your settings files),
   or unpack it next to it and copy `dbsettings.inc.php`, `ownsettings.php`
   and `reallymyownsettings.php` into the new folder.
3. Run `php bin/upgrade-db.php` from the Spotweb folder.

### Finish

1. Turn the retriever back on.
2. Open Spotweb in your browser and hard-refresh once (Ctrl+F5 / Cmd+Shift+R)
   to load the new styles.
3. If you use Prowlarr, Sonarr or Radarr: run *Test* on the indexer. The API
   URL stays the same.

`bin/upgrade-db.php` only adds what is missing (tables, columns, indexes and
new settings) and never removes your data. Run it after every upgrade; running
it twice does no harm.

## Large databases

On a database with millions of spots the first upgrade-db run after a
release that adds an index can take a few minutes. Run it from a shell (not
through the browser) so it is not stopped by a web timeout. MariaDB and MySQL
add normal indexes online, so Spotweb stays usable while it runs.

## Webserver, `/api` and Cloudflare

- The Newznab API at `<spotweb>/api` no longer needs the `.htaccess` rewrite
  rule. nginx and Caddy examples are in [webserver.md](webserver.md).
- Behind Cloudflare or a reverse proxy on the same machine or LAN, Spotweb
  uses the visitor's real IP address and detects HTTPS by itself. Forwarding
  headers from other sources are ignored, so they cannot be spoofed.

## Something went wrong?

- **Blank page:** check the webserver error log. Most often it is a syntax
  error in `ownsettings.php`.
- **"Please run bin/upgrade-db.php":** run that command as shown above.
- **Roll back:** check out the previous version (`git checkout <previous-commit>`)
  and restore the database backup you made before you started.
