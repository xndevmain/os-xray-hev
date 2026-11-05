<?php
/** Retain current, active and the newest three immutable geodata releases. */
function xray_prune_releases(string $root, string $activeConfig): void
{
    $root=realpath($root);
    if($root===false) return;
    $keep=[];
    $current=realpath($root.'/current');if($current) $keep[$current]=true;
    if(is_file($activeConfig)) {
        $active=json_decode(file_get_contents($activeConfig),true);
        $path=realpath($active['asset_dir']??'');if($path) $keep[$path]=true;
    }
    $releases=[];
    foreach(glob($root.'/release-*',GLOB_ONLYDIR) as $candidate) {
        if(is_link($candidate)) continue;
        $path=realpath($candidate);
        if($path===false||!preg_match('/^release-[a-f0-9]{16}$/D',basename($path))||dirname($path)!==$root) continue;
        $releases[$path]=filemtime($path);
    }
    arsort($releases,SORT_NUMERIC);
    foreach(array_slice(array_keys($releases),0,3) as $path) $keep[$path]=true;
    foreach($releases as $path=>$time) {
        if(isset($keep[$path])) continue;
        $entries=array_values(array_diff(scandir($path),['.','..']));
        if(array_diff($entries,['geosite.dat','geoip.dat'])) continue;
        foreach($entries as $entry) unlink($path.'/'.$entry);
        rmdir($path);
    }
}
