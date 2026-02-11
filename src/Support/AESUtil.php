<?php
namespace BaiGe\MonthPay\Support;

use Exception;
class AESUtil{
    /**
     * 
     * @param type $data    待加密的数据
     * @param type $key     密钥
     * @param type $iv      密钥
     * @return type
     * @throws Exception
     */
    public static function AesEncrypt($data,$key){
        if (!function_exists( 'bin2hex')) {
            function hex2bin( $str ) {
                $sbin = "";
                $len = strlen( $str );
                for ( $i = 0; $i < $len; $i += 2 ) {
                    $sbin .= pack( "H*", substr( $str, $i, 2 ) );
                }
                return $sbin;
            }
        }
        if(!(strlen($key) == 16)){
            throw new Exception("AES密码长度固定为16位！当前KEY长度为：".  strlen($key));
        }
        $iv=$key;//偏移量与key相同
        //OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING
        $encrypted = openssl_encrypt($data, "AES-128-CBC", $key,OPENSSL_RAW_DATA,$iv);
        //$encrypted=mcrypt_encrypt(MCRYPT_RIJNDAEL_128,$key,$data,MCRYPT_MODE_CBC,$iv);
        $data=bin2hex($encrypted);
        return $data;
    }

    /**
     * @param $sData
     * @param $sKey
     * @return false|string
     * @throws Exception
     * 解密
     */
    public static function AesDecrypt($sData,$sKey){        
        if(!(strlen($sKey) == 16)){
            throw new Exception("AES密码长度固定为16位！当前KEY长度为：".  strlen($sKey));
        }
        $sIv=$sKey;//偏移量与key相同
        $sData=hex2bin($sData);
        $retrun = openssl_decrypt($sData, "AES-128-CBC",$sKey,OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,$sIv);
        return $retrun;
    }
}