#!/bin/sh
# Turns a fresh Debian 13 system into a Spotweb appliance:
# nginx + PHP-FPM + MariaDB, Spotweb in /var/www/spotweb, its settings in
# /var/www/spotweb-config, retrieve timer and Cloudflare real-IP support.
#
# build-template.sh runs this inside the template's root filesystem
# (chroot). It also works on a plain Debian 13 VM or container:
#
#   SPOTWEB_SRC=/path/to/spotweb ./provision.sh
#
# No secrets are created here: spotweb-firstboot creates the database, the
# administrator and unique passwords on the first start of every container.
#
# Environment:
#   SPOTWEB_SRC      Spotweb checkout to install (default: clone SPOTWEB_REPO)
#   SPOTWEB_REPO     git URL (default https://github.com/rhisijmuiden/spotweb.git)
#   SPOTWEB_BRANCH   branch (default develop)
#   PHP_VERSION      PHP version (default 8.5)
#   USE_SURY         1 = PHP from packages.sury.org (default 1 on Debian)
#   IN_CHROOT        1 = do not start services (set by build-template.sh)
set -eu
umask 022

HERE=$(cd "$(dirname "$0")" && pwd)
SPOTWEB_REPO=${SPOTWEB_REPO:-https://github.com/rhisijmuiden/spotweb.git}
SPOTWEB_BRANCH=${SPOTWEB_BRANCH:-develop}
PHP_VERSION=${PHP_VERSION:-8.5}
IN_CHROOT=${IN_CHROOT:-0}
WEBROOT=/var/www/spotweb
CONFDIR=/var/www/spotweb-config

. /etc/os-release
if [ -z "${USE_SURY:-}" ]; then
    if [ "$ID" = debian ]; then USE_SURY=1; else USE_SURY=0; fi
fi

export DEBIAN_FRONTEND=noninteractive
log() { echo "==> $*"; }

# Keep package scripts from starting services inside a chroot.
if [ "$IN_CHROOT" = 1 ]; then
    printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d
    chmod 0755 /usr/sbin/policy-rc.d
fi

log "Base packages"
apt-get update
apt-get -y install --no-install-recommends \
    ca-certificates curl gnupg git unzip zstd less nano procps iproute2 iputils-ping \
    locales tzdata openssh-server \
    nginx mariadb-server mariadb-client
# Networking as Proxmox configures it (/etc/network/interfaces).
apt-get -y install --no-install-recommends ifupdown || true
apt-get -y install --no-install-recommends isc-dhcp-client 2>/dev/null \
    || apt-get -y install --no-install-recommends dhcpcd-base 2>/dev/null || true

if [ "$USE_SURY" = 1 ]; then
    log "PHP $PHP_VERSION repository (packages.sury.org)"
    curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i /tmp/debsuryorg-archive-keyring.deb
    rm -f /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" \
        > /etc/apt/sources.list.d/php.list
    apt-get update
fi

log "PHP $PHP_VERSION"
PHP_PKGS=""
for ext in fpm cli common mysql gd curl mbstring xml zip intl gmp bcmath opcache; do
    # opcache is built in from PHP 8.5 on and has no package of its own there.
    if apt-cache show "php$PHP_VERSION-$ext" >/dev/null 2>&1; then
        PHP_PKGS="$PHP_PKGS php$PHP_VERSION-$ext"
    fi
done
# shellcheck disable=SC2086
apt-get -y install --no-install-recommends $PHP_PKGS
update-alternatives --set php "/usr/bin/php$PHP_VERSION" 2>/dev/null || true

log "Appliance files"
cp -R --no-preserve=ownership "$HERE/rootfs/." /
chmod 0755 /usr/local/sbin/spotweb-*

# PHP-FPM pool and PHP settings for Spotweb.
PHPDIR=/etc/php/$PHP_VERSION
rm -f "$PHPDIR/fpm/pool.d/www.conf"
cat > "$PHPDIR/fpm/pool.d/spotweb.conf" <<EOF
; Spotweb appliance pool
[spotweb]
user = www-data
group = www-data
listen = /run/php/spotweb.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Prowlarr, Sonarr and Radarr poll in bursts: allow enough workers.
pm = dynamic
pm.max_children = 24
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 1000
request_terminate_timeout = 300s

php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 300
php_admin_value[error_log] = /var/log/php$PHP_VERSION-fpm-spotweb.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
EOF
cat > "$PHPDIR/mods-available/spotweb.ini" <<'EOF'
; Spotweb appliance PHP settings
date.timezone = Europe/Amsterdam
expose_php = Off
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
realpath_cache_size = 4096K
realpath_cache_ttl = 600
EOF
for sapi in fpm cli; do
    ln -sf "$PHPDIR/mods-available/spotweb.ini" "$PHPDIR/$sapi/conf.d/90-spotweb.ini"
done

# nginx: only the Spotweb site.
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/spotweb.conf /etc/nginx/sites-enabled/spotweb.conf
# Fresh Cloudflare ranges when we can reach them (the template ships a list).
/usr/local/sbin/spotweb-cloudflare-ips || true

log "Spotweb code ($SPOTWEB_BRANCH)"
rm -rf "$WEBROOT"
if [ -n "${SPOTWEB_SRC:-}" ]; then
    git clone --quiet --branch "$SPOTWEB_BRANCH" "$SPOTWEB_SRC" "$WEBROOT"
    git -C "$WEBROOT" remote set-url origin "$SPOTWEB_REPO"
else
    git clone --quiet --branch "$SPOTWEB_BRANCH" "$SPOTWEB_REPO" "$WEBROOT"
fi
mkdir -p "$WEBROOT/cache" "$CONFDIR" /var/lib/spotweb
# www-data owns the code so Admin > About & updates can update it in place.
chown -R www-data:www-data "$WEBROOT"
chown root:www-data "$CONFDIR"
chmod 0750 "$CONFDIR"
git config --system --add safe.directory "$WEBROOT"

log "Services"
systemctl enable nginx.service mariadb.service "php$PHP_VERSION-fpm.service" \
    spotweb-firstboot.service spotweb-cloudflare-ips.timer
# spotweb-firstboot starts the retrieve timer once Spotweb is installed.
systemctl disable spotweb-retrieve.timer 2>/dev/null || true

log "Locale and time zone"
sed -i 's/^# *\(en_US.UTF-8\)/\1/; s/^# *\(nl_NL.UTF-8\)/\1/' /etc/locale.gen
locale-gen >/dev/null
echo 'LANG=en_US.UTF-8' > /etc/default/locale
ln -sf /usr/share/zoneinfo/Europe/Amsterdam /etc/localtime
echo Europe/Amsterdam > /etc/timezone

if [ "$IN_CHROOT" = 1 ]; then
    log "Cleaning up for the template"
    apt-get clean
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*
    # Unique per container: generated on the first start.
    rm -f /etc/ssh/ssh_host_*
    : > /etc/machine-id
    rm -f /var/lib/dbus/machine-id
    find /var/log -type f -exec truncate -s 0 {} +
    rm -f /usr/sbin/policy-rc.d /root/.bash_history
else
    systemctl daemon-reload
    systemctl restart "php$PHP_VERSION-fpm.service" mariadb.service
    systemctl start spotweb-firstboot.service
    systemctl reload-or-restart nginx.service
    /usr/local/sbin/spotweb-info
fi

log "Spotweb appliance ready"
