<script>
    $(document).ready(function () {
        var catalogs = {geosite: [], geoip: []};
        function fillPicker(select, kind, value) {
            select.empty().append(new Option("{{ xlang._("Choose a category") }}", ""));
            new Set(catalogs[kind].concat(value ? [value] : [])).forEach(function (name) { select.append(new Option(name, name)); });
            select.val(value || "");
        }
        function initCategoryRows(kind) {
            var field = $('#frm_routing_settings [id="general.firewall_' + kind + '"]');
            if (!field.length) return;
            $('#' + kind + 'Choices').remove();
            var box = $('<div>').attr('id', kind + 'Choices').insertAfter(field.hide());
            var rows = $('<div>').appendTo(box);
            function normalize(value) {
                return value.trim().replace(new RegExp('^' + kind + ':', 'i'), '').toLowerCase();
            }
            function sync() {
                var selected = new Set();
                rows.find('select').each(function () {
                    var name = normalize(this.value);
                    if (name) selected.add(name);
                });
                field.val(Array.from(selected).join(',')).trigger('change');
            }
            function addRow(value) {
                var row = $('<div class="input-group" style="margin-bottom:4px">').appendTo(rows);
                var input = $('<select class="selectpicker" data-live-search="true" data-size="9" data-width="100%" aria-label="{{ xlang._("Category") }}">').appendTo(row);
                fillPicker(input, kind, value);
                input.selectpicker({container: 'body', virtualScroll: false}).on('change', sync);
                // Enter accepts a manually specified category (including @attribute) when no item matches.
                input.data('selectpicker').$menu.find('.bs-searchbox input').on('keydown', function (event) {
                    var name = normalize(this.value);
                    if (event.key === 'Enter' && name && !catalogs[kind].some(function (item) { return item.indexOf(name) >= 0; })) {
                        event.preventDefault();
                        event.stopPropagation();
                        fillPicker(input, kind, name);
                        input.selectpicker('refresh').selectpicker('toggle');
                        sync();
                    }
                });
                var buttons = $('<span class="input-group-btn">').appendTo(row);
                $('<button type="button" class="btn btn-default" aria-label="{{ xlang._("Remove category") }}" title="{{ xlang._("Remove category") }}"><i class="fa fa-trash"></i></button>')
                    .appendTo(buttons).click(function () { input.selectpicker('destroy'); row.remove(); sync(); });
                return input;
            }
            String(field.val() || '').split(/[,\r\n]+/).map(normalize).filter(Boolean).forEach(function (name) {
                addRow(name);
            });
            $('<button type="button" class="btn btn-sm btn-default" aria-label="{{ xlang._("Add category") }}" title="{{ xlang._("Add category") }}"><i class="fa fa-fw fa-plus"></i> {{ xlang._("Add category") }}</button>')
                .appendTo(box).click(function () { var picker = addRow(''); picker.one('loaded.bs.select', function () { picker.data('selectpicker').$button.trigger('click'); }); });
        }

        function refreshCategories() {
            return $.getJSON('/api/xray/service/geosites').done(function (data) {
                ['geosite', 'geoip'].forEach(function (kind) {
                    catalogs[kind] = data[kind === 'geosite' ? 'categories' : 'geoip'] || [];
                    $('#' + kind + 'Choices select').each(function () {
                        var select = $(this);
                        fillPicker(select, kind, select.val());
                        select.selectpicker('refresh');
                    });
                });
            }).fail(function () { $('#geodataResult').text('Could not load the category catalog'); });
        }

        $('#siteTestHost').on('keydown', function(e){ if(e.key === 'Enter') {e.preventDefault(); $('#siteTestButton').click();} });
        $('#siteTestButton').click(async function(){
            var button=$(this), host=$('#siteTestHost').val().trim(), result=$('#siteTestResult');
            if (!host || button.prop('disabled')) return;
            button.prop('disabled',true); result.text('{{ xlang._("Testing...") }}');
            try {
                var data=await $.post('/api/xray/service/testSite',{host:host},null,'json');
                if(data.result!=='ok') throw Error(data.message || '{{ xlang._("Request failed") }}');
                result.empty();
                $('<p>').text(data.host + ' — ' + (data.domain_match ? '{{ xlang._("Domain matches applied lists") }}' : '{{ xlang._("Domain does not match applied lists") }}')).appendTo(result);
                var table=$('<table class="table table-condensed table-striped">').appendTo(result);
                $('<thead>').append($('<tr>').append($('<th>').text('IP'),$('<th>').text('XRAY_HOSTS'),$('<th>').text('GeoIP'))).appendTo(table);
                var body=$('<tbody>').appendTo(table);
                data.addresses.forEach(function(row){
                    var badge=$('<span>').addClass('label '+(row.in_alias?'label-success':'label-warning')).text(row.in_alias?'{{ xlang._("In alias") }}':'{{ xlang._("Not in alias") }}');
                    $('<tr>').append($('<td>').text(row.address),$('<td>').append(badge),$('<td>').text(row.geoip_match?'{{ xlang._("Match") }}':'—')).appendTo(body);
                });
                if(!data.addresses.length) $('<p>').text('{{ xlang._("No A or AAAA addresses returned") }}').appendTo(result);
                if(data.dns_errors.length) $('<p class="text-warning">').text(data.dns_errors.join('; ')).appendTo(result);
            } catch(error){result.text(error.message || '{{ xlang._("Request failed") }}');}
            finally{button.prop('disabled',false);}
        });

        // ── Helpers ───────────────────────────────────────────────
        function escAttr(s) {
            return String(s).replace(/[&"<>]/g, function (c) {
                return {'&':'&amp;','"':'&quot;','<':'&lt;','>':'&gt;'}[c];
            });
        }

        // ── Per-instance status overlay ───────────────────────────
        var instanceStatusCache = {};
        var instanceTestCache   = {};
        var editingUuid = '';
        var tunnelFormLoaded = false;
        function loadTunnel() {
            return $.post('/api/xray/instance/searchItem', {current:1,rowCount:1}, function (data) {
                var tunnel = (data.rows || [])[0];
                if (!tunnel) { $('#singleTunnel .xray-gateway').prop('disabled', true); return; }
                editingUuid = tunnel.uuid;
                if (!tunnelFormLoaded) {
                    mapDataToFormUI({'frm_tunnel_settings': '/api/xray/instance/getItem/' + editingUuid}).done(function (data) {
                        if (!data.frm_tunnel_settings) return;
                        tunnelFormLoaded = true;
                        $('#tunnelSettings').prop('disabled', false);
                        formatTokenizersUI();
                        $('#frm_tunnel_settings .selectpicker').selectpicker('refresh');
                    });
                }
                $('#tunnelName').text(tunnel.name + ' · ' + tunnel.tun_interface);
                $('#singleTunnel button').attr('data-tun', tunnel.tun_interface)
                    .attr('data-assigned', tunnel.assignment || '').prop('disabled', false);
                $('#singleTunnel .xray-gateway').attr('data-peer', tunnel.gateway_peer || '')
                    .prop('disabled', !tunnel.assignment);
                populateInstanceSelects();
            }, 'json');
        }
        loadTunnel();
        $(document).on('xrayTunnelChanged', loadTunnel);
        function createNetworkItem(url, payload, button, done) {
            button.prop('disabled', true);
            $.post(url, payload, null, 'json').done(function (reply) {
                if (reply.result === 'saved') {
                    loadTunnel();
                    done();
                } else {
                    window.alert(Object.values(reply.validations || {}).join('\n') || reply.message || '{{ xlang._("OPNsense rejected the changes.") }}');
                }
            }).fail(function (xhr) { window.alert('{{ xlang._("OPNsense error: HTTP ") }}' + xhr.status); })
              .always(function () { button.prop('disabled', false); });
        }
        $('#singleTunnel').on('click', '.xray-assign', function () {
            var button = $(this).prop('disabled', true);
            $.post('/api/xray/service/setupInterface', {}, null, 'json').done(function(reply) {
                if (reply.result === 'saved') {
                    window.alert('{{ xlang._("Interface enabled and configured:") }} ' + reply.address + '\n{{ xlang._("Tunnel gateway address:") }} ' + reply.peer);
                    loadTunnel();
                } else if (reply.result === 'existing') {
                    window.location.href = '/interfaces.php?if=' + encodeURIComponent(reply.interface);
                } else {
                    window.alert(reply.message || '{{ xlang._("OPNsense rejected the changes.") }}');
                }
            }).fail(function(){ window.alert('{{ xlang._("Request failed") }}'); })
              .always(function(){ button.prop('disabled', false); });
        }).on('click', '.xray-gateway', async function () {
            var button = $(this).prop('disabled', true);
            try { await loadTunnel(); } catch (error) { button.prop('disabled', true); return; }
            var assigned = button.attr('data-assigned');
            if (!assigned) { window.alert('{{ xlang._("Assign and configure the interface first.") }}'); return; }
            var gateway = button.attr('data-peer') || window.prompt('{{ xlang._("Gateway IPv4 address for ") }}' + button.attr('data-tun') + '{{ xlang._(" (tunnel peer address)") }}');
            if (!gateway || !gateway.trim()) return;
            createNetworkItem('/api/routing/settings/addGateway', {gateway_item: {
                name: 'XRAY_' + assigned.toUpperCase() + '_GW', interface: assigned, gateway: gateway.trim(),
                ipprotocol: 'inet', defaultgw: '0', fargw: '1', monitor_disable: '1'
            }}, button, function () { window.alert('{{ xlang._("Gateway saved. Apply changes in System → Gateways → Configuration.") }}'); });
        });

        $('#singleTunnel').on('click', '.xray-rule', function () {
            var button = $(this), assigned = button.attr('data-assigned');
            if (!assigned) { window.alert('{{ xlang._("Assign and configure the interface first.") }}'); return; }
            var gateway = 'XRAY_' + assigned.toUpperCase() + '_GW';
            if (!window.confirm('{{ xlang._("Create an IPv4 LAN rule for XRAY_HOSTS via gateway") }} ' + gateway + '?')) return;
            createNetworkItem('/api/xray/service/setupRule', {}, button, function () { window.alert('{{ xlang._("Rule saved. Check its order and apply it in Firewall Rules.") }}'); });
        });

        // ── General settings form ───────────────────────────────────
        $('#geositeSource').change(function () {
            if ($(this).val()) $('[id="general.geosite_url"]').val($(this).val());
        });
        $('#cancelGeodata').click(function () {
            var button = $(this).prop('disabled', true);
            $.post('/api/xray/service/cancelGeodata', {}, function (data) {
                $('#geodataResult').text(data.message);
            }, 'json').fail(function () { button.prop('disabled', false); });
        });
        $('#btnGeodata').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $result = $('#geodataResult').text('{{ xlang._("Saving settings...") }}');
            saveFormToEndpoint('/api/xray/general/set', 'frm_routing_settings', function () {
                $result.text('{{ xlang._("Downloading and validating geodata...") }}');
                $('#cancelGeodata').prop('hidden', false).prop('disabled', false);
                $.ajax({url: '/api/xray/service/geodata', type: 'POST', dataType: 'json', timeout: 240000})
                    .done(function (data) { $result.text(data.message || data.result); if (data.result === 'ok') refreshCategories(); })
                    .fail(function (xhr) { $result.text('Import failed: HTTP ' + xhr.status); })
                    .always(function () { $('#cancelGeodata').prop('hidden', true); $btn.prop('disabled', false); });
            });
        });
        $('#btnFirewallLists').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $result = $('#firewallListsResult').text('{{ xlang._("Applying firewall lists...") }}');
            saveFormToEndpoint('/api/xray/general/set', 'frm_routing_settings', function () {
            $.ajax({url: '/api/xray/service/firewallLists', type: 'POST', dataType: 'json', timeout: 120000})
                .done(function (data) { $result.text(data.message || data.result); })
                .fail(function (xhr) { $result.text('Apply failed: HTTP ' + xhr.status); })
                .always(function () { $btn.prop('disabled', false); });
            });
        });
        mapDataToFormUI({'frm_general_settings': '/api/xray/general/get', 'frm_routing_settings': '/api/xray/general/get', 'frm_watchdog_settings': '/api/xray/general/get'}).done(function () {
            refreshCategories().always(function () {
                initCategoryRows('geosite');
                initCategoryRows('geoip');
            });
            var geoipRow = $('[id="general.firewall_geoip"]').closest('tr');
            $('<tr><td></td><td colspan="2"></td></tr>').insertAfter(geoipRow)
                .children().last().append($('#firewallListActions').removeClass('form-group'));
            var sourceRow = $('[id="general.geosite_url"]').closest('tr');
            if (sourceRow.length) {
                $('#communityControls tbody > tr').insertBefore(sourceRow);
                $('#communityControls').remove();
            }
            $('#geositeSource').val($('[id="general.geosite_url"]').val() || '');
            if (!$('#geositeSource').val()) $('#geositeSource').val('');
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // ── Apply (save tunnel and general, then reconfigure) ──────────────────
        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function () {
                var dfObj = new $.Deferred();
                if (!editingUuid || !tunnelFormLoaded) return dfObj.reject();
                function failed() { dfObj.reject(); }
                saveFormToEndpoint('/api/xray/instance/setItem/' + editingUuid, 'frm_tunnel_settings', function () {
                    saveFormToEndpoint('/api/xray/general/set', 'frm_general_settings', function () {
                        saveFormToEndpoint('/api/xray/general/set', 'frm_watchdog_settings', function () {
                            saveFormToEndpoint('/api/xray/general/set', 'frm_routing_settings', function () {
                                loadTunnel();
                                dfObj.resolve();
                            }, false, failed);
                        }, false, failed);
                    }, false, failed);
                }, false, failed);
                return dfObj;
            }
        });

        // ── Status badges + per-instance status ───────────────────
        function refreshInstancesStatus() {
            ajaxGet("/api/xray/service/statusall", {}, function (data) {
                if (data.error) return;
                instanceStatusCache = data;

                // Aggregate: any instance running = global running
                var anyXray = false, anyTun = false;
                $.each(data, function (uuid, info) {
                    if (info.xray_core === 'running') anyXray = true;
                    if (info['hev-socks5-tunnel'] === 'running') anyTun = true;
                });
                var xok = anyXray, tok = anyTun;
                $('#badge_xray')
                    .removeClass('label-success label-danger label-default')
                    .addClass(xok ? 'label-success' : 'label-danger')
                    .text('xray-core: ' + (xok ? 'running' : 'stopped'));
                $('#badge_tun')
                    .removeClass('label-success label-danger label-default')
                    .addClass(tok ? 'label-success' : 'label-danger')
                    .text('hev-socks5-tunnel: ' + (tok ? 'running' : 'stopped'));

                // Update per-instance status in grid
                

                var running = xok || tok;
                $('#btnStartAll').prop('disabled', running);
                $('#btnStopAll').prop('disabled', !running);
                $('#btnRestartAll').prop('disabled', !running);
            });
        }
        refreshInstancesStatus();
        setInterval(refreshInstancesStatus, 5000);

        // ── Start / Stop / Restart ──────────────────────────────────
        function serviceAction(action, confirmMsg, callback) {
            if (confirmMsg && !confirm(confirmMsg)) {
                return;
            }
            // TODO: clean up — pass $btn as argument instead of deriving it from action string
            var $btns = $('#btnStartAll, #btnStopAll, #btnRestartAll').prop('disabled', true);
            var $btn = action === 'start'   ? $('#btnStartAll')
                     : action === 'stop'    ? $('#btnStopAll')
                     :                        $('#btnRestartAll');
            var origHtml = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i>');

            $.ajax({
                url:      '/api/xray/service/' + action,
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.html(origHtml);
                    if (data.result !== 'ok') {
                        alert('{{ xlang._("Action failed:") }} ' + (data.message || 'unknown error'));
                    }
                    setTimeout(function () {
                        refreshInstancesStatus();
                        $btns.prop('disabled', false);
                        if (callback) callback();
                    }, 1500);
                },
                error: function (xhr) {
                    $btn.html(origHtml);
                    $btns.prop('disabled', false);
                    alert('{{ xlang._("HTTP error:") }} ' + xhr.status);
                }
            });
        }

        $('#btnStartAll').click(function () {
            serviceAction('start', null, null);
        });
        $('#btnStopAll').click(function () {
            var confirmStop = '{{ xlang._("Stop Xray VPN? Active connections will be terminated.") }}';
            serviceAction('stop', confirmStop, null);
        });
        $('#btnRestartAll').click(function () {
            serviceAction('restart', null, null);
        });

        // Validate the saved configuration without applying changes.
        $(document).on('click', '#dlgValidateBtn', function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#dlgValidateResult');
            if (!editingUuid) {
                $btn.prop('disabled', false);
                $res.text('{{ xlang._("Save the instance before validating it.") }}');
                return;
            }
            $res.removeClass('text-success text-danger').text("{{ xlang._('Validating...') }}");

            $.ajax({
                url:      '/api/xray/service/validate/' + encodeURIComponent(editingUuid),
                type:     'POST',
                dataType: 'json',
                success: function (data) {
                    $btn.prop('disabled', false);
                    if (data.result === 'ok') {
                        $res.removeClass('text-danger').addClass('text-success')
                            .text(data.message || "{{ xlang._('Config is valid') }}");
                    } else {
                        $res.removeClass('text-success').addClass('text-danger')
                            .text(data.message || "{{ xlang._('Validation failed') }}");
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false);
                    $res.addClass('text-danger').text("{{ xlang._('HTTP error:') }} " + xhr.status);
                }
            });
        });

        // ── Diagnostics ─────────────────────────────────────────────
        function populateInstanceSelects() {
            $.ajax({
                url: '/api/xray/instance/searchItem',
                type: 'POST',
                dataType: 'json',
                data: {rowCount: -1, current: 1, searchPhrase: ''},
                success: function (data) {
                    var rows = (data && data.rows) ? data.rows : [];
                    var $diagSel = $('#diagInstanceSelect');
                    var $logSel = $('#logInstanceSelect');
                    var savedDiag = $diagSel.val();
                    var savedLog = $logSel.val();
                    $diagSel.empty();
                    $logSel.empty();
                    $.each(rows, function (_, row) {
                        $diagSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                        $logSel.append($('<option></option>').val(row.uuid).text(row.name || row.uuid));
                    });
                    if (savedDiag && $diagSel.find('option[value="' + savedDiag + '"]').length) {
                        $diagSel.val(savedDiag);
                    }
                    if (savedLog && $logSel.find('option[value="' + savedLog + '"]').length) {
                        $logSel.val(savedLog);
                    }
                }
            });
        }

        function loadDiagnostics() {
            var uuid = $('#diagInstanceSelect').val();
            var url = '/api/xray/service/diagnostics' + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#btnDiagRefresh').prop('disabled', true);
            $('#diagError').hide();
            ajaxGet(url, {}, function (data) {
                $('#btnDiagRefresh').prop('disabled', false);
                if (data.error) {
                    $('#diagError').text(data.error).show();
                    return;
                }
                var running = data.tun_status === 'running';
                var statusHtml = running
                    ? '<span class="label label-success">running</span>'
                    : '<span class="label label-danger">' + escAttr(data.tun_status || 'down') + '</span>';

                $('#diag_tun_iface').text(data.tun_interface  || '\u2014');
                $('#diag_tun_status').html(statusHtml);
                $('#diag_tun_ip').text(data.tun_ip           || '\u2014');
                $('#diag_mtu').text(data.mtu > 0 ? data.mtu + ' bytes' : '\u2014');
                $('#diag_bytes_in').text(data.bytes_in_hr    || '\u2014');
                $('#diag_bytes_out').text(data.bytes_out_hr  || '\u2014');
                $('#diag_pkts_in').text(data.pkts_in != null ? data.pkts_in.toLocaleString() : '\u2014');
                $('#diag_pkts_out').text(data.pkts_out != null ? data.pkts_out.toLocaleString() : '\u2014');
                $('#diag_xray_uptime').text(data.xray_uptime || '\u2014');
                $('#diag_hev_uptime').text(data['hev-socks5-tunnel_uptime'] || '\u2014');
                $('#diag_ping_rtt').text(data.ping_rtt || 'N/A');
            });
        }

        var diagAutoRefresh = null;
        $('a[href="#diagnostics"]').on('shown.bs.tab', function () {
            loadDiagnostics();
            if (!diagAutoRefresh) {
                diagAutoRefresh = setInterval(function () {
                    if ($('#diagnostics').hasClass('active')) {
                        loadDiagnostics();
                    }
                }, 30000);
            }
        });
        $('#btnDiagRefresh').click(function () {
            loadDiagnostics();
        });
        $('#diagInstanceSelect').on('change', function () {
            loadDiagnostics();
        });
        $('#logInstanceSelect').on('change', function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Logs ────────────────────────────────────────────────────
        function loadLog(apiEndpoint, preId, btnId) {
            var uuid = $('#logInstanceSelect').val();
            var apiEndpointWithUuid = apiEndpoint + (uuid ? '/' + encodeURIComponent(uuid) : '');
            $('#' + btnId).prop('disabled', true);
            $('#' + preId).text("{{ xlang._('Loading...') }}");
            $.post(apiEndpointWithUuid, null, function (data) {
                var text = (data && data.log) || "{{ xlang._('Log is empty.') }}";
                $('#' + preId).text(text);
                $('#' + btnId).prop('disabled', false);
                var pre = document.getElementById(preId);
                if (pre) { pre.scrollTop = pre.scrollHeight; }
            }, 'json').fail(function (xhr) {
                $('#' + preId).text("{{ xlang._('Error loading log:') }} " + xhr.status);
                $('#' + btnId).prop('disabled', false);
            });
        }

        $('a[href="#logs"]').on('shown.bs.tab', function () {
            var $active = $('#logSubTabs .active a');
            var href = $active.attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $('#logSubTabs a').on('shown.bs.tab', function (e) {
            var href = $(e.target).attr('href');
            if (href === '#logBoot') {
                loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
            } else if (href === '#logCore') {
                loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
            }
        });

        $("#logBootRefreshBtn").click(function () {
            loadLog("/api/xray/service/log", 'logBootContent', 'logBootRefreshBtn');
        });
        $("#logCoreRefreshBtn").click(function () {
            loadLog("/api/xray/service/xraylog", 'logCoreContent', 'logCoreRefreshBtn');
        });

        // ── Copy Debug Info ─────────────────────────────────────────
        $('#btnCopyDebug').click(function () {
            var $btn = $(this).prop('disabled', true);
            var $res = $('#copyDebugResult');
            $res.removeClass('text-success text-danger').text("{{ xlang._('Collecting...') }}");

            var diagData = {}, bootLog = '', coreLog = '';
            var diagDone = $.Deferred(), bootDone = $.Deferred(), coreDone = $.Deferred();

            ajaxGet('/api/xray/service/diagnostics', {}, function (data) {
                diagData = data;
                diagDone.resolve();
            });
            $.post('/api/xray/service/log', null, function (data) {
                bootLog = (data && data.log) || '';
                bootDone.resolve();
            }, 'json').fail(function () { bootDone.resolve(); });
            $.post('/api/xray/service/xraylog', null, function (data) {
                coreLog = (data && data.log) || '';
                coreDone.resolve();
            }, 'json').fail(function () { coreDone.resolve(); });

            $.when(diagDone, bootDone, coreDone).done(function () {
                var info = "=== os-xray Debug Info ===\n"
                    + "Date: " + new Date().toISOString() + "\n\n"
                    + "--- Diagnostics ---\n"
                    + JSON.stringify(diagData, null, 2) + "\n\n"
                    + "--- Boot Log (last 150 lines) ---\n"
                    + bootLog + "\n\n"
                    + "--- Core Log (last 200 lines) ---\n"
                    + coreLog + "\n";

                $('#debugInfoContent').val(info);
                $('#debugInfoModal').modal('show');
                $('#debugInfoModal').one('shown.bs.modal', function () {
                    var ta = document.getElementById('debugInfoContent');
                    ta.focus();
                    ta.select();
                });
                $res.addClass('text-success').text("{{ xlang._('Use Ctrl+C / Cmd+C to copy') }}");
                $btn.prop('disabled', false);
            });
        });

        // Sidebar links can change the hash without reloading this page.
        function showMainTab(useDefault) {
            var hash = window.location.hash;
            if (!hash && useDefault) hash = '#general';
            if (!hash) return;
            var link = $('#maintabs a').filter(function () {
                return this.getAttribute('href') === hash;
            });
            if (link.length) link.tab('show');
        }
        $(window).on('hashchange', function () { showMainTab(false); });
        $('#maintabs a').on('shown.bs.tab', function (e) {
            if (window.location.hash !== e.target.hash) {
                history.replaceState(null, null, e.target.hash);
            }
        });
        showMainTab(true);
    });
</script>
