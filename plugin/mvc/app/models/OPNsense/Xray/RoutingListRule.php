<?php
namespace OPNsense\Xray;

/**
 * Ownership of firewall rules created by the routing-list rule helper.
 *
 * The helper tags every generated rule with a stable, kind-qualified
 * description. Domain-list ownership is alias-only — the marker is
 * "Xray domain list [XRAY_<12hex>]" and never embeds the mutable list
 * name — so renaming a list cannot orphan deletion cleanup. IP-list
 * descriptions remain backward-compatible and keep the list name.
 * Deletion cleanup may only remove rules whose destination is the list
 * alias AND whose description exactly matches the generated marker, so
 * user-created rules on the same alias are never touched.
 */
class RoutingListRule
{
    public static function description(string $kind, string $name, string $alias = ''): string
    {
        if ($kind === 'domain') {
            return substr('Xray domain list [' . $alias . ']', 0, 255);
        }
        return substr('Xray IP list: ' . $name, 0, 255);
    }

    public static function ownedByDomainList(string $destinationNet, string $description, string $alias): bool
    {
        return $destinationNet === $alias && $description === self::description('domain', '', $alias);
    }
}
