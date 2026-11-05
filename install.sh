#!/bin/sh
# os-xray-hev installer. BSD-2-Clause; see LICENSE and upstream attribution.
set -eu
umask 077
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_DIR="$ROOT/plugin"
MODE=${1:-install}
fail() { echo "ERROR: $*" >&2; exit 1; }
[ "$(id -u)" = 0 ] || fail 'Run as root on OPNsense.'
[ "$(uname -s)" = FreeBSD ] && [ -f /usr/local/etc/inc/config.inc ] || fail 'OPNsense on FreeBSD is required.'
case "$MODE" in install|--check|uninstall) ;; *) fail 'Usage: sh install.sh [--check|uninstall]' ;; esac
# Serialize installers without leaving a stale mkdir lock on interruption.
if [ "${OS_XRAY_INSTALL_LOCKED:-0}" != 1 ]; then
    exec env OS_XRAY_INSTALL_LOCKED=1 lockf -t 0 /var/run/os-xray-install.lock /bin/sh "$ROOT/install.sh" "$MODE"
fi
refresh_cron() {
    /usr/local/bin/php -r 'require_once "config.inc"; require_once "util.inc"; require_once "system.inc"; require_once "plugins.inc"; system_cron_configure();'
}
backup=$(mktemp -d /root/os-xray-backup.XXXXXXXX)
chmod 700 "$backup"
cp -p /conf/config.xml "$backup/config.xml"
# Back up installed plugin trees, plus managed callback/configuration; no binaries are replaced.
: > "$backup/files.txt"
for path in usr/local/opnsense/scripts/Xray usr/local/opnsense/mvc/app/models/OPNsense/Xray \
    usr/local/opnsense/mvc/app/controllers/OPNsense/Xray usr/local/opnsense/mvc/app/views/OPNsense/Xray \
    usr/local/opnsense/service/conf/actions.d/actions_xray.conf usr/local/etc/inc/plugins.inc.d/xray.inc \
    usr/local/etc/rc.syshook.d/start/50-xray usr/local/etc/newsyslog.conf.d/xray.conf \
    usr/local/share/locale/ru_RU/LC_MESSAGES/os-xray.mo \
    usr/local/etc/unbound.opnsense.d/xray-routing.conf var/unbound/etc/xray-routing.conf \
    var/unbound/xray-routing.py usr/local/etc/xray-routing.json; do
    [ ! -e "/$path" ] || printf '%s\n' "$path" >> "$backup/files.txt"
done
tar -czpf "$backup/files.tar.gz" -C / -T "$backup/files.txt"
echo "Backup: $backup"
if [ "$MODE" = uninstall ]; then
    # Keep user firewall rules, assignments, gateways, catalog and binary dependencies.
    [ ! -x /usr/local/opnsense/scripts/Xray/xray-service-control.php ] || /usr/local/opnsense/scripts/Xray/xray-service-control.php stop
    [ ! -x /usr/local/opnsense/scripts/Xray/xray-routing-control.php ] || /usr/local/opnsense/scripts/Xray/xray-routing-control.php stop
    rm -f /usr/local/etc/unbound.opnsense.d/xray-routing.conf /var/unbound/etc/xray-routing.conf
    /usr/local/sbin/configctl unbound restart
    rm -f /var/unbound/xray-routing.py /var/unbound/run/xray-routing.sock
    rm -f /usr/local/etc/inc/plugins.inc.d/xray.inc
    refresh_cron
    rm -rf /usr/local/opnsense/scripts/Xray /usr/local/opnsense/mvc/app/models/OPNsense/Xray \
        /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray /usr/local/opnsense/mvc/app/views/OPNsense/Xray
    rm -f /usr/local/opnsense/service/conf/actions.d/actions_xray.conf /usr/local/etc/rc.syshook.d/start/50-xray \
        /usr/local/etc/newsyslog.conf.d/xray.conf /usr/local/share/locale/ru_RU/LC_MESSAGES/os-xray.mo
    service configd restart
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
    echo 'Removed. Configuration, logs, geodata and dependencies retained. Review rules that reference the tunnel.'
    exit 0
fi
[ -d "$PLUGIN_DIR" ] && [ -f "$ROOT/locale/ru_RU/LC_MESSAGES/os-xray.mo" ] || fail 'Extract the complete release archive first.'
for component in scripts/Xray mvc/app/models/OPNsense/Xray mvc/app/controllers/OPNsense/Xray mvc/app/views/OPNsense/Xray service/conf/actions.d etc/inc/plugins.inc.d etc/rc.syshook.d/start etc/newsyslog.conf.d; do
    [ -d "$PLUGIN_DIR/$component" ] || fail "Missing package component: $component"
done
[ -x /usr/local/bin/xray-core ] && [ -x /usr/local/bin/hev-socks5-tunnel ] || fail 'Install Xray-core and HevSocks5Tunnel first; this installer never downloads executable binaries.'
/usr/local/bin/php -r 'if(PHP_VERSION_ID<80300 || !extension_loaded("curl") || !extension_loaded("phalcon")) exit(1);' || fail 'PHP 8.3+, curl and Phalcon are required.'
/usr/local/bin/python3 -c 'import sqlite3, dns.resolver' || fail 'Python sqlite3 and dnspython are required.'
[ -z "$(find "$PLUGIN_DIR" -type l -print)" ] || fail 'Symlinks in the plugin source tree are not supported.'
find "$PLUGIN_DIR" -name '*.php' -type f | while IFS= read -r php_file; do
    /usr/local/bin/php -l "$php_file" || exit 1
done > "$backup/php-lint.txt"
/usr/local/bin/php "$ROOT/tools/check-install.php"
if [ "$MODE" = --check ]; then echo 'Preflight passed; no plugin or configuration changes made.'; exit 0; fi
: > "$backup/new-files.txt"
find "$PLUGIN_DIR" -type f ! -path '*/__pycache__/*' | while IFS= read -r source_file; do
    relative=${source_file#"$PLUGIN_DIR"/}
    case "$relative" in etc/*) destination="/usr/local/$relative" ;; *) destination="/usr/local/opnsense/$relative" ;; esac
    [ -e "$destination" ] || printf '%s\n' "$destination" >> "$backup/new-files.txt"
done
[ -e /usr/local/share/locale/ru_RU/LC_MESSAGES/os-xray.mo ] || echo /usr/local/share/locale/ru_RU/LC_MESSAGES/os-xray.mo >> "$backup/new-files.txt"
rollback() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$status" -ne 0 ]; then
        echo "Installation failed. Restoring plugin files from $backup" >&2
        while IFS= read -r new_file; do rm -f "$new_file"; done < "$backup/new-files.txt"
        tar -xzpf "$backup/files.tar.gz" -C /
        # Do not overwrite live config.xml: a user may have changed unrelated settings.
        echo 'Original config.xml retained in the backup. Review before restoring it.' >&2
        service configd restart || true
        refresh_cron || true
    fi
    exit "$status"
}
trap rollback EXIT
trap 'exit 1' HUP INT TERM
install_tree() {
    source_dir=$1; target_dir=$2; mode=$3
    [ -d "$source_dir" ] || return 1
    install -d -m 755 "$target_dir"
    find "$source_dir" -type f ! -path '*/__pycache__/*' | while IFS= read -r source_file; do
        relative=${source_file#"$source_dir"/}
        install -d -m 755 "$(dirname "$target_dir/$relative")"
        install -o root -g wheel -m "$mode" "$source_file" "$target_dir/$relative"
    done
}
# Existing runtime processes stay alive. Apply is an explicit post-install operation.
install_tree "$PLUGIN_DIR/scripts/Xray" /usr/local/opnsense/scripts/Xray 755
install_tree "$PLUGIN_DIR/mvc/app/models/OPNsense/Xray" /usr/local/opnsense/mvc/app/models/OPNsense/Xray 644
install_tree "$PLUGIN_DIR/mvc/app/controllers/OPNsense/Xray" /usr/local/opnsense/mvc/app/controllers/OPNsense/Xray 644
install_tree "$PLUGIN_DIR/mvc/app/views/OPNsense/Xray" /usr/local/opnsense/mvc/app/views/OPNsense/Xray 644
install_tree "$PLUGIN_DIR/service/conf/actions.d" /usr/local/opnsense/service/conf/actions.d 644
install_tree "$PLUGIN_DIR/etc/inc/plugins.inc.d" /usr/local/etc/inc/plugins.inc.d 644
install_tree "$PLUGIN_DIR/etc/rc.syshook.d/start" /usr/local/etc/rc.syshook.d/start 755
install_tree "$PLUGIN_DIR/etc/newsyslog.conf.d" /usr/local/etc/newsyslog.conf.d 644
install -d -m 755 /usr/local/share/locale/ru_RU/LC_MESSAGES
install -m 644 "$ROOT/locale/ru_RU/LC_MESSAGES/os-xray.mo" /usr/local/share/locale/ru_RU/LC_MESSAGES/os-xray.mo
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/ensure-tunnel.php
/usr/local/bin/php /usr/local/opnsense/scripts/Xray/subscriptions.php migrate
refresh_cron
service configd restart
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
/usr/local/bin/php -r 'foreach(glob("/var/lib/php/cache/*opnsense_xray*") as $file) if(is_file($file)) unlink($file);'
trap - EXIT HUP INT TERM
echo 'Installed. Open VPN > Xray, review settings, then Apply when ready.'
echo 'New installation: add endpoints/subscriptions, enable selected servers, then configure interface, gateway and firewall rule.'
