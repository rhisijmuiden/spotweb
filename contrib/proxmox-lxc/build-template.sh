#!/bin/sh
# Builds a Proxmox LXC template with Spotweb built in:
#
#   debian-13-spotweb_<date>-<commit>_amd64.tar.zst
#
# Upload it in Proxmox (Datacenter > <storage> > CT Templates > Upload) or copy
# it to /var/lib/vz/template/cache/ and create a container from it.
#
# Run as root on Debian/Ubuntu or on the Proxmox host itself:
#
#   sudo contrib/proxmox-lxc/build-template.sh [options]
#
# Options:
#   --branch NAME     Spotweb branch to bake in (default: develop)
#   --source DIR|URL  Spotweb git repository (default: the repository this
#                     script is in, else GitHub). Only committed work is used.
#   --base FILE       Proxmox Debian 13 base template (.tar.zst/.tar.xz/.tar.gz).
#                     Default: download the newest debian-13-standard from
#                     download.proxmox.com.
#   --debootstrap     Build the base system with debootstrap instead.
#   --php VERSION     PHP version (default: 8.5)
#   --out DIR         Where to write the template (default: current directory)
#
# Needs: tar, zstd, curl, git (and debootstrap with --debootstrap).
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
REPO_URL=https://github.com/rhisijmuiden/spotweb.git
BRANCH=develop
SOURCE=""
BASE=""
USE_DEBOOTSTRAP=0
PHP_VERSION=8.5
OUT=$(pwd)
PVE_IMAGES=http://download.proxmox.com/images/system

while [ $# -gt 0 ]; do
    case "$1" in
        --branch) BRANCH=$2; shift 2 ;;
        --source) SOURCE=$2; shift 2 ;;
        --base) BASE=$2; shift 2 ;;
        --debootstrap) USE_DEBOOTSTRAP=1; shift ;;
        --php) PHP_VERSION=$2; shift 2 ;;
        --out) OUT=$2; shift 2 ;;
        -h|--help) sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

log() { echo "==> $*"; }
die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "run as root (the template keeps file owners and permissions)"
for tool in tar zstd curl git; do
    command -v "$tool" >/dev/null 2>&1 || die "$tool is missing (apt install $tool)"
done

if [ -z "$SOURCE" ]; then
    if git -C "$HERE" rev-parse --show-toplevel >/dev/null 2>&1; then
        SOURCE=$(git -C "$HERE" rev-parse --show-toplevel)
    else
        SOURCE=$REPO_URL
    fi
fi
mkdir -p "$OUT"
OUT=$(cd "$OUT" && pwd)

WORK=$(mktemp -d /var/tmp/spotweb-lxc.XXXXXX)
ROOT=$WORK/rootfs
mkdir -p "$ROOT"

cleanup() {
    for m in dev/pts dev sys proc; do
        mountpoint -q "$ROOT/$m" 2>/dev/null && umount -l "$ROOT/$m"
    done
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

# 1. Base system --------------------------------------------------------------
if [ "$USE_DEBOOTSTRAP" = 1 ]; then
    command -v debootstrap >/dev/null 2>&1 || die "debootstrap is missing (apt install debootstrap)"
    log "Debian 13 base system with debootstrap"
    debootstrap --variant=minbase --include=systemd,systemd-sysv,dbus,ca-certificates,ifupdown \
        trixie "$ROOT" http://deb.debian.org/debian
    cat > "$ROOT/etc/apt/sources.list" <<'EOF'
deb http://deb.debian.org/debian trixie main
deb http://deb.debian.org/debian trixie-updates main
deb http://security.debian.org/debian-security trixie-security main
EOF
else
    if [ -z "$BASE" ]; then
        log "Looking up the newest Proxmox Debian 13 base template"
        NAME=$(curl -fsS "$PVE_IMAGES/" | grep -o 'debian-13-standard_[^"<]*_amd64\.tar\.zst' | sort -uV | tail -1)
        [ -n "$NAME" ] || die "no debian-13-standard template found on $PVE_IMAGES (use --base or --debootstrap)"
        BASE=$WORK/$NAME
        log "Downloading $NAME"
        curl -fSL --retry 3 -o "$BASE" "$PVE_IMAGES/$NAME"
    fi
    [ -f "$BASE" ] || die "base template $BASE not found"
    log "Unpacking $(basename "$BASE")"
    case "$BASE" in
        *.zst) zstd -dc "$BASE" | tar --numeric-owner -xpf - -C "$ROOT" ;;
        *) tar --numeric-owner -xpf "$BASE" -C "$ROOT" ;;
    esac
fi
grep -q '^13' "$ROOT/etc/debian_version" 2>/dev/null || die "base system is not Debian 13"

# 2. Provision inside the root filesystem -------------------------------------
log "Preparing chroot"
cp -a "$ROOT/etc/resolv.conf" "$WORK/resolv.conf.orig" 2>/dev/null || true
rm -f "$ROOT/etc/resolv.conf"
cp /etc/resolv.conf "$ROOT/etc/resolv.conf"
mount -t proc proc "$ROOT/proc"
mount -t sysfs sys "$ROOT/sys"
mount --bind /dev "$ROOT/dev"
mount --bind /dev/pts "$ROOT/dev/pts"

mkdir -p "$ROOT/tmp/spotweb-build"
cp -a "$HERE/provision.sh" "$HERE/rootfs" "$ROOT/tmp/spotweb-build/"
log "Spotweb $BRANCH from $SOURCE"
git clone --quiet --bare --branch "$BRANCH" "$SOURCE" "$ROOT/tmp/spotweb-src.git"
COMMIT=$(git --git-dir="$ROOT/tmp/spotweb-src.git" rev-parse --short HEAD)

chroot "$ROOT" /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 \
    IN_CHROOT=1 SPOTWEB_SRC=/tmp/spotweb-src.git SPOTWEB_BRANCH="$BRANCH" \
    SPOTWEB_REPO="$REPO_URL" PHP_VERSION="$PHP_VERSION" \
    sh /tmp/spotweb-build/provision.sh

rm -rf "$ROOT/tmp/spotweb-build" "$ROOT/tmp/spotweb-src.git"
for m in dev/pts dev sys proc; do umount -l "$ROOT/$m"; done

# Proxmox writes resolv.conf, hostname and hosts when the container starts.
rm -f "$ROOT/etc/resolv.conf"
if [ -e "$WORK/resolv.conf.orig" ] || [ -L "$WORK/resolv.conf.orig" ]; then
    cp -a "$WORK/resolv.conf.orig" "$ROOT/etc/resolv.conf"
else
    : > "$ROOT/etc/resolv.conf"
fi

DATE=$(date -u +%Y%m%d)
cat > "$ROOT/etc/spotweb-template" <<EOF
SPOTWEB_BRANCH=$BRANCH
SPOTWEB_COMMIT=$COMMIT
PHP_VERSION=$PHP_VERSION
BUILT=$(date -u +%FT%TZ)
EOF

# 3. Pack ----------------------------------------------------------------------
FILE=$OUT/debian-13-spotweb_${DATE}-${COMMIT}_amd64.tar.zst
log "Packing $(basename "$FILE")"
tar --numeric-owner --xattrs --xattrs-include='*' -C "$ROOT" -cpf - . | zstd -q -T0 -19 -o "$FILE" -f
(cd "$OUT" && sha256sum "$(basename "$FILE")" > "$(basename "$FILE").sha256")
log "Done: $FILE ($(du -h "$FILE" | cut -f1))"
