<?php
/** Read-only preflight; any model serialization below changes this process's memory only. */
require_once 'config.inc';
$root = dirname(__DIR__);
foreach (['Catalog','EndpointCatalog','CatalogStore','Instance','General'] as $name) {
    require_once $root . '/plugin/mvc/app/models/OPNsense/Xray/' . $name . '.php';
}
$cfg = \OPNsense\Core\Config::getInstance()->object();
if (isset($cfg->OPNsense->xray->instance)) throw new RuntimeException('Legacy single-instance schema requires manual migration; nothing was changed');
$instances = new \OPNsense\Xray\Instance();
$count=0;
foreach($instances->instance->iterateItems() as $node) {
    $count++;
    if(trim((string)$node->outbound_config)==='' && ((string)($node->server_address??'')!=='')) throw new RuntimeException('Legacy connection fields require manual migration');
}
if($count>1) throw new RuntimeException('This release supports one tunnel; consolidate instances before installation');
if(!$count) { $node=$instances->instance->Add();$node->name='default';$instances->serializeToConfig(); }
if(!\OPNsense\Xray\CatalogStore::ready()) \OPNsense\Xray\CatalogStore::migrate(false);
else \OPNsense\Xray\EndpointCatalog::active(\OPNsense\Xray\CatalogStore::read());
$compiler=new \Phalcon\Mvc\View\Engine\Volt\Compiler();
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/plugin/mvc/app/views/OPNsense/Xray'));
foreach($it as $file) if($file->isFile()&&$file->getExtension()==='volt') $compiler->compileString(file_get_contents($file->getPathname()));
echo "Native models, migration and Volt preflight passed (configuration not saved)\n";
