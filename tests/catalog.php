<?php
require __DIR__ . '/../plugin/mvc/app/models/OPNsense/Xray/EndpointCatalog.php';
use OPNsense\Xray\EndpointCatalog as C;
function checkCatalog($ok, $why) { if (!$ok) throw new RuntimeException($why); }
$a = ['tag'=>'Server A', 'protocol'=>'trojan', 'settings'=>['servers'=>[['address'=>'a.example','port'=>443,'password'=>'test']]]];
$b = $a; $b['settings']['servers'][0]['address'] = 'b.example';
$state = C::migrate([], [$a]);
checkCatalog(count(C::active($state)) === 1, 'Legacy selection preserved');
checkCatalog(C::migrate($state, [$b]) === $state, 'Migration is idempotent');
$state['sources']['source-a'] = ['url'=>'https://example.com/sub','enabled'=>true];
$rev = C::sourceRevision($state['sources']['source-a']);
$state = C::reconcile($state, 'source-a', $rev, [$a,$b]);
checkCatalog(count(C::active($state)) === 1, 'New subscription servers default disabled');
$keys = array_keys(array_filter($state['endpoints'], fn($r)=>$r['source']==='source-a'));
$state = C::select($state, $keys[1], true);
checkCatalog(count(C::active($state)) === 2, 'Explicit selection activates server');
$renamed = array_reverse($b, true); $renamed['tag'] = 'Renamed';
$state = C::reconcile($state, 'source-a', $rev, [$renamed,$a]);
checkCatalog($state['endpoints'][$keys[1]]['enabled'], 'Reorder and rename preserve selection');
$state = C::select($state, $keys[1], false);
$state = C::reconcile($state, 'source-a', $rev, [$b,$a]);
checkCatalog(!$state['endpoints'][$keys[1]]['enabled'], 'Concurrent disable is preserved');
$state = C::select($state, $keys[1], true);
$state = C::reconcile($state, 'source-a', $rev, [$a]);
checkCatalog(!$state['endpoints'][$keys[1]]['present'] && count(C::active($state))===1, 'Missing server excluded');
$state = C::reconcile($state, 'source-a', $rev, [$a,$b]);
checkCatalog($state['endpoints'][$keys[1]]['enabled'] && count(C::active($state))===2, 'Returning server restores preference');
$state = C::remove($state, $keys[1]);
$state = C::reconcile($state, 'source-a', $rev, [$a,$b]);
checkCatalog($state['endpoints'][$keys[1]]['removed'] && count(C::active($state))===1, 'Removed server stays removed');
$old = $state;
try { C::reconcile($state, 'source-a', $rev, []); throw new Exception('Accepted empty subscription'); }
catch (InvalidArgumentException $expected) {}
checkCatalog($old === $state, 'Failed refresh does not mutate catalog');
$state['sources']['source-a']['url'] = 'https://example.com/changed';
try { C::reconcile($state, 'source-a', $rev, [$a]); throw new Exception('Accepted stale source'); }
catch (RuntimeException $expected) { checkCatalog(str_contains($expected->getMessage(), 'Source changed'), 'Reject stale refresh'); }
$state = C::select($state, $keys[1], true);
$state['sources']['source-a']['enabled'] = false;
checkCatalog(count(C::active($state))===1 && $state['endpoints'][$keys[1]]['enabled'], 'Source switch retains per-server choices');
echo "Catalog regression checks passed: migration, identities, selections, refresh, tombstones, concurrency\n";
