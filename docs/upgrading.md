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

### From the admin centre

Open **Admin → About & updates** (also in the *Admin* menu at the top). It
shows the installed version, the build (git commit) and the channel, and
checks GitHub for newer versions.

* **Check for updates** compares your build with the newest commit of your
  channel and lists the changes.
* **Update now** installs it and then upgrades the database by itself; the
  page follows the progress and reloads when it is done. Spotweb is
  unavailable for a short while. Your settings files, `cache/` and the
  database are kept.

**Channels.** *Production* follows the `main` branch (tested versions), *Test*
follows `develop` (newest changes, can contain bugs). Picking the other
channel and pressing *Update now* switches the installation. Spotweb refuses
to switch to a version with an older database layout, because a database
cannot be taken back; wait until that branch has caught up.

**How the files are replaced** (*How updates are installed*):

| Method | When it works | What it does |
|---|---|---|
| Automatic | always | git when possible, otherwise *Download from GitHub* |
| Download from GitHub | PHP has the `zip` and `curl` extensions and may write to the Spotweb folder | downloads the zip of the new commit, checks that every file can be written, keeps a backup of the replaced files in `cache/updater/backup/` and puts them back if anything fails |
| git | PHP may run programs (`proc_open`) and the folder is a clean git checkout | `git fetch`, `git checkout <branch>`, `git merge --ff-only` |
| Hosting panel deploy webhook | your hosting panel has a deploy URL | asks the panel to deploy; set the panel to the same branch |

Many hosting setups disable `exec`/`proc_open`; then *Automatic* uses the
download. A GitHub token is only needed for a private fork or when GitHub's
limit of 60 requests per hour per server is reached.

If the database step is interrupted (for example by a webserver timeout),
the next page view finishes it, or use *Run the database upgrade again*. Running
`php bin/upgrade-db.php` from a shell always works as well.

A fork can point the updater elsewhere in `ownsettings.php`:

```php
$settings['updater_repo'] = 'you/spotweb';
$settings['updater_branches'] = ['production' => 'main', 'test' => 'develop'];
```

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

## Where the database settings live

Spotweb reads the database settings from the first of these that exists:

1. the file named in the `SPOTWEB_DBSETTINGS` environment variable;
2. `spotweb-config/dbsettings.inc.php` **next to** the Spotweb folder, so
   outside the webroot;
3. `dbsettings.inc.php` in the Spotweb folder (where the install wizard
   writes it).

Option 2 is recommended: the file is then out of reach of the webserver, and
a `git pull` or a fresh ZIP never touches it. To move it:

```sh
cd /path/to/spotweb
mkdir -p ../spotweb-config
mv dbsettings.inc.php ../spotweb-config/
chmod 640 ../spotweb-config/dbsettings.inc.php
```

If PHP runs with `open_basedir`, the parent folder must be allowed, otherwise
keep the file where it is.

Without a settings file, Spotweb also reads `SPOTWEB_DB_ENGINE`
(`pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`), `SPOTWEB_DB_HOST`,
`SPOTWEB_DB_PORT`, `SPOTWEB_DB_NAME`, `SPOTWEB_DB_USER`, `SPOTWEB_DB_PASS`
and `SPOTWEB_DB_SCHEMA` from the environment, which is handy in Docker.

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
