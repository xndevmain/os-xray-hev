<?php
require __DIR__.'/../plugin/scripts/Xray/geodata-releases.php';
$root=sys_get_temp_dir().'/xray-retention-'.bin2hex(random_bytes(6));mkdir($root);
try {
    for($i=1;$i<=6;$i++) {
        $dir=$root.'/release-'.sprintf('%016x',$i);mkdir($dir);
        file_put_contents($dir.'/geosite.dat','fixture');file_put_contents($dir.'/geoip.dat','fixture');touch($dir,100000+$i);
    }
    $active=$root.'/release-'.sprintf('%016x',1);
    file_put_contents($root.'/routing.json',json_encode(['asset_dir'=>$active]));
    xray_prune_releases($root,$root.'/routing.json');
    if(!is_dir($active)||is_dir($root.'/release-'.sprintf('%016x',2))||count(glob($root.'/release-*'))!==4) throw new RuntimeException('Retention did not preserve active and recent releases');
    echo "Geodata retention checks passed\n";
} finally {
    foreach(glob($root.'/release-*') as $dir) {foreach(glob($dir.'/*') as $file) unlink($file);rmdir($dir);}
    unlink($root.'/routing.json');rmdir($root);
}
