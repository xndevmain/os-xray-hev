<!-- LOGS -->
<div id="logs" class="tab-pane fade in">

    {# ── Toolbar ─────────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="panel-body form-inline">

                {# Log type sub-tabs #}
                <ul class="nav nav-pills nav-sm" id="logSubTabs" style="margin: 0;">
                    <li class="active">
                        <a data-toggle="tab" href="#logBoot">
                            <i class="fa fa-terminal fa-fw"></i> {{ xlang._('Boot Log') }}
                        </a>
                    </li>
                    <li>
                        <a data-toggle="tab" href="#logCore">
                            <i class="fa fa-file-text-o fa-fw"></i> {{ xlang._('Core Log') }}
                        </a>
                    </li>
                </ul>

            </div>
        </section>
    </div>

    {# ── Log panes ───────────────────────────────────────────────────── #}
    <div class="row">
        <section class="col-xs-12">
            <div class="tab-content" style="padding: 0 15px 15px;">

                <div id="logBoot" class="tab-pane fade in active" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logBootRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ xlang._('Refresh') }}
                        </button>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ xlang._('/tmp/xray_syshook.log — last 150 lines') }}
                        </span>
                    </div>
                    <pre id="logBootContent" class="pre-scrollable">{{ xlang._('Switch to this tab to load log.') }}</pre>
                </div>

                <div id="logCore" class="tab-pane fade in" style="padding-top: 10px;">
                    <div style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                        <button id="logCoreRefreshBtn" class="btn btn-sm btn-default">
                            <i class="fa fa-refresh fa-fw"></i> {{ xlang._('Refresh') }}
                        </button>
                        <div class="input-group input-group-sm" style="min-width: 200px; width: auto;">
                            <span class="input-group-addon">
                                <i class="fa fa-server fa-fw"></i>
                            </span>
                            <select id="logInstanceSelect" class="form-control">
                            </select>
                        </div>
                        <span class="text-muted" style="font-size: 12px;">
                            {{ xlang._('/var/log/xray-core-{uuid}.log — last 200 lines') }}
                        </span>
                    </div>
                    <pre id="logCoreContent" class="pre-scrollable">{{ xlang._('Click "Core Log" to load.') }}</pre>
                </div>

            </div>
        </section>
    </div>

</div>
