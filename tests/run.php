<?php
declare(strict_types=1);
require $argv[1].'/php/Arnproxy.php';
use ArnproxySdk\{Client,ArnproxyError};
$f=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);$resources=json_decode(file_get_contents($argv[1].'/resources.json'),true)['resources'];$checks=0;
// Public synthetic fixtures, never production signing secrets.
foreach($f['vectors'] as &$vector){
    if(isset($vector['secret_fixture'])){
        $tag=$vector['secret_fixture'];
        if(!in_array($tag,['repeat_X_32','repeat_Y_32'],true))throw new RuntimeException('INVALID_TEST_FIXTURE');
        $vector['secret']='whsec_'.base64_encode(str_repeat($tag==='repeat_X_32'?'X':'Y',32));
    }
}
unset($vector);

function ck(bool$v):void{global$checks;if(!$v)throw new RuntimeException('ASSERT_'.$checks);$checks++;}
function err(callable$c,string$code):ArnproxyError{global$f;try{$c();throw new RuntimeException('NO_ERROR');}catch(ArnproxyError$e){ck($e->error_code===$code);ck(!str_contains($e->getMessage(),$f['key']));return$e;}}
class MockClient extends Client{public array $calls=[];public int $status=200;public array $received=[];public string $raw;public bool $failure=false;public function __construct(array$o=[]){global$f;parent::__construct($f['key'],$o);$this->raw=json_encode($f['body']);}protected function send(string$m,string$u,array$h,?string$b):array{$this->calls[]=[$m,$u,$h,$b];if($this->failure)throw new RuntimeException('sensitive transport detail');return[$this->status,$this->received,$this->raw];}}
$c=new MockClient(['allow_writes'=>true,'allow_provider_reads'=>true]);
foreach($resources as$name=>$spec){$params=str_contains($spec['path'],'{id}')?['id'=>123]:[];$params['q']='ü & = ? /';$key=$spec['idempotency']?$f['operation_key']:null;$n=count($c->calls);
    if($spec['disabled']){err(fn()=>$c->request($name,$params,$key),'DISABLED_RESOURCE');ck(count($c->calls)===$n);continue;}
    ck($c->request($name,$params,$key)['data']['id']===2);[$method,$url,$headers,$body]=end($c->calls);ck($method===$spec['method']);ck(parse_url($url,PHP_URL_PATH)==='/account/api/v1/'.str_replace('{id}','123',$spec['path']));ck(parse_url($url,PHP_URL_HOST)==='www.arnproxy.com');ck(!str_contains($url,$f['key']));ck(in_array('Authorization: Bearer '.$f['key'],$headers,true));ck(in_array('Idempotency-Key: '.$f['operation_key'],$headers,true)===$spec['idempotency']);if($method==='GET'){parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);ck($q['q']===$params['q']);ck($body===null);}else ck(json_decode($body,true)===$params);ck(count($c->calls)===$n+1);
    $guarded=new MockClient();if($spec['write']||$spec['provider_read']){err(fn()=>$guarded->request($name,$params,$key),$spec['write']?'WRITES_DISABLED':'PROVIDER_READS_DISABLED');ck(!$guarded->calls);}if($spec['idempotency']){err(fn()=>$c->request($name,$params),'OPERATION_KEY_REQUIRED');ck(count($c->calls)===$n+1);}
}
foreach(['',$f['key']."\n",' '.$f['key'],str_repeat('x',31)]as$key)err(fn()=>new Client($key),'INVALID_KEY_FORMAT');
err(fn()=>$c->request('account',['resource'=>'purchase']),'INVALID_PARAMETERS');err(fn()=>$c->request('proxy',['id'=>'1/../../secret']),'INVALID_ROUTE_ID');err(fn()=>$c->request('orders',['id'=>[1,2]]),'INVALID_QUERY_VALUE');err(fn()=>$c->request('quote',['bad'=>NAN]),'INVALID_JSON_BODY');err(fn()=>$c->request('quote',['x'=>str_repeat('a',2097152)]),'REQUEST_TOO_LARGE');
$c->status=302;$c->raw='';$n=count($c->calls);ck(!err(fn()=>$c->account(),'REDIRECT_BLOCKED')->uncertain);ck(count($c->calls)===$n+1);
$c->status=429;$c->raw=json_encode(['ok'=>false,'error'=>['code'=>'RATE_LIMITED'],'meta'=>['request_id'=>$f['request_id']]]);$c->received=['retry-after'=>'12'];$e=err(fn()=>$c->account(),'RATE_LIMITED');ck($e->http_status===429&&$e->retry_after===12&&$e->request_id===$f['request_id']);
$c->status=500;$c->raw='<html>not json</html>';ck(err(fn()=>$c->request('purchase',[],$f['operation_key']),'INVALID_API_RESPONSE')->uncertain);$c->failure=true;$n=count($c->calls);ck(err(fn()=>$c->request('purchase',[],$f['operation_key']),'TRANSPORT_ERROR')->uncertain);ck(count($c->calls)===$n+1);
$c->failure=false;$c->status=200;$c->raw=json_encode(['ok'=>true,'data'=>['state'=>'unknown'],'meta'=>['request_id'=>$f['request_id']]]);ck($c->request('purchase',[],$f['operation_key'])['data']['state']==='unknown');ob_start();var_dump(new Client($f['key']));$dump=ob_get_clean();ck(!str_contains($dump,$f['key']));err(fn()=>serialize(new Client($f['key'])),'SERIALIZATION_DISABLED');
foreach($f['vectors']as$v){$call=fn()=>Client::verifyWebhook($v['body'],$v['headers'],$v['secret'],$v['now']);if($v['valid'])ck(str_starts_with($call()['id'],'evt_'));else err($call,'WEBHOOK_VERIFICATION_FAILED');}

// Fake the native cURL extension in-process: these functions cannot contact a network.
$constants=['CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_PROTOCOLS','CURLOPT_PROXY','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT_MS','CURLOPT_NOSIGNAL','CURLOPT_HEADERFUNCTION','CURLOPT_WRITEFUNCTION','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE','CURLPROTO_HTTPS','CURLE_OPERATION_TIMEDOUT'];foreach($constants as$i=>$name)define($name,$i+1);
$nativeCalls=[];function curl_init($url){global$nativeCalls;$o=(object)['url'=>$url,'options'=>[],'closed'=>false];$nativeCalls[]=$o;return$o;}function curl_setopt_array($c,$o){$c->options=$o;return true;}function curl_setopt($c,$k,$v){$c->options[$k]=$v;return true;}function curl_exec($c){global$f;$c->options[CURLOPT_HEADERFUNCTION]($c,"Retry-After: 2\r\n");$c->options[CURLOPT_WRITEFUNCTION]($c,json_encode($f['body']));return true;}function curl_errno($c){return 0;}function curl_getinfo($c,$k){return 200;}function curl_close($c){$c->closed=true;}
ck((new Client($f['key']))->account()['data']['id']===2);ck(count($nativeCalls)===1&&$nativeCalls[0]->closed);$o=$nativeCalls[0];ck($o->url==='https://www.arnproxy.com/account/api/v1/account');ck($o->options[CURLOPT_SSL_VERIFYPEER]===true&&$o->options[CURLOPT_SSL_VERIFYHOST]===2);ck($o->options[CURLOPT_FOLLOWLOCATION]===false&&$o->options[CURLOPT_PROXY]===''&&$o->options[CURLOPT_PROTOCOLS]===CURLPROTO_HTTPS);
echo json_encode(['status'=>'PASS','checks'=>$checks,'resources'=>count($resources),'webhook_vectors'=>count($f['vectors']),'external_requests'=>0]);
