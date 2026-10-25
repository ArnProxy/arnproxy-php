<?php
declare(strict_types=1);
namespace ArnproxySdk;

/** Thin client 1.1.1. No redirects, retries, automatic polling or logging. PHP 8.1+ with cURL. */
final class ArnproxyError extends \RuntimeException
{
    public function __construct(public readonly string $error_code,public readonly ?int $http_status=null,public readonly ?string $request_id=null,public readonly ?int $retry_after=null,public readonly bool $uncertain=false){parent::__construct($error_code);}
}
class Client
{
    public const BASE='https://www.arnproxy.com/account/api/v1/';
    private const MAX=2097152;
    private string $key;private bool $allowWrites;private bool $allowProviderReads;private float $timeout;
    public function __construct(mixed $apiKey,array $options=[])
    {
        if(!is_string($apiKey)||preg_match('/^(?:[A-Za-z0-9]{32}|arnp_[a-f0-9]{16}_[a-f0-9]{64})$/D',$apiKey)!==1)throw new ArnproxyError('INVALID_KEY_FORMAT');
        if(array_diff(array_keys($options),['allow_writes','allow_provider_reads','timeout']))throw new ArnproxyError('INVALID_CLIENT_OPTIONS');
        $w=$options['allow_writes']??false;$p=$options['allow_provider_reads']??false;$t=$options['timeout']??20;
        if(!is_bool($w)||!is_bool($p)||!is_int($t)&&!is_float($t)||!is_finite((float)$t)||$t<1||$t>60)throw new ArnproxyError('INVALID_CLIENT_OPTIONS');
        $this->key=$apiKey;$this->allowWrites=$w;$this->allowProviderReads=$p;$this->timeout=(float)$t;
    }
    public function __debugInfo():array{return['key'=>'REDACTED'];}
    public function __serialize():array{throw new ArnproxyError('SERIALIZATION_DISABLED');}
    public function __unserialize(array $data):void{throw new ArnproxyError('SERIALIZATION_DISABLED');}
    private static function resources():array
    {static $all=null;if($all===null){try{$raw=@file_get_contents(__DIR__.'/../resources.json');$v=json_decode($raw===false?'':$raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($v['resources']??null))throw new \RuntimeException();$all=$v['resources'];}catch(\Throwable){throw new ArnproxyError('CLIENT_CONTRACT_MISSING');}}return$all;}
    public function account():array{return$this->request('account');}
    public function capabilities():array{return$this->request('capabilities');}
    public function request(string $resource,array $params=[],?string $operation_key=null):array
    {
        $spec=self::resources()[$resource]??null;if(!$spec)throw new ArnproxyError('UNKNOWN_RESOURCE');
        if(($params&&array_is_list($params))||array_filter(array_keys($params),static fn($k)=>!is_string($k))||array_key_exists('resource',$params))throw new ArnproxyError('INVALID_PARAMETERS');
        if($spec['disabled'])throw new ArnproxyError('DISABLED_RESOURCE');$write=$spec['write'];
        if($write&&!$this->allowWrites)throw new ArnproxyError('WRITES_DISABLED');if($spec['provider_read']&&!$this->allowProviderReads)throw new ArnproxyError('PROVIDER_READS_DISABLED');
        $headers=['Authorization: Bearer '.$this->key,'Accept: application/json','User-Agent: ARNPROXY-PHP/1.1.1'];
        if($spec['idempotency']){if($operation_key===null||preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$operation_key)!==1)throw new ArnproxyError('OPERATION_KEY_REQUIRED');$headers[]='Idempotency-Key: '.$operation_key;}elseif($operation_key!==null)throw new ArnproxyError('UNEXPECTED_OPERATION_KEY');
        $path=$spec['path'];if(str_contains($path,'{id}')){$id=$params['id']??null;if(!is_string($id)&&!is_int($id)||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id)!==1||(int)$id>2147483647)throw new ArnproxyError('INVALID_ROUTE_ID');$path=str_replace('{id}',(string)$id,$path);unset($params['id']);}
        $body=null;if($spec['method']==='GET'){$query=[];foreach($params as$k=>$v){if(!is_string($v)&&!is_int($v)&&!is_bool($v))throw new ArnproxyError('INVALID_QUERY_VALUE');$query[$k]=is_bool($v)?($v?'1':'0'):(string)$v;}if($query)$path.='?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);}else{try{$body=json_encode((object)$params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE,32);}catch(\Throwable){throw new ArnproxyError('INVALID_JSON_BODY');}if(strlen($body)>self::MAX)throw new ArnproxyError('REQUEST_TOO_LARGE');$headers[]='Content-Type: application/json';}
        try{[$status,$received,$raw]=$this->send($spec['method'],self::BASE.$path,$headers,$body);}catch(\Throwable$e){throw new ArnproxyError($e instanceof ArnproxyError?$e->error_code:'TRANSPORT_ERROR',uncertain:$write);}
        if($status>=300&&$status<400)throw new ArnproxyError('REDIRECT_BLOCKED',$status,uncertain:$write);
        $retry=$received['retry-after']??'';$retry=is_string($retry)&&preg_match('/^[0-9]{1,5}$/D',$retry)&&((int)$retry)<=86400?(int)$retry:null;
        if(!is_string($raw)||strlen($raw)>self::MAX)throw new ArnproxyError('RESPONSE_TOO_LARGE',$status,uncertain:$write);
        try{$result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ArnproxyError('INVALID_API_RESPONSE',$status,retry_after:$retry,uncertain:$write);}
        $id=is_array($result)&&is_array($result['meta']??null)?($result['meta']['request_id']??null):null;$id=is_string($id)&&preg_match('/^[a-f0-9]{24}$/D',$id)===1?$id:null;
        if(!is_array($result)||!is_bool($result['ok']??null)||$id===null)throw new ArnproxyError('INVALID_API_RESPONSE',$status,retry_after:$retry,uncertain:$write);
        if($status<200||$status>=300||$result['ok']!==true){$code=is_array($result['error']??null)?($result['error']['code']??null):null;$code=is_string($code)&&preg_match('/^[A-Z][A-Z0-9_]{0,79}$/D',$code)===1?$code:'API_HTTP_ERROR';throw new ArnproxyError($code,$status,$id,$retry,$write&&$status>=500);}
        if(!array_key_exists('data',$result))throw new ArnproxyError('INVALID_API_RESPONSE',$status,$id,uncertain:$write);return$result;
    }
    protected function send(string $method,string $url,array $headers,?string $body):array
    {
        if(!function_exists('curl_init'))throw new ArnproxyError('CURL_REQUIRED');$ch=curl_init($url);if($ch===false)throw new ArnproxyError('TRANSPORT_ERROR');$raw='';$received=[];$tooLarge=false;
        try{curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>min(8,(int)ceil($this->timeout)),CURLOPT_TIMEOUT_MS=>(int)ceil($this->timeout*1000),CURLOPT_NOSIGNAL=>true,CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$received):int{$n=strlen($line);if(str_starts_with(strtolower($line),'retry-after:'))$received['retry-after']=trim(substr($line,12));return$n;},CURLOPT_WRITEFUNCTION=>static function($c,string $part)use(&$raw,&$tooLarge):int{if(strlen($raw)+strlen($part)>self::MAX){$tooLarge=true;return 0;}$raw.=$part;return strlen($part);}]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);if($tooLarge)throw new ArnproxyError('RESPONSE_TOO_LARGE');if($ok===false)throw new ArnproxyError(curl_errno($ch)===CURLE_OPERATION_TIMEDOUT?'TRANSPORT_TIMEOUT':'TRANSPORT_ERROR');return[$status,$received,$raw];}finally{curl_close($ch);}
    }
    public static function verifyWebhook(string $rawBody,array $headers,string $signingSecret,?int $now=null):array
    {
        try{if(strlen($rawBody)>4096)throw new \RuntimeException();$values=[];foreach($headers as$k=>$v)if(is_string($k)&&in_array(strtolower($k),['webhook-id','webhook-timestamp','webhook-signature'],true)){if(isset($values[strtolower($k)])||!is_string($v))throw new \RuntimeException();$values[strtolower($k)]=$v;}
            $id=$values['webhook-id']??'';$timestamp=$values['webhook-timestamp']??'';$signature=$values['webhook-signature']??'';
            if(preg_match('/^evt_[a-f0-9]{32}$/D',$id)!==1||preg_match('/^[1-9][0-9]{0,11}$/D',$timestamp)!==1||strlen($signature)>1024||abs(($now??time())-(int)$timestamp)>300||preg_match('/^whsec_[A-Za-z0-9+\/]{43}=$/D',$signingSecret)!==1)throw new \RuntimeException();
            $secret=base64_decode(substr($signingSecret,6),true);if($secret===false||strlen($secret)!==32||base64_encode($secret)!==substr($signingSecret,6))throw new \RuntimeException();$expected=hash_hmac('sha256',$id.'.'.$timestamp.'.'.$rawBody,$secret,true);$valid=false;
            foreach(preg_split('/\s+/',trim($signature))as$token)if(preg_match('/^v1,[A-Za-z0-9+\/]{43}=$/D',$token)===1){$actual=base64_decode(substr($token,3),true);if($actual!==false)$valid=hash_equals($expected,$actual)||$valid;}
            if(!$valid)throw new \RuntimeException();$event=json_decode($rawBody,true,32,JSON_THROW_ON_ERROR);if(!is_array($event)||($event['id']??null)!==$id||($event['api_version']??null)!=='1'||!is_array($event['data']??null)||!is_string($event['type']??null))throw new \RuntimeException();return$event;
        }catch(\Throwable){throw new ArnproxyError('WEBHOOK_VERIFICATION_FAILED');}
    }
}
