# Proxmox LXC-template

🇬🇧 [English](proxmox-lxc.md)

Een kant-en-klare Proxmox-container met Spotweb erin: Debian 13, PHP 8.5
(FPM), nginx en MariaDB, afgesteld op Spotweb en op werken achter
Cloudflare. Bij de eerste start installeert de container Spotweb zelf en
toont hij het adres, de gebruikersnaam en het wachtwoord op de console. Jij
logt alleen in en vult je nieuwsserver in.

## Installeren

1. Open in Proxmox **Datacenter → (je node) → local → CT Templates →
   Download from URL** en plak:

   ```
   https://github.com/rhisijmuiden/spotweb/releases/download/lxc-template/debian-13-spotweb_amd64.tar.zst
   ```

   Klik *Query URL* en daarna *Download*. (Of download het bestand zelf en
   gebruik *Upload*. De checksum staat ernaast als `.sha256`.)
2. **Create CT** met die template. Advies: unprivileged, *Nesting* aan
   (Options → Features), 2 CPU-cores, 2 GB geheugen (4 GB of meer voor een
   grote database), 20 GB schijf of meer, netwerk met DHCP of een vast adres.
3. Start de container en open de **Console**. Na ongeveer een halve minuut
   staat op het inlogscherm:

   ```
   Spotweb  ->  http://192.168.1.50/
   Username:    spotadmin
   Password:    Xk7pQ2mZr9TfWc4H
   ```

   Hetzelfde zie je als je inlogt als root (`spotweb-info`).
4. Open het adres, log in en ga naar **Admin → Settings → News server**.
   Vul je nieuwsserver in. Het ophalen van spots start vanzelf binnen 15
   minuten (of start het meteen in **Admin → Retrieve**).

Wijzig het wachtwoord onder **My account** en voer daarna
`spotweb-info --hide-password` uit op de console, zodat het eerste
wachtwoord niet meer op het inlogscherm staat. Tot die tijd staat het ook in
`/root/spotweb-credentials`.

## Wat de eerste start doet

`spotweb-firstboot` draait één keer per container en geeft elke container
zijn eigen geheimen:

- SSH-hostkeys en een MariaDB-bufferpool van ongeveer een derde van het
  containergeheugen (tussen 256 MB en 8 GB).
- Een database `spotweb` met gebruiker `spotweb` en een willekeurig
  wachtwoord, in `/var/www/spotweb-config/dbsettings.inc.php` (buiten de
  webroot).
- De Spotweb-tabellen en de beheerder `spotadmin` met een willekeurig
  wachtwoord (het ingebouwde account `admin` krijgt hetzelfde wachtwoord en
  een nieuwe API-sleutel). Het systeemtype is *shared*: iedereen moet
  inloggen, en nieuwe accounts maak jij aan in het beheercentrum.
- Zet de retrieve-timer aan. Die draait `retrieve.php` elke 15 minuten, maar
  pas als er een nieuwsserver is ingesteld.

Log: `journalctl -u spotweb-firstboot`.

## Wat erin zit

| Onderdeel | Waar |
| --- | --- |
| Spotweb (git-checkout van `develop`) | `/var/www/spotweb` |
| Database-instellingen | `/var/www/spotweb-config/dbsettings.inc.php` |
| nginx-site | `/etc/nginx/sites-available/spotweb` |
| Echt bezoekersadres achter Cloudflare | `/etc/nginx/conf.d/cloudflare-realip.conf`, wekelijks ververst |
| PHP-FPM-pool | `/etc/php/8.5/fpm/pool.d/spotweb.conf` |
| MariaDB-afstelling | `/etc/mysql/mariadb.conf.d/60-spotweb.cnf` |
| Retrieve | `spotweb-retrieve.timer`, log via `journalctl -u spotweb-retrieve` |

De API voor Prowlarr, Sonarr en Radarr staat op `http://<adres>/api`. Laat
ze het LAN-adres van de container gebruiken in plaats van via Cloudflare.

**HTTPS.** De container serveert gewone HTTP op poort 80. Zet hem achter
Cloudflare (proxy of tunnel) of achter je eigen reverse proxy; Spotweb
herkent HTTPS zelf aan de proxy-headers, zie [webserver.md](webserver.md).

**Updates.** Spotweb is een git-checkout, dus **Admin → About & updates**
kan het bijwerken en wisselen tussen het productie- (`main`) en testkanaal
(`develop`). De template start op `develop`, omdat daar de nieuwste code
staat. Debian-pakketten: `apt update && apt full-upgrade`.

**Back-ups.** Gebruik Proxmox-back-ups (vzdump) van de container. Een
database-dump: `mysqldump --single-transaction --quick spotweb | gzip > spotweb.sql.gz`.

## Zelf de template bouwen

De template wordt gebouwd door `contrib/proxmox-lxc/build-template.sh`. De
GitHub-workflow *Proxmox LXC template* draait dat bij elke wijziging aan de
templatebestanden op `develop` (of met de hand via **Actions → Proxmox LXC
template → Run workflow**) en zet het resultaat op de release
`lxc-template`, op het vaste adres hierboven.

Bouwen op de Proxmox-host (of een andere Debian/Ubuntu-machine), als root:

```sh
apt install git zstd curl
git clone https://github.com/rhisijmuiden/spotweb.git
cd spotweb
contrib/proxmox-lxc/build-template.sh --out /var/lib/vz/template/cache
```

Het script downloadt de officiële Proxmox-template `debian-13-standard`,
installeert alles erin (`contrib/proxmox-lxc/provision.sh`) en pakt het in
als `debian-13-spotweb_<datum>-<commit>_amd64.tar.zst`. Opties: `--branch`,
`--source`, `--base BESTAND` (een basistemplate die je al hebt, bijvoorbeeld
uit `/var/lib/vz/template/cache`), `--debootstrap`, `--php`, `--out`. Zie
`build-template.sh --help`.

`provision.sh` werkt ook los, om dezelfde opzet op een gewone Debian 13-VM
of -container te zetten: `contrib/proxmox-lxc/provision.sh` als root. Het
eerste-startscript installeert Spotweb dan meteen en toont adres en
wachtwoord.
