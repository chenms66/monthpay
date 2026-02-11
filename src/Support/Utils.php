<?php

namespace BaiGe\MonthPay\Support;

use Exception;

class Utils
{
    public static function yuanToCent($amount)
    {
        return (int)bcmul($amount, 100);
    }

    /**
     * 排序并且转成字符串
     * @param $params
     * @return string
     */
    public static function _getParamsString($params, $key)
    {
        ksort($params);
        $return = '';
        foreach ($params as $k => $param) {
            $return .= '&' . $k . '=' . $param;
        }
        $return .= '&key=' . $key;
        return ltrim($return, '&');
    }

    /**
     * 获取客户端IP地址
     * @param integer $type 返回类型 0 返回IP地址 1 返回IPV4地址数字
     * @param boolean $adv 是否进行高级模式获取（有可能被伪装）
     * @return mixed
     */
    public static function ip(int $type = 0, bool $adv = true)
    {
        $type = $type ? 1 : 0;
        static $ip = null;
        if (null !== $ip) {
            return $ip[$type];
        }
        if ($adv) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $pos = array_search('unknown', $arr);
                if (false !== $pos) {
                    unset($arr[$pos]);
                }
                $ip = trim(current($arr));
            } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? [$ip, $long] : ['0.0.0.0', 0];
        return $ip[$type];
    }

    public static function dxmEncrypt($str,$key) {
        $str = mb_convert_encoding($str, "GBK", "UTF-8");
        $data = openssl_encrypt($str,"AES-128-ECB",md5($key,true), OPENSSL_RAW_DATA);
        return base64_encode($data);
    }
    public static function dxmDecrypt($str,$key) {
        // 1. Base64解码
        $encryptedData = base64_decode($str);

        // 2. AES-128-ECB解密
        $decryptedData = openssl_decrypt(
            $encryptedData,
            "AES-128-ECB",
            md5($key, true),
            OPENSSL_RAW_DATA
        );
        return mb_convert_encoding($decryptedData, "UTF-8", "GBK");
    }

    /**
     * 发送 GET 请求
     *
     * @param string $url 请求 URL
     * @param array $params GET 参数 ['key'=>'value']
     * @param array $headers 请求头 ['Authorization: Bearer xxx']
     * @param int $timeout 超时时间（秒）
     * @return array|string 返回 JSON 解析后的数组，如果不是 JSON 返回原始字符串
     */
    public static function curl_get(string $url, array $params = [], array $headers = [], int $timeout = 10) {
        // 拼接参数
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        // 初始化 cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // 设置请求头
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // HTTPS 设置（测试环境可忽略证书）
        if (stripos($url, 'https://') === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        // 执行请求
        $response = curl_exec($ch);

        // 错误处理
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL GET Error: {$err}");
        }

        curl_close($ch);

        return  $response;
    }

    /**
     * http请求
     * @param $url
     * @param $data
     * @return bool|string
     */
    public static function httpCurl($url, $data)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        curl_setopt($curl, CURLOPT_TIMEOUT, 3600 * 5);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * @return false|int
     * 生成时间戳
     */
    public static function getTransid(){
        return strtotime(date('Y-m-d H:i:s',time()));
    }

    /**
     * @return int
     * 生成四位随机数
     */
    public static function getRand4(){
        return rand(1000,9999);
    }

    /**
     * @param $datef
     * @return false|string
     * 生成四位随机数
     */
    public static function getTime($datef="Y-m-d H:i:s"){
        return date($datef,time());
    }

    /**
     * 排序输出k=v&k1=v1.....格式
     * @param  $DArray
     * @return
     */
    public static function SortAndOutString($DArray)
    {
        $TempData = array();
        foreach ($DArray As $Key => $Value){
            if(!self::isBlank($Value)){
                $TempData[$Key] = $Value;
            }
        }
        ksort($TempData);//排序
        return http_build_query($TempData);
    }

    /**
     * 判断是否空值
     * @param  $Strings
     * @return boolean
     */
    public static function isBlank($Strings){
        $Strings = trim($Strings);
        if((empty($Strings)||($Strings == null))&&(strlen($Strings) <= 0)){
            return true;
        }else{
            return FALSE;
        }
    }

    /**
     * @param $data
     * @param $key
     * @return mixed
     * 删除数组元素
     */
    public static function array_remove($data, $key){
        if(!array_key_exists($key, $data)){
            return $data;
        }
        $keys = array_keys($data);
        $index = array_search($key, $keys);
        if($index !== FALSE){
            array_splice($data, $index, 1);
        }
        return $data;
    }

    /**
     * @param $Strings
     * @return string
     * @throws Exception
     * 获取信封中的key值
     */
    public static function getAesKey($Strings){
        $KeyArray = explode("|",$Strings);
        if(count($KeyArray) == 2){
            $KeyArray[1]=trim($KeyArray[1]);
            if(!empty($KeyArray[1])){
                return $KeyArray[1];
            }else{
                throw new Exception("Key is Null!");
            }
        }else{
            throw new Exception("Data format is incorrect!");
        }
    }
}
