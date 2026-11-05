<?php
require_once('config.inc');
$config = OPNsense\Core\Config::getInstance();
$config->lock();
try {
    $model = new OPNsense\Xray\Instance();
    foreach ($model->instance->iterateItems() as $existing) {
        echo "Existing tunnel preserved\n";
        return;
    }
    $node = $model->instance->Add();
    $node->name = 'default';
    $node->enabled = '0';
    $model->serializeToConfig();
    $config->save();
    echo "Default tunnel created\n";
} finally { $config->unlock(); }
