<div id="endpoints" class="tab-pane fade">
  <div class="panel-body">
    <table id="epGrid" class="table table-condensed table-hover table-striped">
        <thead><tr>
        <th data-column-id="enabled" data-formatter="enabled">{{ xlang._("Enabled") }}</th>
        <th data-column-id="source" data-formatter="text">{{ xlang._("Subscription") }}</th>
        <th data-column-id="name" data-formatter="text">{{ xlang._("Name") }}</th>
        <th data-column-id="server" data-formatter="text">{{ xlang._("Server") }}</th><th data-column-id="port" data-type="numeric">{{ xlang._("Port") }}</th>
        <th data-column-id="protocol" data-formatter="text">{{ xlang._("Protocol") }}</th><th data-column-id="transport" data-formatter="text">{{ xlang._("Transport") }}</th>
        <th data-column-id="health" data-formatter="health">{{ xlang._("Status") }}</th>
    </tr></thead><tbody></tbody></table>
        <p id="epEmpty" class="text-center text-muted" hidden>{{ xlang._("No endpoints yet. Click Add endpoints to add a server or subscription.") }}</p>
    <div class="btn-toolbar">
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#epImport"><i class="fa fa-plus"></i> {{ xlang._("Add endpoints") }}</button>
        <button type="button" id="epRemove" class="btn btn-default" disabled><i class="fa fa-trash"></i> {{ xlang._("Remove selected") }}</button>
        <button type="button" id="epTest" class="btn btn-default" disabled><i class="fa fa-plug"></i> {{ xlang._("Test") }}</button>
        <button type="button" id="epReload" class="btn btn-default" title="Refresh"><i class="fa fa-refresh"></i></button>
    </div>
    <span role="status" id="epMessage"></span>
    <div id="epImport" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="epImportTitle">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ xlang._('Close') }}">&times;</button>
                <h4 id="epImportTitle" class="modal-title">{{ xlang._('Add endpoints') }}</h4>
            </div>
            <div class="modal-body"><div class="panel-body">
                <div class="form-group">
                    <label for="epSource">{{ xlang._('Server links or HTTPS subscription URL') }}</label>
                    <textarea id="epSource" rows="3" class="form-control" style="width:100%;max-width:100%;resize:vertical"></textarea>
                </div>
                <div class="form-group">
                    <label for="epPickFile">{{ xlang._('Subscription file') }}</label>
                    <div><button id="epPickFile" type="button" class="btn btn-default"><i class="fa fa-folder-open"></i> {{ xlang._('Choose file') }}</button>
                    <span id="epFilename" class="text-muted" aria-live="polite"></span></div>
                    <input id="epFile" type="file" style="display:none">
                </div>
                <span role="status" class="text-danger" id="epImportMessage"></span>
            </div></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">{{ xlang._('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="epAdd"><i class="fa fa-plus"></i> {{ xlang._('Add endpoints') }}</button>
            </div>
        </div></div>
    </div>
  </div>
</div>
<script>
$(function () {
    var tunnelId = '', revision = '', grid = $('#epGrid'), message = $('#epMessage');
    $('#epPickFile').click(function () { $('#epFile').click(); });
    $('#epFile').change(function () { $('#epFilename').text(this.files.length ? this.files[0].name : ''); });
    function selected() { return grid.bootgrid('getSelectedRows'); }
    var gridReady, running = false, testing = false, checks = {}, endpointRows = [];
    var serviceKnown = false, activeCount = 0;
    function initializeGrid() {
        if (!gridReady) {
            gridReady = $.Deferred();
            grid = grid.UIBootgrid({datakey:'id',options:{ajax:false, selection:true, multiSelect:true, rowCount:[10,25,-1], formatters:{health:function(column,row){return healthBadge(row);},enabled:function(column,row){return '<button type="button" class="btn btn-default btn-xs epToggle" data-id="'+row.id+'" data-enabled="'+(row.enabled?'0':'1')+'"><i class="fa fa-'+(row.enabled?'check-square-o':'square-o')+'"></i></button>';},text:function(column,row){return $('<div>').text(row[column.id]).html();}}}});
            grid.data('UIBootgrid').getTable().on('tableBuilt', function () { gridReady.resolve(); });
            grid.on('selected.rs.jquery.bootgrid deselected.rs.jquery.bootgrid', function () {
                $('#epRemove').prop('disabled', !selected().length);
            });
        }
        return gridReady.promise();
    }
    function load() {
        $('#epEmpty').prop('hidden', true);
        return initializeGrid().then(function () {
            return $.getJSON('/api/xray/catalog/endpoints');
        }).then(function (data) {
            if (!Array.isArray(data.rows)) throw Error('Invalid endpoint list response');
            tunnelId = data.uuid || '';
            if (revision !== (data.revision || '')) checks = {};
            revision = data.revision || '';
            endpointRows = data.rows; activeCount = data.active_count || 0;
            return grid.data('UIBootgrid').getTable().setData(data.rows).then(function () {
                $('#epEmpty').prop('hidden', data.rows.length !== 0);
                $('#epRemove').prop('disabled', true);
                refreshHealth();
            });
        }).catch(function () { message.text('{{ xlang._("Could not load endpoints") }}'); });
    }
    $('#maintabs a[href="#endpoints"]').on('shown.bs.tab', load);
    if ($('#endpoints').hasClass('active')) load();
    function healthBadge(row) {
        var check = checks[row.id], color = 'default', label = '{{ xlang._("Not tested") }}';
        if (row.removed) label = '{{ xlang._("Removed") }}';
        else if (!row.present) label = '{{ xlang._("Missing from subscription") }}';
        else if (!row.effective) label = '{{ xlang._("Disabled") }}';
        else if (!serviceKnown) label = '{{ xlang._("Status unavailable") }}';
        else if (!running) { color = 'warning'; label = '{{ xlang._("Xray is stopped") }}'; }
        else if (testing) label = '{{ xlang._("Testing...") }}';
        else if (check) {
            color = check.result === 'ok' ? 'success' : 'danger';
            label = check.result === 'ok' ? '{{ xlang._("Healthy") }}' : '{{ xlang._("Failed") }}';
            if (check.result === 'ok') label += ' · ' + check.latency_ms + ' ms';
        }
        return $('<span>').addClass('label label-' + color).text(label)[0].outerHTML;
    }
    function renderHealth() {
        if (grid.data('UIBootgrid')) grid.data('UIBootgrid').getTable().getRows().forEach(function(row){ row.reformat(); });
        $('#epTest').prop('disabled', testing || !serviceKnown || !running || !activeCount);
    }
    function refreshHealth() {
        if (!tunnelId) return $.Deferred().resolve().promise();
        return $.getJSON('/api/xray/service/status/' + encodeURIComponent(tunnelId)).done(function(data){
            serviceKnown = ['running','stopped'].indexOf(data.xray_core) !== -1;
            running = data.xray_core === 'running';
            if (!running) checks = {};
        }).fail(function(){ serviceKnown = false; }).always(renderHealth);
    }
    $('#epTest').click(async function(){
        if (testing) return;
        testing = true; renderHealth();
        var testedRevision = revision, testedId = tunnelId;
        try {
            await refreshHealth();
            if (!serviceKnown || !running) return;
            message.text('{{ xlang._("Testing...") }}');
            var data = await $.post('/api/xray/service/testconnect/' + encodeURIComponent(testedId), {}, null, 'json');
            await load();
            if (revision !== testedRevision || tunnelId !== testedId) return;
            if (!Array.isArray(data.proxies) || data.proxies.length !== activeCount) throw Error(data.message || '{{ xlang._("Request failed") }}');
            checks = {};
            endpointRows.forEach(function(row){if(row.effective && row.active_index !== null) checks[row.id] = data.proxies[row.active_index];});
            message.text(data.message || '');
        } catch(error) { checks = {}; message.text(error.message || '{{ xlang._("Request failed") }}'); }
        finally { testing = false; await refreshHealth(); renderHealth(); }
    });
    setInterval(function(){ if ($('#endpoints').hasClass('active') && !testing) refreshHealth(); }, 10000);
    $('#epReload').click(load);
    $('#endpoints').on('click', '.epToggle', async function(){
        var button=$(this).prop('disabled',true);
        try {
            var reply=await $.post('/api/xray/catalog/select',{ids:[button.attr('data-id')],enabled:button.attr('data-enabled'),revision:revision},null,'json');
            if(reply.result!=='saved') throw Error(reply.error || '{{ xlang._("Request failed") }}');
            message.text('{{ xlang._("Saved. Click Apply to activate.") }}'); await load();
            $(document).trigger('xrayTunnelChanged');
        } catch(e) { message.text(e.message); }
        finally { button.prop('disabled',false); }
    });
    $('#epRemove').click(async function () {
        var ids = selected();
        if (!ids.length || !window.confirm('Remove selected endpoints (' + ids.length + ')?')) return;
        try {
            var data = await $.post('/api/xray/catalog/remove', {ids:ids,revision:revision}, null, 'json');
            if(data.result !== 'saved') throw Error(data.error || '{{ xlang._("Removal failed") }}');
            message.text('{{ xlang._("Removed. Click Apply to activate.") }}');
            await load();
            $(document).trigger('xrayTunnelChanged');
        } catch (e) { message.text(e.message || '{{ xlang._("Removal failed") }}'); }
    });
    $('#epAdd').click(async function () {
        var btn = $(this).prop('disabled', true);
        try {
            var file = $('#epFile')[0].files[0];
            if (file && file.size > 1048576) throw Error('{{ xlang._("Maximum file size is 1 MiB") }}');
            var source = file ? await file.text() : $('#epSource').val().trim();
            if (!source) throw Error('{{ xlang._("Enter a server link or select a subscription file") }}');
            var id = tunnelId;
            var parsed = await $.ajax({url:'/api/xray/import/parse',type:'POST',contentType:'application/json',dataType:'json',data:JSON.stringify({link:source})});
            if (parsed.status !== 'ok') throw Error(parsed.message);
            var pool = JSON.parse(parsed.outbound_config);
            var saved = await $.post('/api/xray/catalog/append', {outbounds:JSON.stringify(Array.isArray(pool) ? pool : [pool]),names:JSON.stringify(parsed.servers || [])}, null, 'json');
            if (saved.result !== 'saved') throw Error(saved.error || '{{ xlang._("Save failed") }}');
            $('#epSource,#epFile').val(''); $('#epFilename').empty();
            $('#epImportMessage').empty(); $('#epImport').modal('hide');
            message.text('Saved endpoints: ' + saved.count + '. Click Apply to activate.');
            await load(); $(document).trigger('xrayTunnelChanged');
        } catch (error) { $('#epImportMessage').text(error.message || '{{ xlang._("Request failed") }}'); }
        finally { btn.prop('disabled', false); }
    });
});
</script>
