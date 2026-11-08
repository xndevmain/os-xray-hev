# os-xray-hev — fork of MrTheory/os-xray

An experimental OPNsense plugin combining **Xray-core**, **HevSocks5Tunnel**, and Unbound/PF destination lists.

This is a fork of [MrTheory/os-xray](https://github.com/MrTheory/os-xray), originally authored by Pavel. The original BSD-2-Clause license and copyright are preserved. This fork is independently maintained by [xndevmain](https://github.com/xndevmain); it is not an official OPNsense plugin or an upstream-author release.

[Русская инструкция](README.ru.md) · [Audit report](docs/PUBLIC-AUDIT.txt) · [Security and limitations](docs/SECURITY.txt)

**Status: 4.1.0-beta.1.** Tested host: OPNsense 26.7, FreeBSD 15.1 amd64, PHP 8.5, Xray 26.10.31, HevSocks5Tunnel 2.19.3 and Unbound 1.25.1. Other versions are not claimed supported. Use a configuration backup and retain console access.

## Changes in this fork

- One Xray/Hev tunnel; multiple selected endpoints with Observatory/leastPing failover.
- VLESS, VMess, Trojan and Shadowsocks URI/Base64/Xray JSON imports and managed HTTPS subscriptions.
- Per-server enabled state survives updates and renames. New servers are disabled; removed servers retain a tombstone. Up to 32 selected distinct servers and 1024 catalog records.
- Subscription refresh schedules, bounded HTTPS downloads with public-address/redirect checks, runtime validation and activation rollback.
- A Routing tab for geosite/geoip categories and custom domain/hostname lists. Unbound adds DNS answers to the external `XRAY_HOSTS` PF alias; **OPNsense firewall rules decide which traffic enters the tunnel**.
- Multiple native downloadable IP routing entries. Each combines up to 16 HTTPS IP/CIDR feeds and inline networks into its own stable external alias. A Discord voice preset and port-scoped LAN-rule helper are included.
- Interface, gateway and rule setup helpers, endpoint checks, native tables and EN/RU localization.

## Installation

Obtain Xray-core and HevSocks5Tunnel from their upstream projects and verify their distributions. Install executable files as `/usr/local/bin/xray-core` and `/usr/local/bin/hev-socks5-tunnel`. Python 3 with sqlite3 and dnspython, PHP 8.3+ with curl and Phalcon, and OPNsense Unbound with its Python module are required. The installer does not download or replace binary dependencies.

Extract the complete release archive, verify its SHA-256 against the release checksum, then as root:

```sh
sh install.sh --check
sh install.sh
```

The installer backs up existing files/configuration, checks models/templates before copying files, and preserves running tunnel processes. Review configuration and click Apply after installation. Automatic migration supports the current single-tunnel outbound JSON schema. Legacy wizard schemas and multiple instances are rejected before replacement and require a manual migration; do not install over them blindly.

1. Open **VPN → Xray → Subscriptions**, add a named HTTPS URL; the initial download starts immediately. Alternatively import links/files in **Endpoints**.
2. Enable the required endpoints; new subscription servers are disabled by default. Click **Apply**.
3. In **General**, enable Xray and configure the tunnel. Create/assign its interface, then its peer gateway. For `/30` assignments the helper chooses the other usable address.
4. In **Routing**, download community databases, choose categories/custom domains, and click **Apply Firewall Lists**. Add any number of **Downloaded IP routing lists**; each entry may combine several source URLs. Use **Download and apply all** to refresh their native aliases.
5. For a downloaded list, use its filter button to create native LAN rules. When destination UDP ports are configured, the helper creates one scoped rule per range; review and apply them in the standard Firewall UI.
6. Review the LAN rule using destination `XRAY_HOSTS` and the tunnel gateway. It must precede a generic LAN allow rule while retaining earlier restrictions. The helper saves a rule; review and apply it in the native firewall UI.
7. Clients must use OPNsense Unbound for domain-driven lists. Verify both endpoint health and the actual LAN → firewall → TUN path.

A subscription's refresh interval is checked every five minutes. Turning off an endpoint is persistent. Changing credentials/address/transport creates a new disabled identity. Missing servers are excluded; returning identities retain their previous choice. Existing manual endpoints are not automatically assigned to a subscription because old imports did not retain source URLs. Disabling the last selected endpoint requires disabling Xray first.

Automatic activation occurs only when the running configuration matches the previously saved configuration. Pending manual changes or a stopped service require a later Apply; refresh does not deliberately start a stopped tunnel. Existing connections may need to reconnect after failover.

## Removal and recovery

```sh
sh install.sh uninstall
```

Removal stops plugin processes, detaches the Unbound callback and regenerates cron. It retains config.xml settings, binary dependencies, logs and geodata. Review/remove firewall rules, gateways and interface assignments that you created. Backup paths are printed by the installer under `/root/os-xray-backup.*`. Restore compatible code and configuration together; do not overwrite newer unrelated firewall settings with an old full backup.

## Development

```sh
python3 tools/check.py
python3 tests/failover.py /path/to/xray
python3 tools/build_release.py
```

`tools/check.py` requires PHP, Python and Node.js. FreeBSD root integration tests (`tests/freebsd_unbound.py`) use an isolated Unbound process and test PF table. Release archives use an explicit allowlist; local test output, credentials, router backups and internal deployment notes are excluded. CI runs on GitHub-hosted runners with read-only repository permissions.

Upstream dependencies: [Xray-core](https://github.com/XTLS/Xray-core), [HevSocks5Tunnel](https://github.com/heiher/hev-socks5-tunnel), [OPNsense](https://github.com/opnsense/core), [v2fly community lists](https://github.com/v2fly/domain-list-community), [ITDog lists](https://github.com/itdoginfo/allow-domains). Their licenses and update policies remain independent.
