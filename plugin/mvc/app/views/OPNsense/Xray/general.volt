{{ partial('OPNsense/Xray/partials/scripts') }}

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#general">{{ xlang._('General') }}</a></li>
    <li><a data-toggle="tab" href="#endpoints">{{ xlang._('Endpoints') }}</a></li>
    <li><a data-toggle="tab" href="#subscriptions">{{ xlang._('Subscriptions') }}</a></li>
    <li><a data-toggle="tab" href="#routing">{{ xlang._('Routing') }}</a></li>
    <li><a data-toggle="tab" href="#diagnostics">{{ xlang._('Diagnostics') }}</a></li>
    <li><a data-toggle="tab" href="#logs">{{ xlang._('Log') }}</a></li>
</ul>

<div class="tab-content content-box">
    {{ partial('OPNsense/Xray/partials/endpoints') }}
    {{ partial('OPNsense/Xray/partials/subscriptions') }}

    <div id="general" class="tab-pane fade in active">
        {{ partial('OPNsense/Xray/partials/tab_instances') }}
        <fieldset id="tunnelSettings" disabled>
            {{ partial("layout_partials/base_form", {'fields': generalForm, 'id': 'frm_general_settings'}) }}
            {{ partial("layout_partials/base_form", {'fields': instanceForm, 'id': 'frm_tunnel_settings'}) }}
            {{ partial("layout_partials/base_form", {'fields': watchdogForm, 'id': 'frm_watchdog_settings'}) }}
            <div style="padding:8px">
                <button type="button" id="dlgValidateBtn" class="btn btn-default"><i class="fa fa-check-circle"></i> {{ xlang._('Validate Saved Instance') }}</button>
                <span id="dlgValidateResult" role="status"></span>
            </div>
        </fieldset>
    </div>
    <div id="routing" class="tab-pane fade">
        {{ partial("layout_partials/base_form", {'fields': routingForm, 'id': 'frm_routing_settings'}) }}
        {{ partial('OPNsense/Xray/partials/tab_routing_lists') }}
        <table id="communityControls" class="table table-condensed opnsense_standard_table">
            <tbody><tr><td><label for="geositeSource">{{ xlang._('Geosite provider') }}</label></td><td colspan="2">
                <select id="geositeSource" class="form-control" style="width:100%;max-width:48em">
                    <option value="">{{ xlang._('Configured URL') }}</option>
                    <option value="https://github.com/v2fly/domain-list-community/releases/latest/download/dlc.dat">v2fly</option>
                    <option value="https://github.com/itdoginfo/allow-domains/releases/latest/download/geosite.dat">ITDog / Podkop</option>
                </select></td></tr>
            <tr><td>{{ xlang._('Community databases') }}</td><td colspan="2">
                <button type="button" id="btnGeodata" class="btn btn-default"><i class="fa fa-download"></i> {{ xlang._('Update geosite and geoip') }}</button>
                <button type="button" id="cancelGeodata" class="btn btn-default" hidden>{{ xlang._("Cancel import") }}</button>
                <span id="geodataResult" role="status"></span></td></tr></tbody>
        </table>
        <div id="firewallListActions" class="form-group">
            <button type="button" id="btnFirewallLists" class="btn btn-default"><i class="fa fa-check"></i> {{ xlang._('Apply Firewall Lists') }}</button>
            <span id="firewallListsResult" role="status"></span>
            <p class="help-block">{{ xlang._('Use XRAY_HOSTS as the destination in your firewall rule and select the tunnel gateway there.') }}</p>
        </div>
        <div class="panel-body">
            <h4>{{ xlang._('Site routing test') }}</h4>
            <label for="siteTestHost">{{ xlang._('Domain or website URL') }}</label>
            <div class="input-group" style="max-width:48em">
                <input id="siteTestHost" type="text" class="form-control" placeholder="www.example.com">
                <span class="input-group-btn"><button id="siteTestButton" type="button" class="btn btn-default"><i class="fa fa-search"></i> {{ xlang._('Test') }}</button></span>
            </div>
            <p class="help-block">{{ xlang._('Tests applied lists through local Unbound and checks XRAY_HOSTS. Does not test client firewall rules or proxy connectivity.') }}</p>
            <div id="siteTestResult" role="status" aria-live="polite"></div>
        </div>
    </div>

    {{ partial('OPNsense/Xray/partials/tab_diagnostics') }}
    {{ partial('OPNsense/Xray/partials/tab_logs') }}
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/xray/service/reconfigure'}) }}


{{ partial('OPNsense/Xray/partials/modal_debug') }}

<div class="modal fade" id="proxyTestModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4>{{ xlang._('Proxy health checks') }}</h4></div>
        <div class="modal-body">
            <p>{{ xlang._('Each configured proxy is tested in isolation using a temporary Xray process. This checks HTTPS connectivity, not UDP or the firewall/TUN path.') }}</p>
            <table class="table"><thead><tr><th>{{ xlang._('Proxy') }}</th><th>{{ xlang._('Status') }}</th><th>HTTP</th><th>{{ xlang._('Time (ms)') }}</th></tr></thead><tbody id="proxyTestRows"></tbody></table>
        </div>
    </div></div>
</div>
