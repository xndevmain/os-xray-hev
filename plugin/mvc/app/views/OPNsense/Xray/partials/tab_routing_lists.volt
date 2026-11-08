<div id="routingLists" class="panel-body">
  <h4>{{ xlang._('Downloaded routing lists') }}</h4>
  <table id="routingListGrid" class="table table-condensed table-hover table-striped"><thead><tr>
    <th data-column-id="name" data-formatter="text">{{ xlang._('Name') }}</th>
    <th data-column-id="kind" data-formatter="kind">{{ xlang._('Kind') }}</th>
    <th data-column-id="enabled" data-formatter="flag">{{ xlang._('Enabled') }}</th>
    <th data-column-id="sources" data-type="numeric">{{ xlang._('Sources') }}</th>
    <th data-column-id="alias" data-formatter="text">{{ xlang._('Firewall alias') }}</th>
    <th data-column-id="entries" data-type="numeric">{{ xlang._('Entries') }}</th>
    <th data-column-id="ports" data-formatter="lines">{{ xlang._('UDP ports') }}</th>
    <th data-column-id="updated" data-formatter="time">{{ xlang._('Updated') }}</th>
    <th data-column-id="commands" data-formatter="commands" data-sortable="false"></th>
  </tr></thead><tbody></tbody></table>
  <p id="routingListEmpty" class="text-center text-muted" hidden>{{ xlang._('No downloaded routing lists configured.') }}</p>
  <div class="btn-toolbar">
    <button type="button" class="btn btn-primary" id="routingListNew"><i class="fa fa-plus"></i> {{ xlang._('Add routing list') }}</button>
    <button type="button" class="btn btn-default" id="routingListDownload"><i class="fa fa-download"></i> {{ xlang._('Download and apply all') }}</button>
    <button type="button" class="btn btn-default" id="routingListReload"><i class="fa fa-refresh"></i> {{ xlang._('Refresh') }}</button>
  </div>
  <p class="help-block">{{ xlang._('Each entry may combine several HTTPS text lists with inline values. IP lists merge feeds and inline IP/CIDR networks into their alias; domain lists learn DNS answers for suffix domains. The plugin creates one native external alias per entry; firewall rules remain visible and editable in the normal OPNsense UI.') }}</p>
  <span id="routingListMessage" role="status"></span>
</div>

<div id="routingListDialog" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="routingListTitle">
 <div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="routingListTitle">{{ xlang._('Routing list') }}</h4></div>
  <div class="modal-body"><div class="panel-body">
   <div class="form-group"><label for="routingListName">{{ xlang._('Name') }}</label><input id="routingListName" class="form-control" maxlength="128"></div>
   <div class="form-group"><label for="routingListPreset">{{ xlang._('Preset') }}</label><select id="routingListPreset" class="form-control"><option value="custom">{{ xlang._('Custom') }}</option><option value="discord_voice">{{ xlang._('Discord voice') }}</option></select></div>
   <div class="form-group"><label for="routingListKind">{{ xlang._('Kind') }}</label><select id="routingListKind" class="form-control"><option value="ip">{{ xlang._('IP') }}</option><option value="domain">{{ xlang._('Domain') }}</option></select><p class="help-block">{{ xlang._('IP lists route static addresses; domain lists learn DNS answers through Unbound.') }}</p></div>
   <div class="form-group"><label for="routingListUrls">{{ xlang._('HTTPS list URLs') }}</label><textarea id="routingListUrls" rows="4" class="form-control" spellcheck="false"></textarea><p class="help-block" id="routingListUrlsHelpIp">{{ xlang._('One plain-text IP/CIDR list URL per line; up to 16 sources are combined.') }}</p><p class="help-block" id="routingListUrlsHelpDomain" hidden>{{ xlang._('One plain-text suffix-domain list URL per line; up to 16 sources are combined.') }}</p></div>
   <div class="form-group" id="routingListIpFields">
    <label for="routingListNetworks">{{ xlang._('Inline IP/CIDR networks') }}</label><textarea id="routingListNetworks" rows="4" class="form-control" spellcheck="false"></textarea>
    <label for="routingListPorts" style="margin-top:10px">{{ xlang._('Destination UDP ports') }}</label><textarea id="routingListPorts" rows="2" class="form-control" spellcheck="false"></textarea><p class="help-block">{{ xlang._('Optional rule-helper metadata; one port or range such as 19294:19344 per line.') }}</p>
   </div>
   <div class="form-group" id="routingListDomainFields" hidden>
    <label for="routingListDomains">{{ xlang._('Inline domains') }}</label><textarea id="routingListDomains" rows="4" class="form-control" spellcheck="false"></textarea><p class="help-block">{{ xlang._('One suffix domain per line, for example example.com; includes all subdomains. Use punycode (xn--…) for internationalized names.') }}</p>
   </div>
   <div class="form-group"><label for="routingListRefresh">{{ xlang._('Refresh interval (minutes)') }}</label><input id="routingListRefresh" type="number" min="30" max="10080" class="form-control"></div>
   <div class="checkbox"><label><input id="routingListEnabled" type="checkbox"> {{ xlang._('Enabled') }}</label></div>
   <span id="routingListError" class="text-danger" role="status"></span>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ xlang._('Cancel') }}</button><button type="button" class="btn btn-primary" id="routingListSave">{{ xlang._('Save') }}</button></div>
 </div></div>
</div>

<script>
$(function(){
 var grid=$('#routingListGrid'),ready,editing='',message=$('#routingListMessage');
 var discordPreset={
  name:'Discord voice',kind:'ip',
  urls:'https://raw.githubusercontent.com/123jjck/cdn-ip-ranges/main/discord-voice/discord-voice_plain_ipv4.txt',
  networks:'104.29.136.0/21\n104.29.144.0/20\n66.22.192.0/18\n35.217.12.0/24',
  ports:'19294:19344\n50000:50032',refresh:'720'
 };
 function text(value){return $('<div>').text(value==null?'':value).html();}
 function applyKind(){
  var domain=$('#routingListKind').val()==='domain';
  $('#routingListIpFields').prop('hidden',domain);
  $('#routingListDomainFields').prop('hidden',!domain);
  $('#routingListUrlsHelpIp').prop('hidden',domain);
  $('#routingListUrlsHelpDomain').prop('hidden',!domain);
  if(domain){$('#routingListNetworks,#routingListPorts').val('');}
  else{$('#routingListDomains').val('');}
 }
 function load(){
  if(!ready){
   ready=$.Deferred();
   grid=grid.UIBootgrid({datakey:'uuid',options:{ajax:false,rowCount:[10,25,-1],formatters:{
    text:function(c,r){return text(r[c.id]);},
    kind:function(c,r){return text(r[c.id]==='domain'?'{{ xlang._('Domain') }}':'{{ xlang._('IP') }}');},
    flag:function(c,r){return '<i class="fa fa-'+(String(r[c.id])==='1'?'check-square-o':'square-o')+'"></i>';},
    lines:function(c,r){return text(String(r[c.id]||'').replace(/[\r\n]+/g,', '));},
    time:function(c,r){return Number(r[c.id])?text(new Date(Number(r[c.id])*1000).toLocaleString()):'—';},
    commands:function(c,r){return '<div class="btn-group"><button type="button" class="btn btn-default btn-xs routingListEdit" data-id="'+r.uuid+'" title="{{ xlang._('Edit') }}"><i class="fa fa-pencil"></i></button><button type="button" class="btn btn-default btn-xs routingListRule" data-id="'+r.uuid+'" title="{{ xlang._('Create firewall rule') }}"><i class="fa fa-filter"></i></button><button type="button" class="btn btn-default btn-xs routingListDelete" data-id="'+r.uuid+'" data-kind="'+(r.kind==='domain'?'domain':'ip')+'" title="{{ xlang._('Delete') }}"><i class="fa fa-trash"></i></button></div>';}
   }}});
   grid.data('UIBootgrid').getTable().on('tableBuilt',function(){ready.resolve();});
  }
  return ready.then(function(){return $.post('/api/xray/routingList/searchItem',{current:1,rowCount:-1},null,'json');}).then(function(data){
   $('#routingListEmpty').prop('hidden',(data.rows||[]).length!==0);
   return grid.data('UIBootgrid').getTable().setData(data.rows||[]);
  }).catch(function(){message.text('{{ xlang._('Request failed') }}');});
 }
 function clearDialog(){
  editing='';$('#routingListName,#routingListUrls,#routingListNetworks,#routingListDomains,#routingListPorts').val('');
  $('#routingListPreset').val('custom');$('#routingListKind').val('ip');applyKind();
  $('#routingListRefresh').val(1440);$('#routingListEnabled').prop('checked',true);$('#routingListError').empty();
 }
 $('#maintabs a[href="#routing"]').on('shown.bs.tab',load);
 $('#routingListReload').click(load);
 $('#routingListNew').click(function(){clearDialog();$('#routingListDialog').modal('show');});
 $('#routingListKind').change(applyKind);
 $('#routingListPreset').change(function(){
  if(this.value!=='discord_voice') return;
  $('#routingListName').val(discordPreset.name);$('#routingListKind').val(discordPreset.kind);applyKind();
  $('#routingListUrls').val(discordPreset.urls);
  $('#routingListNetworks').val(discordPreset.networks);$('#routingListPorts').val(discordPreset.ports);$('#routingListRefresh').val(discordPreset.refresh);
 });
 $('#routingLists').on('click','.routingListEdit',async function(){
  try{editing=$(this).attr('data-id');var data=await $.getJSON('/api/xray/routingList/getItem/'+editing),item=data.list;if(!item)throw Error('{{ xlang._('Routing list not found') }}');
   $('#routingListName').val(item.name||'');$('#routingListPreset').val(item.preset||'custom');$('#routingListKind').val(item.kind||'ip');applyKind();
   $('#routingListUrls').val(item.urls||'');
   $('#routingListNetworks').val(item.networks||'');$('#routingListDomains').val(item.domains||'');$('#routingListPorts').val(item.ports||'');
   $('#routingListRefresh').val(item.refresh||1440);
   $('#routingListEnabled').prop('checked',String(item.enabled)==='1');$('#routingListError').empty();$('#routingListDialog').modal('show');
  }catch(e){message.text(e.message||'{{ xlang._('Request failed') }}');}
 });
 $('#routingListSave').click(async function(){
  var button=$(this).prop('disabled',true),url=editing?'/api/xray/routingList/setItem/'+editing:'/api/xray/routingList/addItem';
  var payload={list:{name:$('#routingListName').val(),preset:$('#routingListPreset').val(),kind:$('#routingListKind').val(),urls:$('#routingListUrls').val(),
   networks:$('#routingListNetworks').val(),domains:$('#routingListDomains').val(),ports:$('#routingListPorts').val(),refresh:$('#routingListRefresh').val(),enabled:$('#routingListEnabled').is(':checked')?'1':'0'}};
  try{var result=await $.post(url,payload,null,'json');if(result.result!=='saved')throw Error(Object.values(result.validations||{}).join('\n')||result.message||'{{ xlang._('Request failed') }}');
   $('#routingListDialog').modal('hide');message.text('{{ xlang._('Saved. Download and apply the lists.') }}');await load();
  }catch(e){$('#routingListError').text(e.message||'{{ xlang._('Request failed') }}');}finally{button.prop('disabled',false);}
 });
 $('#routingLists').on('click','.routingListRule',async function(){
  var button=$(this).prop('disabled',true);
  try{var result=await $.post('/api/xray/service/setupListRule/'+button.attr('data-id'),{},null,'json');if(result.result!=='saved')throw Error(result.message||'{{ xlang._('Request failed') }}');message.text('{{ xlang._('Firewall rule saved. Review its order and apply it in Firewall Rules.') }}');}
  catch(e){message.text(e.message||'{{ xlang._('Request failed') }}');}finally{button.prop('disabled',false);}
 });
 $('#routingLists').on('click','.routingListDelete',async function(){
   var button=$(this),question=button.attr('data-kind')==='domain'
    ? '{{ xlang._('Delete this routing list? Helper-created firewall rules and the managed alias are removed; rules you created yourself are kept.') }}'
    : '{{ xlang._('Delete this IP routing list? Existing firewall rules are kept until you remove or update them.') }}';
   if(!window.confirm(question))return;
   try{var result=await $.post('/api/xray/routingList/delItem/'+button.attr('data-id'),{},null,'json');if(result.result!=='deleted')throw Error(result.message||'{{ xlang._('Request failed') }}');message.text(result.message||'{{ xlang._('Deleted and applied.') }}');await load();}
  catch(e){message.text(e.message||'{{ xlang._('Request failed') }}');}
 });
 $('#routingListDownload').click(async function(){
  var button=$(this).prop('disabled',true);message.text('{{ xlang._('Downloading and applying routing lists...') }}');
  try{var result=await $.post('/api/xray/service/ipLists',{force:1},null,'json');if(result.result!=='ok')throw Error(result.message||'{{ xlang._('Request failed') }}');message.text(result.message);await load();}
  catch(e){message.text(e.message||'{{ xlang._('Request failed') }}');}finally{button.prop('disabled',false);}
 });
});
</script>
