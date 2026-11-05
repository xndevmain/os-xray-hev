<!-- DIAGNOSTICS -->
<div id="diagnostics" class="tab-pane fade in">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="panel-body form-inline">

                {# Instance selector #}
                <div class="input-group" style="width:240px;max-width:100%;vertical-align:middle">
                    <span class="input-group-addon">
                        <i class="fa fa-server fa-fw"></i>
                    </span>
                    <select id="diagInstanceSelect" class="form-control" style="height:34px;padding:6px 12px;line-height:normal">
                    </select>
                </div>

                

                <button id="btnDiagRefresh" class="btn btn-default">
                    <i class="fa fa-refresh fa-fw"></i> {{ xlang._('Refresh') }}
                </button>
                <button id="btnCopyDebug" class="btn btn-default">
                    <i class="fa fa-clipboard fa-fw"></i> {{ xlang._('Copy Debug Info') }}
                </button>
                <span id="copyDebugResult" style="font-size: 12px;"></span>
            </div>
        </section>
    </div>

    {# ── Stats table ─────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div style="padding: 12px 15px 15px;">
                <table class="table table-condensed table-striped">
                    <tbody>
                        <tr>
                            <th style="width: 220px;">{{ xlang._('TUN Interface') }}</th>
                            <td id="diag_tun_iface">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('TUN Status') }}</th>
                            <td id="diag_tun_status">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('TUN IP') }}</th>
                            <td id="diag_tun_ip">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('MTU') }}</th>
                            <td id="diag_mtu">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('Bytes In') }}</th>
                            <td id="diag_bytes_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('Bytes Out') }}</th>
                            <td id="diag_bytes_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('Packets In') }}</th>
                            <td id="diag_pkts_in">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('Packets Out') }}</th>
                            <td id="diag_pkts_out">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('xray-core Uptime') }}</th>
                            <td id="diag_xray_uptime">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('hev-socks5-tunnel Uptime') }}</th>
                            <td id="diag_hev_uptime">&mdash;</td>
                        </tr>
                        <tr>
                            <th>{{ xlang._('Server Ping RTT') }}</th>
                            <td id="diag_ping_rtt">&mdash;</td>
                        </tr>
                    </tbody>
                </table>
                <p id="diagError" class="text-danger" style="display: none;"></p>
            </div>
        </section>
    </div>

</div>
