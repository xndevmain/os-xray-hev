"""Exercise lazy tabs using actual OPNsense UI libraries in a headless browser.
Usage: python tests/browser_catalog.py --assets DIR --browser PATH
DIR supplies jquery-3.5.1.min.js, bootstrap.min.js, opnsense.js,
tabulator.min.js and opnsense_bootgrid.js from the tested OPNsense host.
"""
import argparse
import ast
import html
import json
from pathlib import Path
import re
import subprocess
import tempfile

parser=argparse.ArgumentParser()
parser.add_argument('--assets',type=Path,required=True)
parser.add_argument('--browser',required=True)
args=parser.parse_args()
root=Path(__file__).resolve().parents[1]
assets=['jquery-3.5.1.min.js','bootstrap.min.js','opnsense.js','tabulator.min.js','opnsense_bootgrid.js']
page='<!doctype html><html><body><main class="page-content"><ul id="maintabs"><li><a href="#endpoints">Endpoints</a></li><li><a href="#subscriptions">Subscriptions</a></li></ul>'
for name in assets:
    asset=(args.assets/name).resolve()
    if not asset.is_file():raise FileNotFoundError(asset)
    page+='<script src="'+asset.as_uri()+'"></script>'
page+='''<script>window.errors=[];window.calls=[];window.addEventListener('error',e=>errors.push(e.message));$.getJSON=url=>$.Deferred().resolve(url.includes('/status/')?{xray_core:'running'}:url.includes('/sources')?{rows:[{id:'source-id',name:'Source',enabled:true,auto_update:false,interval:60,servers:1}],revision:'test'}:{uuid:'tunnel',revision:'test',active_count:0,rows:[{id:'a'.repeat(64),name:'Server',source:'Source',enabled:false,effective:false,present:true,removed:false,active_index:null,server:'one.example',port:443,protocol:'vless',transport:'tcp'}]}).promise();$.post=(url,data)=>{calls.push({url,data});return $.Deferred().resolve({result:url.includes('/refresh/')?'ok':'saved',message:'OK'}).promise();};</script>'''
for name in ['endpoints','subscriptions']:
    source=(root/'plugin/mvc/app/views/OPNsense/Xray/partials'/f'{name}.volt').read_text(encoding='utf-8')
    page+=re.sub(r'\{\{\s*xlang\._\((.*?)\)\s*\}\}',lambda m:html.escape(ast.literal_eval(m[1]),quote=True),source)
page+='''<script>setTimeout(()=>{$('#endpoints,#subscriptions').addClass('active');$('#maintabs a').trigger('shown.bs.tab');},200);setTimeout(()=>{$('.epToggle').first().click();$('.subRefresh').first().click();},650);setTimeout(()=>{document.body.insertAdjacentHTML('beforeend','<pre id="result">'+JSON.stringify({calls,errors})+'</pre>');},1500);</script></main></body></html>'''
with tempfile.TemporaryDirectory(prefix='xray-browser-') as temporary:
    fixture=Path(temporary)/'fixture.html'
    fixture.write_text(page,encoding='utf-8')
    run=subprocess.run([args.browser,'--headless','--disable-gpu','--no-first-run','--user-data-dir='+str(Path(temporary)/'profile'),'--virtual-time-budget=2500','--dump-dom',fixture.as_uri()],capture_output=True,text=True,encoding='utf-8',timeout=45)
    match=re.search(r'<pre id="result">(\{.*?)</pre>',run.stdout)
    if not match:raise RuntimeError('Browser did not return a test result')
    data=json.loads(html.unescape(match[1]))
    assert not data['errors'],data['errors']
    assert len(data['calls'])==2,data['calls']
    assert data['calls'][0]['url']=='/api/xray/catalog/select'
    assert data['calls'][0]['data']['enabled']=='1'
    assert data['calls'][1]['url']=='/api/xray/catalog/refresh/source-id'
    print('Native lazy-tab endpoint and subscription actions: passed')
