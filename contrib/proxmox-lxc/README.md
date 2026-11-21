# Proxmox LXC template

Build scripts for the Spotweb Proxmox container template. See
[docs/proxmox-lxc.md](../../docs/proxmox-lxc.md) (🇳🇱 [Nederlands](../../docs/proxmox-lxc.nl.md)).

- `build-template.sh` builds `debian-13-spotweb_<date>-<commit>_amd64.tar.zst` (run as root).
- `provision.sh` installs and configures everything inside the Debian 13 system.
- `rootfs/` files copied into the container: nginx site, PHP/MariaDB tuning,
  systemd units and the `spotweb-*` helper scripts.
