# Proxmox LXC template

🇳🇱 [Nederlands](proxmox-lxc.nl.md)

A ready-made Proxmox container with Spotweb built in: Debian 13, PHP 8.5
(FPM), nginx and MariaDB, tuned for Spotweb and for running behind
Cloudflare. On its first start the container installs Spotweb by itself and
shows the address, user name and password on the console. You only log in
and enter your usenet server.

## Install

1. In Proxmox open **Datacenter → (your node) → local → CT Templates →
   Download from URL** and paste:

   ```
   https://github.com/rhisijmuiden/spotweb/releases/download/lxc-template/debian-13-spotweb_amd64.tar.zst
   ```

   Press *Query URL*, then *Download*. (Or download the file yourself and use
   *Upload*. The checksum is next to it as `.sha256`.)
2. **Create CT** with that template. Advised: unprivileged, *Nesting* on
   (Options → Features), 2 CPU cores, 2 GB memory (4 GB or more for a big
   database), 20 GB disk or more, network with DHCP or a fixed address.
3. Start the container and open its **Console**. After about half a minute
   the login screen shows:

   ```
   Spotweb  ->  http://192.168.1.50/
   Username:    spotadmin
   Password:    Xk7pQ2mZr9TfWc4H
   ```

   You see the same when you log in as root (`spotweb-info`).
4. Open the address, log in, and go to **Admin → Settings → News server**.
   Enter your usenet server. Retrieving starts by itself within 15 minutes
   (or start it right away in **Admin → Retrieve**).

Change the password under **My account** and then run
`spotweb-info --hide-password` on the console, so the first password is no
longer shown on the login screen. The password stays in
`/root/spotweb-credentials` until you do that.

## What the first start does

`spotweb-firstboot` runs once per container and gives every container its
own secrets:

- SSH host keys and a MariaDB buffer pool of about a third of the container
  memory (between 256 MB and 8 GB).
- A database `spotweb` with user `spotweb` and a random password, in
  `/var/www/spotweb-config/dbsettings.inc.php` (outside the webroot).
- The Spotweb tables and the administrator `spotadmin` with a random
  password (the built-in `admin` account gets the same password and a new
  API key). The system type is *shared*: everybody must log in, and new
  accounts are made by you in the admin centre.
- Starts the retrieve timer. It runs `retrieve.php` every 15 minutes, but
  only once a usenet server is set.

Log: `journalctl -u spotweb-firstboot`.

## What is inside

| Part | Where |
| --- | --- |
| Spotweb (git checkout of `develop`) | `/var/www/spotweb` |
| Database settings | `/var/www/spotweb-config/dbsettings.inc.php` |
| nginx site | `/etc/nginx/sites-available/spotweb.conf` |
| Cloudflare real visitor address | `/etc/nginx/conf.d/cloudflare-realip.conf`, refreshed weekly |
| PHP-FPM pool | `/etc/php/8.5/fpm/pool.d/spotweb.conf` |
| MariaDB tuning | `/etc/mysql/mariadb.conf.d/60-spotweb.cnf` |
| Retrieve | `spotweb-retrieve.timer`, log in `journalctl -u spotweb-retrieve` |

The API for Prowlarr, Sonarr and Radarr is at `http://<address>/api`. Let
them use the container's LAN address rather than going through Cloudflare.

**HTTPS.** The container serves plain HTTP on port 80. Put it behind
Cloudflare (proxy or tunnel) or behind your own reverse proxy; Spotweb
detects HTTPS from the proxy headers by itself, see
[webserver.md](webserver.md).

**Updates.** Spotweb is a git checkout, so **Admin → About & updates** can
update it and switch between the production (`main`) and test (`develop`)
channel. The template starts on `develop`, because it has the newest code.
Debian packages: `apt update && apt full-upgrade`.

**Backups.** Use Proxmox backups (vzdump) of the container. A database dump:
`mysqldump --single-transaction --quick spotweb | gzip > spotweb.sql.gz`.

## Building the template yourself

The template is built by `contrib/proxmox-lxc/build-template.sh`. The GitHub
workflow *Proxmox LXC template* runs it on every change to the template
files on `develop` (or by hand under **Actions → Proxmox LXC template → Run
workflow**) and publishes the result on the `lxc-template` release, at the
fixed address above.

To build it on the Proxmox host (or any Debian/Ubuntu machine) as root:

```sh
apt install git zstd curl
git clone https://github.com/rhisijmuiden/spotweb.git
cd spotweb
contrib/proxmox-lxc/build-template.sh --out /var/lib/vz/template/cache
```

The script downloads the official Proxmox `debian-13-standard` template,
installs everything in it (`contrib/proxmox-lxc/provision.sh`) and packs
`debian-13-spotweb_<date>-<commit>_amd64.tar.zst`. Options: `--branch`,
`--source`, `--base FILE` (use a base template you already have, for
example from `/var/lib/vz/template/cache`), `--debootstrap`, `--php`,
`--out`. See `build-template.sh --help`.

`provision.sh` also works on its own, to install the same setup on a plain
Debian 13 VM or container: `contrib/proxmox-lxc/provision.sh` as root. The
first-start script then installs Spotweb right away and prints the address
and password.
