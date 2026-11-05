<?php
namespace OPNsense\Xray;
final class CatalogStore
{
    public static function ready(): bool { return (string)(new Catalog())->migrated === '1'; }
    public static function read(): array
    {
        $model = new Catalog();
        if ((string)$model->migrated !== '1') throw new \RuntimeException('Endpoint catalog has not been migrated');
        $state = ['version'=>1,'sources'=>[],'endpoints'=>[]];
        foreach ($model->source->iterateItems() as $id=>$node) {
            $s=[];
            foreach (['name','url','last_attempt','last_success','error'] as $key) $s[$key]=(string)$node->$key;
            foreach (['enabled','auto_update'] as $key) $s[$key]=(string)$node->$key === '1';
            $s['interval']=(int)(string)$node->interval;
            $state['sources'][$id]=$s;
        }
        foreach ($model->endpoint->iterateItems() as $node) {
            $r=[];
            foreach (['source','identity','name'] as $key) $r[$key]=(string)$node->$key;
            foreach (['enabled','present','removed'] as $key) $r[$key]=(string)$node->$key === '1';
            $r['outbound']=json_decode((string)$node->outbound,true,512,JSON_THROW_ON_ERROR);
            $state['endpoints'][(string)$node->key]=$r;
        }
        return $state;
    }
    public static function revision(array $state): string { return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR)); }
    public static function lock()
    {
        $lock=fopen('/var/run/xray-catalog.lock','ce');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new \RuntimeException('Another catalog operation is running');
        return $lock;
    }
    public static function transaction(callable $fn)
    {
        $lock=self::lock(); $config=\OPNsense\Core\Config::getInstance();
        $config->lock();
        try { return $fn(); }
        finally { $config->unlock(); flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function save(array $state, bool $project=true, ?string $legacy=null, bool $persist=true): void
    {
        $pool=EndpointCatalog::active($state);
        $config=\OPNsense\Core\Config::getInstance();
        if ($project && !$pool && (string)$config->object()->OPNsense->xray->general->enabled==='1') {
            throw new \RuntimeException('Disable Xray before disabling its last active endpoint');
        }
        $model=new Catalog(); $model->migrated='1';
        if ($legacy!==null) $model->legacy_backup=$legacy;
        $sourceNodes=[]; foreach($model->source->iterateItems() as $id=>$node) $sourceNodes[$id]=$node;
        foreach($sourceNodes as $id=>$node) if(!isset($state['sources'][$id])) $model->source->del($id);
        foreach($state['sources'] as $id=>$source) {
            $node=$sourceNodes[$id]??$model->source->Add($id);
            foreach($source as $key=>$value) $node->$key=is_bool($value)?($value?'1':'0'):(string)$value;
        }
        $nodes=[]; $ids=[];
        foreach($model->endpoint->iterateItems() as $id=>$node) { $nodes[(string)$node->key]=$node; $ids[(string)$node->key]=$id; }
        foreach($nodes as $key=>$node) if(!isset($state['endpoints'][$key])) $model->endpoint->del($ids[$key]);
        foreach($state['endpoints'] as $key=>$record) {
            $node=$nodes[$key]??$model->endpoint->Add(); $node->key=$key;
            foreach($record as $field=>$value) $node->$field=$field==='outbound'?json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):(is_bool($value)?($value?'1':'0'):(string)$value);
        }
        foreach($model->performValidation() as $error) throw new \RuntimeException((string)$error);
        $instances=new Instance();
        if($project) {
            $count=0;
            foreach($instances->instance->iterateItems() as $node) { $count++; $node->outbound_config=json_encode($pool,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE); }
            if($count!==1) throw new \RuntimeException('Exactly one tunnel is required');
            foreach($instances->performValidation() as $error) throw new \RuntimeException((string)$error);
        }
        $model->serializeToConfig();
        if($project) $instances->serializeToConfig();
        if ($persist) $config->save();
    }
    public static function migrate(bool $write): array
    {
        if(self::ready()) return ['result'=>'ok','message'=>'Already migrated'];
        $raw='';$count=0;
        foreach((new Instance())->instance->iterateItems() as $node) { $raw=(string)$node->outbound_config; $count++; }
        if($count!==1) throw new \RuntimeException('Exactly one tunnel is required');
        $legacy=trim($raw)===''?[]:json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(isset($legacy['protocol'])) $legacy=[$legacy];
        $state=EndpointCatalog::migrate([],$legacy);
        $active=EndpointCatalog::active($state);
        if(array_map([EndpointCatalog::class,'identity'],$active)!==array_values(array_unique(array_map([EndpointCatalog::class,'identity'],$legacy)))) {
            throw new \RuntimeException('Migration changed endpoint identities');
        }
        self::save($state,true,$raw,$write);
        return ['result'=>'ok','endpoints'=>count($active),'written'=>$write];
    }
}
