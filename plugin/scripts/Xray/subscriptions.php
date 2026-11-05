#!/usr/local/bin/php
<?php
define('XRAY_LIBRARY_ONLY',true);
require_once __DIR__.'/xray-service-control.php';
use OPNsense\Xray\CatalogStore as Store;
use OPNsense\Xray\EndpointCatalog as Engine;
function refresh_subscription(string $id): array
{
    $snapshot=Store::read();$source=$snapshot['sources'][$id]??null;
    if(!$source) throw new RuntimeException('Subscription not found');
    $revision=Engine::sourceRevision($source);
    try {
        $stream=fopen('php://temp/maxmemory:1048576','w+b');
        try {
            OPNsense\Xray\HttpsDownload::fetch($source['url'],$stream,1048576);
            $parsed=OPNsense\Xray\Subscription::parse(stream_get_contents($stream),Engine::MAX_RECORDS);
        } finally { fclose($stream); }
        $incoming=json_decode($parsed['outbound_config'],true,512,JSON_THROW_ON_ERROR);
        if(isset($incoming['protocol'])) $incoming=[$incoming];
        foreach($incoming as $i=>&$item) $item['tag']=$parsed['servers'][$i]??'';
        unset($item);
        $serviceLock=null;
        $lock=Store::lock();$config=OPNsense\Core\Config::getInstance();
        try {
            $config->lock();
            try {
                $before=Store::read();
                $next=Engine::reconcile($before,$id,$revision,$incoming);
                $c=xray_get_config();
                $oldPool=json_encode(Engine::active($before));$newPool=json_encode(Engine::active($next));
                $changed=$oldPool!==$newPool;
                $autoApply=false;
                if($changed) {
                    $serviceLock=xray_acquire_service_lock();
                    $candidate=$c;$candidate['outbound_config']=$newPool;
                    ob_start();try { $valid=xray_preflight([$candidate]); } finally { ob_end_clean(); }
                    if(!$valid) throw new RuntimeException('Updated endpoints failed Xray validation; previous configuration retained');
                    $runtime=is_file(xray_conf_path($c['inst_uuid']))?json_decode(file_get_contents(xray_conf_path($c['inst_uuid'])),true):null;
                    $autoApply=$c['enabled'] && proc_is_running(xray_pid_path($c['inst_uuid']))
                        && $runtime==xray_build_config($c);
                }
                $next['sources'][$id]['last_attempt']=(string)time();
                $next['sources'][$id]['last_success']=(string)time();
                $next['sources'][$id]['error']='';
                Store::save($next);
            } finally { $config->unlock(); }
            if($changed && $autoApply) {
                ob_start();
                try { $activated=xray_activate_current(); } catch(Throwable $error) { $activated=false; }
                finally { ob_end_clean(); }
                if(!$activated) {
                    $config->lock();
                    try { Store::save($before); } finally { $config->unlock(); }
                    ob_start();
                    try { $rollbackRc=xray_activate_current()?0:1; } catch(Throwable $error) { $rollbackRc=1; }
                    finally { ob_end_clean(); }
                    throw new RuntimeException($rollbackRc===0?'Activation failed; previous catalog and service restored':'Activation and rollback failed; inspect service status');
                }
            }
            return ['result'=>'ok','message'=>$changed&&!$autoApply?'Subscription updated. Click Apply to activate.':'Subscription updated. New servers are disabled until selected.', 'count'=>count($incoming)];
        } finally {
            if(is_resource($serviceLock)) { flock($serviceLock,LOCK_UN);fclose($serviceLock); }
            flock($lock,LOCK_UN);fclose($lock);
        }
    } catch(Throwable $error) {
        try { Store::transaction(function() use($id,$revision,$error) {
            $state=Store::read();
            if(isset($state['sources'][$id]) && hash_equals(Engine::sourceRevision($state['sources'][$id]),$revision)) {
                $state['sources'][$id]['last_attempt']=(string)time();
                $state['sources'][$id]['error']=$error->getMessage();
                Store::save($state,false);
            }
        }); } catch(Throwable $ignored) {}
        throw $error;
    }
}
try {
    $action=$argv[1]??'auto';
    if($action==='migrate'||$action==='check') {
        $result=Store::transaction(fn()=>Store::migrate($action==='migrate'));
    } elseif($action==='auto') {
        if(!Store::ready()) exit;
        $cronLock=fopen('/var/run/xray-subscriptions-cron.lock','ce');
        if(!$cronLock||!flock($cronLock,LOCK_EX|LOCK_NB)) exit;
        $count=0;
        foreach(Store::read()['sources'] as $id=>$source) {
            if(!$source['enabled']||!$source['auto_update']||time()-(int)$source['last_attempt']<max(15,$source['interval'])*60) continue;
            try { refresh_subscription($id); $count++; }
            catch(Throwable $e) { syslog(LOG_ERR,'Xray subscription refresh failed: '.$e->getMessage()); }
        }
        $result=['result'=>'ok','updated'=>$count];
    } else {
        if(!preg_match('/^[a-f0-9-]{36}$/D',$action)) throw new RuntimeException('Invalid subscription ID');
        $result=refresh_subscription($action);
    }
    echo json_encode($result),"\n";
} catch(Throwable $e) { echo json_encode(['result'=>'failed','error'=>$e->getMessage()]),"\n";exit(1); }
