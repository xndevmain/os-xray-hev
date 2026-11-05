<?php
namespace OPNsense\Xray\Api;
use OPNsense\Xray\CatalogStore as Store;
use OPNsense\Xray\EndpointCatalog as Engine;
class CatalogController extends \OPNsense\Base\ApiControllerBase
{
    private function mutate(callable $fn)
    {
        if(!$this->request->isPost()) return ['result'=>'failed','error'=>'POST required'];
        try { return Store::transaction(function() use($fn) {
            $state=Store::read();
            $revision=$this->request->getPost('revision',null,'');
            if($revision!=='' && !hash_equals(Store::revision($state),$revision)) throw new \RuntimeException('Catalog changed. Refresh and select again.');
            $next=$fn($state); Store::save($next);
            return ['result'=>'saved','count'=>count($next['endpoints'])];
        }); } catch(\Throwable $e) { return ['result'=>'failed','error'=>$e->getMessage()]; }
    }
    public function endpointsAction()
    {
        try {
            $state=Store::read();$rows=[];$indices=[];
            foreach(Engine::active($state) as $i=>$outbound) $indices[Engine::identity($outbound)]=$i;
            foreach($state['endpoints'] as $key=>$record) {
                $item=$record['outbound'];$peer=$item['settings']['vnext'][0]??$item['settings']['servers'][0]??[];
                $effective=$record['enabled']&&$record['present']&&!$record['removed']&&($record['source']===''||($state['sources'][$record['source']]['enabled']??false));
                $rows[]=['id'=>$key,'name'=>$record['name'],'source'=>$record['source']===''?'Manual':($state['sources'][$record['source']]['name']??'Missing source'),
                    'enabled'=>$record['enabled'],'present'=>$record['present'],'removed'=>$record['removed'],'effective'=>$effective,
                    'active_index'=>$effective?($indices[$record['identity']]??null):null,
                    'server'=>$peer['address']??'','port'=>$peer['port']??'','protocol'=>$item['protocol'],'transport'=>$item['streamSettings']['network']??'tcp'];
            }
            $uuid='';foreach((new \OPNsense\Xray\Instance())->instance->iterateItems() as $id=>$node) $uuid=$id;
            return ['rows'=>$rows,'uuid'=>$uuid,'revision'=>Store::revision($state),'active_count'=>count($indices)];
        } catch(\Throwable $e) { return ['error'=>$e->getMessage()]; }
    }
    public function selectAction()
    {
        return $this->mutate(function($state) {
            $ids=$this->request->getPost('ids'); if(!is_array($ids)||!$ids) throw new \InvalidArgumentException('Select endpoints');
            $enable=$this->request->getPost('enabled')==='1';
            foreach($ids as $key) $state=Engine::select($state,(string)$key,$enable);
            return $state;
        });
    }
    public function removeAction()
    {
        return $this->mutate(function($state) {
            $ids=$this->request->getPost('ids'); if(!is_array($ids)||!$ids) throw new \InvalidArgumentException('Select endpoints');
            foreach($ids as $key) $state=Engine::remove($state,(string)$key);
            return $state;
        });
    }
    public function appendAction()
    {
        return $this->mutate(function($state) {
            $items=json_decode($this->request->getPost('outbounds',null,''),true,512,JSON_THROW_ON_ERROR);
            $names=json_decode($this->request->getPost('names',null,'[]'),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($items)||!array_is_list($items)||!$items||count($items)>32||!is_array($names)) throw new \InvalidArgumentException('Invalid endpoints');
            foreach($items as $i=>&$item) {
                $name=$names[$i]??($item['tag']??'');
                if(!is_string($name)||strlen($name)>1024||preg_match('/[\x00-\x1f\x7f]/',$name)) throw new \InvalidArgumentException('Invalid endpoint name');
                $item['tag']=$name;
            } unset($item);
            $manual=Engine::migrate([],$items);
            foreach($manual['endpoints'] as $key=>$record) {
                if(isset($state['endpoints'][$key])) $record['enabled']=$state['endpoints'][$key]['enabled'];
                $state['endpoints'][$key]=$record;
            }
            if(count($state['endpoints'])>Engine::MAX_RECORDS) throw new \RuntimeException('Catalog limit reached');
            return $state;
        });
    }
    public function sourcesAction()
    {
        $state=Store::read();$rows=[];
        foreach($state['sources'] as $id=>$source) {
            unset($source['url']);$source['id']=$id;
            $source['servers']=count(array_filter($state['endpoints'],fn($r)=>$r['source']===$id&&$r['present']&&!$r['removed']));
            $rows[]=$source;
        }
        return ['rows'=>$rows,'revision'=>Store::revision($state)];
    }
    public function getSourceAction($id='')
    {
        $state=Store::read();return ['source'=>$state['sources'][$id]??null,'revision'=>Store::revision($state)];
    }
    public function saveSourceAction($id='')
    {
        $result = $this->mutate(function($state) use(&$id) {
            if($id!==''&&!isset($state['sources'][$id])) throw new \RuntimeException('Subscription not found');
            $name=trim($this->request->getPost('name',null,''));$url=trim($this->request->getPost('url',null,''));
            $interval=filter_var($this->request->getPost('interval'),FILTER_VALIDATE_INT);
            if($name===''||strlen($name)>128||$interval<15||$interval>10080) throw new \InvalidArgumentException('Enter a name and interval from 15 to 10080 minutes');
            $parts=parse_url($url);
            if(!filter_var($url,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||strlen($url)>8192) throw new \InvalidArgumentException('Enter an HTTPS subscription URL without credentials');
            if($id==='' && count($state['sources'])>=32) throw new \RuntimeException('Maximum 32 subscriptions');
            if($id==='') { $hex=bin2hex(random_bytes(16));$id=substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-a'.substr($hex,17,3).'-'.substr($hex,20); }
            $old=$state['sources'][$id]??['last_attempt'=>'','last_success'=>'','error'=>''];
            $state['sources'][$id]=array_merge($old,['name'=>$name,'url'=>$url,'enabled'=>$this->request->getPost('enabled')==='1','auto_update'=>$this->request->getPost('auto_update')==='1','interval'=>$interval]);
            return $state;
        });
        if (($result['result'] ?? '') === 'saved') $result['id'] = $id;
        return $result;
    }
    public function deleteSourceAction($id='')
    {
        return $this->mutate(function($state) use($id) {
            if(!isset($state['sources'][$id])) throw new \RuntimeException('Subscription not found');
            unset($state['sources'][$id]);
            $state['endpoints']=array_filter($state['endpoints'],fn($r)=>$r['source']!==$id);
            return $state;
        });
    }
    public function refreshAction($id='')
    {
        if(!$this->request->isPost()||!preg_match('/^[a-f0-9-]{36}$/D',$id)) return ['result'=>'failed','error'=>'Invalid request'];
        session_write_close();
        $raw=(new \OPNsense\Core\Backend())->configdpRun('xray subscriptionrefresh',[$id]);
        return json_decode($raw,true)?:['result'=>'failed','error'=>'No valid response from subscription updater'];
    }
}
