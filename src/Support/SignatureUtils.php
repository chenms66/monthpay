<?php
namespace BaiGe\MonthPay\Support;

 use Exception;

 class SignatureUtils{

     /**
      * @param $Data 原数据
      * @param $PfxPath 私钥路径
      * @param $Pwd 私钥密码
      * @return string
      */
     public static function Sign($Data,$PfxPath,$Pwd)
    {
        
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
        if(!file_exists($PfxPath)) {
           throw new Exception("私钥文件不存在！");
        } 
        
        $pkcs12 = file_get_contents($PfxPath);
        $PfxPathStr=array();
        if (openssl_pkcs12_read($pkcs12, $PfxPathStr, $Pwd)) {
            $PrivateKey = $PfxPathStr['pkey'];
            $BinarySignature=NULL;
            if (openssl_sign($Data, $BinarySignature, $PrivateKey, OPENSSL_ALGO_SHA1)) {
                return bin2hex($BinarySignature);
            } else {
                throw new Exception("加签异常！");
            }
        } else {
            throw new Exception("私钥读取异常【密码和证书不匹配】！");
        }
    }
 
    /**
     * 验证签名自己生成的是否正确
     *
     * @param string $Data 签名的原文
     * @param string $CerPath  公钥路径
     * @param string $SignaTure 签名
     * @return bool
     */
    public static function VerifySign($Data,$CerPath,$SignaTure)
    {
        if (!function_exists( 'hex2bin')) {
            function hex2bin( $str ) {
                $sbin = "";
                $len = strlen( $str );
                for ( $i = 0; $i < $len; $i += 2 ) {
                    $sbin .= pack( "H*", substr( $str, $i, 2 ) );
                }
                return $sbin;
            }
        }
        if(!file_exists($CerPath)) {
            throw new Exception("公钥文件不存在！路径：".$CerPath);
        } 
        $PubKey = file_get_contents($CerPath);
        $Certs = openssl_get_publickey($PubKey);
        $ok = openssl_verify($Data,hex2bin($SignaTure), $Certs);
        if ($ok == 1) {
            return true;
        }
        return false;
    }
 }