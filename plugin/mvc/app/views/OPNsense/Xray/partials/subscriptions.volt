<div id="subscriptions" class="tab-pane fade">
  <div class="panel-body">
    <table id="subGrid" class="table table-condensed table-hover table-striped"><thead><tr>
      <th data-column-id="name" data-formatter="text">{{ xlang._('Name') }}</th>
      <th data-column-id="enabled" data-formatter="flag">{{ xlang._('Enabled') }}</th>
      <th data-column-id="auto_update" data-formatter="flag">{{ xlang._('Automatic updates') }}</th>
      <th data-column-id="interval" data-type="numeric">{{ xlang._('Interval (minutes)') }}</th>
      <th data-column-id="servers" data-type="numeric">{{ xlang._('Servers') }}</th>
      <th data-column-id="last_success" data-formatter="time">{{ xlang._('Last successful update') }}</th>
      <th data-column-id="error" data-formatter="text">{{ xlang._('Last error') }}</th>
      <th data-column-id="commands" data-formatter="commands" data-sortable="false"></th>
    </tr></thead><tbody></tbody></table>
    <p id="subEmpty" class="text-center text-muted" hidden>{{ xlang._('No subscriptions yet. Existing endpoints remain manual.') }}</p>
    <div class="btn-toolbar">
      <button type="button" class="btn btn-primary" id="subNew"><i class="fa fa-plus"></i> {{ xlang._('Add subscription') }}</button>
      <button type="button" class="btn btn-default" id="subReload"><i class="fa fa-refresh"></i> {{ xlang._('Refresh') }}</button>
    </div>
    <p class="help-block">{{ xlang._('New servers are disabled. Select the servers you need in Endpoints, then click Apply. Disabled servers stay disabled after updates.') }}</p>
    <span id="subMessage" role="status"></span>
  </div>
</div>
<div id="subDialog" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="subTitle">
 <div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title" id="subTitle">{{ xlang._('Subscription') }}</h4></div>
  <div class="modal-body"><div class="panel-body">
   <div class="form-group"><label for="subName">{{ xlang._('Name') }}</label><input id="subName" class="form-control" maxlength="128"></div>
   <div class="form-group"><label for="subUrl">{{ xlang._('HTTPS subscription URL') }}</label><input id="subUrl" type="url" class="form-control" autocomplete="off" spellcheck="false"></div>
   <div class="checkbox"><label><input id="subEnabled" type="checkbox"> {{ xlang._('Enabled') }}</label></div>
   <div class="checkbox"><label><input id="subAuto" type="checkbox"> {{ xlang._('Automatic updates') }}</label></div>
   <div class="form-group"><label for="subInterval">{{ xlang._('Interval (minutes)') }}</label><input id="subInterval" type="number" min="15" max="10080" class="form-control"></div>
   <span id="subError" class="text-danger" role="status"></span>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">{{ xlang._('Cancel') }}</button><button type="button" class="btn btn-primary" id="subSave">{{ xlang._('Save') }}</button></div>
 </div></div>
</div>
<script>
$(function(){
 var grid=$('#subGrid'),ready,revision='',editing='',message=$('#subMessage');
 function text(value){return $('<div>').text(value==null?'':value).html();}
 function load(){
  if(!ready){
   ready=$.Deferred();
   grid=grid.UIBootgrid({datakey:'id',options:{ajax:false,rowCount:[10,25,-1],formatters:{
    text:function(c,r){return text(r[c.id]);},flag:function(c,r){return '<i class="fa fa-'+(r[c.id]?'check-square-o':'square-o')+'"></i>';},
    time:function(c,r){return r[c.id]?text(new Date(Number(r[c.id])*1000).toLocaleString()):'—';},
    commands:function(c,r){return '<div class="btn-group"><button type="button" class="btn btn-default btn-xs subEdit" data-id="'+r.id+'" title="{{ xlang._("Edit") }}"><i class="fa fa-pencil"></i></button><button type="button" class="btn btn-default btn-xs subRefresh" data-id="'+r.id+'" title="{{ xlang._("Update subscription") }}"><i class="fa fa-download"></i></button><button type="button" class="btn btn-default btn-xs subDelete" data-id="'+r.id+'" title="{{ xlang._("Delete") }}"><i class="fa fa-trash"></i></button></div>';}
   }}});
   grid.data('UIBootgrid').getTable().on('tableBuilt',function(){ready.resolve();});
  }
  return ready.then(function(){return $.getJSON('/api/xray/catalog/sources');}).then(function(data){
   revision=data.revision; $('#subEmpty').prop('hidden',data.rows.length!==0);
   return grid.data('UIBootgrid').getTable().setData(data.rows);
  }).catch(function(){message.text('{{ xlang._("Request failed") }}');});
 }
 $('#maintabs a[href="#subscriptions"]').on('shown.bs.tab',load);
 if($('#subscriptions').hasClass('active')) load();
 $('#subReload').click(load);
 $('#subNew').click(function(){editing='';$('#subName,#subUrl').val('');$('#subEnabled').prop('checked',true);$('#subAuto').prop('checked',false);$('#subInterval').val(60);$('#subError').empty();$('#subDialog').modal('show');});
 $('#subscriptions').on('click','.subEdit',async function(){
  try{ editing=$(this).attr('data-id');var data=await $.getJSON('/api/xray/catalog/getSource/'+editing);if(!data.source) throw Error('{{ xlang._("Subscription not found") }}');
   revision=data.revision;var s=data.source;$('#subName').val(s.name);$('#subUrl').val(s.url);$('#subEnabled').prop('checked',s.enabled);$('#subAuto').prop('checked',s.auto_update);$('#subInterval').val(s.interval);$('#subError').empty();$('#subDialog').modal('show');
  }catch(e){message.text(e.message);}
 });
 $('#subSave').click(async function(){
  var button=$(this).prop('disabled',true),isNew=!editing,saved=false;
  try {
   var result=await $.post('/api/xray/catalog/saveSource/'+editing,{name:$('#subName').val(),url:$('#subUrl').val(),enabled:$('#subEnabled').is(':checked')?'1':'0',auto_update:$('#subAuto').is(':checked')?'1':'0',interval:$('#subInterval').val(),revision:revision},null,'json');
   if(result.result!=='saved') throw Error(result.error);
   saved=true;$('#subDialog').modal('hide');$('#subUrl').val('');
   if(isNew){
    message.text('{{ xlang._("Updating subscription...") }}');
    await load();
    var update=await $.post('/api/xray/catalog/refresh/'+encodeURIComponent(result.id),{},null,'json');
    if(update.result!=='ok') throw Error(update.error || '{{ xlang._("Request failed") }}');
    message.text(update.message);
   }else message.text('{{ xlang._("Saved. Click Apply to activate.") }}');
  }catch(e){
   (saved?message:$('#subError')).text(e.message||'{{ xlang._("Request failed") }}');
  }finally{
   button.prop('disabled',false);
   if(saved){await load();$(document).trigger('xrayTunnelChanged');}
  }
 });
 $('#subscriptions').on('click','.subRefresh',async function(){
  var button=$(this).prop('disabled',true),id=button.attr('data-id');message.text('{{ xlang._("Updating subscription...") }}');
  try{var result=await $.post('/api/xray/catalog/refresh/'+id,{},null,'json');if(result.result!=='ok') throw Error(result.error);message.text(result.message);}
  catch(e){message.text(e.message||'{{ xlang._("Request failed") }}');}finally{button.prop('disabled',false);await load();$(document).trigger('xrayTunnelChanged');}
 });
 $('#subscriptions').on('click','.subDelete',async function(){
  if(!window.confirm('{{ xlang._("Delete this subscription and its endpoints?") }}')) return;
  try{var result=await $.post('/api/xray/catalog/deleteSource/'+$(this).attr('data-id'),{revision:revision},null,'json');if(result.result!=='saved') throw Error(result.error);message.text('{{ xlang._("Saved. Click Apply to activate.") }}');await load();$(document).trigger('xrayTunnelChanged');}
  catch(e){message.text(e.message||'{{ xlang._("Request failed") }}');}
 });
 $('#subDialog').on('hidden.bs.modal',function(){$('#subUrl').val('');});
});
</script>
