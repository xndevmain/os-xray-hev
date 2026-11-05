<?php
/** Isolated refresh-worker integration test. No production Config or services are loaded. */
namespace OPNsense\Core {
    class Config { private static $self; public static function getInstance(){return self::$self??=new self();} public function lock(){} public function unlock(){} }
}
namespace OPNsense\Xray {
    class CatalogStore {
        public static array $state=[];
        public static function ready(){return true;}
        public static function read(){return self::$state;}
        public static function lock(){return fopen('php://temp','w+b');}
        public static function transaction($fn){return $fn();}
        public static function save($state,$project=true){self::$state=$state;}
    }
    class HttpsDownload {
        public static string $body='';public static bool $fail=false;
        public static function fetch($url,$stream,$limit){if(self::$fail) throw new \RuntimeException('Simulated TLS failure');fwrite($stream,self::$body);rewind($stream);}
    }
}
namespace {
    require __DIR__.'/../plugin/mvc/app/models/OPNsense/Xray/EndpointCatalog.php';
    require __DIR__.'/../plugin/mvc/app/models/OPNsense/Xray/Subscription.php';
    use OPNsense\Xray\CatalogStore as S;
    use OPNsense\Xray\EndpointCatalog as E;
    use OPNsense\Xray\HttpsDownload as H;
    function xray_get_config(){return ['enabled'=>$GLOBALS['simulateRunning']??false,'inst_uuid'=>'test'];}
    function xray_preflight($instances){return true;}
    function xray_conf_path($id){return __DIR__.'/output/refresh-runtime.json';}
    function xray_build_config($c){return ['fixture'=>true];}
    function xray_acquire_service_lock(){
        if($GLOBALS['serviceBusy']??false) throw new RuntimeException('Another service operation is running');
        return fopen('php://temp','w+b');
    }
    function xray_activate_current(){ $GLOBALS['activationCalls']++;return !($GLOBALS['activationCalls']===1&&($GLOBALS['failActivation']??false)); }
    function xray_pid_path($id){return __DIR__.'/output/no-such-runtime.pid';}
    function proc_is_running($file){return $GLOBALS['simulateRunning']??false;}
    $script=file_get_contents(__DIR__.'/../plugin/scripts/Xray/subscriptions.php');
    $start=strpos($script,'function refresh_subscription');
    $end=strpos($script,"\ntry {\n    \$action=",$start);
    eval('use OPNsense\Xray\CatalogStore as Store; use OPNsense\Xray\EndpointCatalog as Engine;' . substr($script,$start,$end-$start));
    $a=['tag'=>'Manual','protocol'=>'trojan','settings'=>['servers'=>[['address'=>'manual.example','port'=>443,'password'=>'fixture']]]];
    S::$state=E::migrate([],[$a]);
    $id='e487a5e0-54cc-4112-a41e-145119219d13';
    S::$state['sources'][$id]=['url'=>'https://example.com/sub','enabled'=>true,'auto_update'=>true,'interval'=>60,'last_attempt'=>'','last_success'=>'','error'=>''];
    H::$body='trojan://fixture@remote.example:443#Remote';
    $r=refresh_subscription($id);
    if($r['result']!=='ok'||count(S::$state['endpoints'])!==2||count(E::active(S::$state))!==1) throw new Exception('Initial refresh changed active pool');
    $records=array_filter(S::$state['endpoints'],fn($r)=>$r['source']===$id);$key=array_key_first($records);
    S::$state=E::select(S::$state,$key,true);
    S::$state=E::select(S::$state,$key,false);
    H::$body='trojan://fixture@remote.example:443#Renamed';refresh_subscription($id);
    if(S::$state['endpoints'][$key]['enabled']||S::$state['endpoints'][$key]['name']!=='Renamed') throw new Exception('Preference lost');
    $before=S::$state['endpoints'];H::$fail=true;
    try {refresh_subscription($id);throw new Exception('Expected TLS failure');} catch(RuntimeException $expected){}
    if(S::$state['endpoints']!==$before||S::$state['sources'][$id]['error']!=='Simulated TLS failure') throw new Exception('Failure did not preserve catalog');
    H::$fail=false;
    S::$state=E::select(S::$state,$key,true);
    $before=S::$state['endpoints'];$GLOBALS['simulateRunning']=true;$GLOBALS['activationCalls']=0;$GLOBALS['failActivation']=true;
    file_put_contents(xray_conf_path('test'),json_encode(['fixture'=>true]));
    H::$body='trojan://fixture@replacement.example:443#Replacement';
    try {refresh_subscription($id);throw new Exception('Expected activation failure');} catch(RuntimeException $expected) {
        if(!str_contains($expected->getMessage(),'previous catalog and service restored')) throw $expected;
    }
    if($GLOBALS['activationCalls']!==2||S::$state['endpoints']!==$before) throw new Exception('Rollback failed');
    $GLOBALS['activationCalls']=0;$GLOBALS['failActivation']=false;
    $GLOBALS['serviceBusy']=true;
    try {refresh_subscription($id);throw new Exception('Expected busy service rejection');} catch(RuntimeException $expected){}
    if(S::$state['endpoints']!==$before||$GLOBALS['activationCalls']!==0) throw new Exception('Busy service changed catalog or runtime');
    $GLOBALS['serviceBusy']=false;
    $result=refresh_subscription($id);
    if($GLOBALS['activationCalls']!==1||$result['result']!=='ok'||S::$state['endpoints'][$key]['present']) throw new Exception('Automatic activation failed');
    unlink(xray_conf_path('test'));
    echo "Refresh worker tests passed: parse, reconcile, disabled preference, TLS failure, activation and rollback\n";
}
