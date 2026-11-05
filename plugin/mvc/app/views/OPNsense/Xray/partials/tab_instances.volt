<style>
#instances .xray-service-bar, #instances .xray-status-group, #instances .xray-service-actions,
#instances .xray-row-actions { display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#instances .xray-service-bar { width:100%; box-sizing:border-box; padding:12px 8px; }
#instances .xray-row-actions { justify-content:flex-end; gap:3px; white-space:normal; }
#instances .xray-service-actions { margin-left:auto; justify-content:flex-end; }
#instances .xray-status-group .label, #instances .xray-service-actions .btn {
    height:32px; box-sizing:border-box; display:inline-flex; align-items:center; justify-content:center;
}
#instances .xray-status-group .label { padding:0 10px; font-size:12px; }
#instances .xray-service-actions .btn-group { flex:none; }
#instances #testConnectResult:empty { display:none; }
</style>
<div id="instances">

    {# ── Service toolbar ─────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="xray-service-bar">

                {# Status badges #}
                <div class="xray-status-group">
                    <span id="badge_xray" class="label label-default">xray-core: ...</span>
                    <span id="badge_tun"  class="label label-default">hev-socks5-tunnel: ...</span>
                </div>

                

                {# Global service controls #}
                <div class="xray-service-actions">
                <div class="btn-group">
                    <button id="btnStartAll" class="btn btn-sm btn-success"
                            title="{{ xlang._('Start the tunnel') }}">
                        <i class="fa fa-play fa-fw"></i> {{ xlang._('Start') }}
                    </button>
                    <button id="btnStopAll" class="btn btn-sm btn-danger"
                            title="{{ xlang._('Stop the tunnel') }}">
                        <i class="fa fa-stop fa-fw"></i> {{ xlang._('Stop') }}
                    </button>
                    <button id="btnRestartAll" class="btn btn-sm btn-warning"
                            title="{{ xlang._('Restart the tunnel') }}">
                        <i class="fa fa-refresh fa-fw"></i> {{ xlang._('Restart') }}
                    </button>
                </div>

                

                </div>

            </div>
        </section>
    </div>

    <div id="singleTunnel" style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:4px 8px 12px">
        <strong>{{ xlang._("Tunnel") }}</strong><span id="tunnelName"></span>
        <button type="button" disabled class="btn btn-sm btn-default xray-assign"><i class="fa fa-sitemap"></i> {{ xlang._("Interface") }}</button>
        <button type="button" disabled class="btn btn-sm btn-default xray-gateway"><i class="fa fa-plus"></i> {{ xlang._("Gateway") }}</button>
        <button type="button" disabled class="btn btn-sm btn-default xray-rule"><i class="fa fa-filter"></i> {{ xlang._('Firewall rule') }}</button>
    </div>
</div>
