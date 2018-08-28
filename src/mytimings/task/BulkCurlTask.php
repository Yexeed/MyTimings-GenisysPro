<?php
/**
 *  __     __                         _
 *  \ \   / /                        | |
 *   \ \_/ /___ __  __ ___   ___   __| |
 *    \   // _ \\ \/ // _ \ / _ \ / _` |
 *     | ||  __/ >  <|  __/|  __/| (_| |
 *     |_| \___|/_/\_\\___| \___| \__,_|
 *
 *           users are losers
 *               {2018}.
 */

namespace mytimings\task;


use mytimings\ex\InternetException;
use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Utils;

class BulkCurlTask extends AsyncTask{
    private $operations;
    /**
     * BulkCurlTask constructor.
     *
     * $operations accepts an array of arrays. Each member array must contain a string mapped to "page", and optionally,
     * "timeout", "extraHeaders" and "extraOpts". Documentation of these options are same as those in
     * {@link Utils::simpleCurl}.
     *
     * @param array $operations
     */
    public function __construct(array $operations){
        $this->operations = serialize($operations);
    }
    public function onRun(){
        $operations = unserialize($this->operations);
        $results = [];
        foreach($operations as $op){
            try{
                $results[] = self::simpleCurl($op["page"], $op["timeout"] ?? 10, $op["extraHeaders"] ?? [], $op["extraOpts"] ?? []);
            }catch(InternetException $e){
                $results[] = $e;
            }
        }
        $this->setResult($results);
    }


    public static function simpleCurl(string $page, $timeout = 10, array $extraHeaders = [], array $extraOpts = [], callable $onSuccess = null){
        if(!Utils::$online){
            throw new InternetException("Cannot execute web request while offline");
        }
        $ch = curl_init($page);
        curl_setopt_array($ch, $extraOpts + [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FORBID_REUSE => 1,
                CURLOPT_FRESH_CONNECT => 1,
                CURLOPT_AUTOREFERER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
                CURLOPT_HTTPHEADER => array_merge(["User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:12.0) Gecko/20100101 Firefox/12.0 " . "PocketMine-MP"], $extraHeaders),
                CURLOPT_HEADER => true
            ]);
        try{
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            if($error !== ""){
                throw new InternetException($error);
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            $headers = [];
            foreach(explode("\r\n\r\n", $rawHeaders) as $rawHeaderGroup){
                $headerGroup = [];
                foreach(explode("\r\n", $rawHeaderGroup) as $line){
                    $nameValue = explode(":", $line, 2);
                    if(isset($nameValue[1])){
                        $headerGroup[trim(strtolower($nameValue[0]))] = trim($nameValue[1]);
                    }
                }
                $headers[] = $headerGroup;
            }
            if($onSuccess !== null){
                $onSuccess($ch);
            }
            return [$body, $headers, $httpCode];
        }finally{
            curl_close($ch);
        }
    }

}