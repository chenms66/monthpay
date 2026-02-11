<?php

namespace BaiGe\MonthPay\Support;

use Exception;

class RSAUtil
{
    /**
     * @param $PfxPath
     * @param $PrivateKPASS
     * @return mixed|void
     * 读取私钥
     */
    private static function getPriveKey($PfxPath, $PrivateKPASS)
    {
        try {
            if (!file_exists($PfxPath)) {
                throw new Exception("私钥文件不存在！路径：" . $PfxPath);
            }
            $PKCS12 = file_get_contents($PfxPath);
            $PrivateKey = array();
            if (openssl_pkcs12_read($PKCS12, $PrivateKey, $PrivateKPASS)) {
                return $PrivateKey["pkey"];
            } else {
                throw new Exception("私钥证书读取出错！原因[证书或密码不匹配]，请检查本地证书相关信息。");
            }
        } catch (Exception $ex) {
            $ex->getTrace();
        }
    }

    /**
     * @param $PublicPath
     * @return resource|void
     * 读取公钥
     */
    private static function getPublicKey($PublicPath)
    {
        try {
            if (!file_exists($PublicPath)) {
                throw new Exception("公钥文件不存在！路径：" . $PublicPath);
            }
            $KeyFile = file_get_contents($PublicPath);
            $PublicKey = openssl_get_publickey($KeyFile);
            if (empty($PublicKey)) {
                throw new Exception("公钥不可用！路径：" . $PublicPath);
            }
            return $PublicKey;
        } catch (Exception $ex) {
            $ex->getTraceAsString();
        }
    }

    /**
     * @param $Data
     * @param $PublicPath
     * @return string|void
     * 公钥加密
     */
    public static function encryptByCERFile($Data, $PublicPath)
    {
        try {
            if (!function_exists('bin2hex')) {
                throw new Exception("bin2hex PHP5.4及以上版本支持此函数，也可自行实现！");
            }
            $KeyObj = self::getPublicKey($PublicPath);
            $BASE64EN_DATA = base64_encode($Data);
            $EncryptStr = "";
            $blockSize = self::get_Key_Size($KeyObj, false);
            if ($blockSize <= 0) {
                throw new Exception("BlockSize is 0");
            } else {
                $blockSize = $blockSize / 8 - 11;
            }
            $totalLen = strlen($BASE64EN_DATA);
            $EncryptSubStarLen = 0;
            $EncryptTempData = "";
            while ($EncryptSubStarLen < $totalLen) {
                openssl_public_encrypt(substr($BASE64EN_DATA, $EncryptSubStarLen, $blockSize), $EncryptTempData, $KeyObj);
                $EncryptStr .= bin2hex($EncryptTempData);
                $EncryptSubStarLen += $blockSize;
            }
            return $EncryptStr;
        } catch (Exception $exc) {
            echo $exc->getTraceAsString();
        }
    }

    /**
     * @param $Data
     * @param $PfxPath
     * @param $PrivateKPASS
     * @return string|void
     * 私钥加密
     */
    public static function encryptByPFXFile($Data, $PfxPath, $PrivateKPASS)
    {
        try {
            if (!function_exists('bin2hex')) {
                throw new Exception("bin2hex PHP5.4及以上版本支持此函数，也可自行实现！");
            }
            $KeyObj = self::getPriveKey($PfxPath, $PrivateKPASS);
            $BASE64EN_DATA = base64_encode($Data);
            $EncryptStr = "";
            $blockSize = self::get_Key_Size($KeyObj);
            if ($blockSize <= 0) {
                throw new Exception("BlockSize is 0");
            } else {
                $blockSize = $blockSize / 8 - 11;//分段
            }
            $totalLen = strlen($BASE64EN_DATA);
            $EncryptSubStarLen = 0;
            $EncryptTempData = "";
            while ($EncryptSubStarLen < $totalLen) {
                openssl_private_encrypt(substr($BASE64EN_DATA, $EncryptSubStarLen, $blockSize), $EncryptTempData, $KeyObj);
                $EncryptStr .= bin2hex($EncryptTempData);
                $EncryptSubStarLen += $blockSize;
            }
            return $EncryptStr;
        } catch (Exception $exc) {
            echo $exc->getTraceAsString();
        }
    }

    /**
     * @param $Data
     * @param $PfxPath
     * @param $PrivateKPASS
     * @return false|string|void
     * 私钥解密
     */
    public static function decryptByPFXFile($Data, $PfxPath, $PrivateKPASS)
    {
        try {
            if (!function_exists('hex2bin')) {
                throw new Exception("hex2bin PHP5.4及以上版本支持此函数，也可自行实现！");
            }
            $KeyObj = self::getPriveKey($PfxPath, $PrivateKPASS);
            $blockSize = self::get_Key_Size($KeyObj);
            if ($blockSize <= 0) {
                throw new Exception("BlockSize is 0");
            } else {
                $blockSize = $blockSize / 4;
            }
            $DecryptRsult = "";
            $totalLen = strlen($Data);
            $EncryptSubStarLen = 0;
            $DecryptTempData = "";
            while ($EncryptSubStarLen < $totalLen) {
                openssl_private_decrypt(hex2bin(substr($Data, $EncryptSubStarLen, $blockSize)), $DecryptTempData, $KeyObj);
                $DecryptRsult .= $DecryptTempData;
                $EncryptSubStarLen += $blockSize;
            }
            return base64_decode($DecryptRsult);
        } catch (Exception $exc) {
            echo $exc->getTraceAsString();
        }
    }

    /**
     * @param $Data
     * @param $PublicPath
     * @return false|string|void
     * 公钥解密
     */
    public static function decryptByCERFile($Data, $PublicPath)
    {
        try {
            if (!function_exists('hex2bin')) {
                throw new Exception("hex2bin PHP5.4及以上版本支持此函数，也可自行实现！");
            }
            $KeyObj = self::getPublicKey($PublicPath);
            $DecryptRsult = "";
            $blockSize = self::get_Key_Size($KeyObj, false);
            if ($blockSize <= 0) {
                throw new Exception("BlockSize is 0");
            } else {
                $blockSize = $blockSize / 4;
            }
            $totalLen = strlen($Data);
            $EncryptSubStarLen = 0;
            $DecryptTempData = "";
            while ($EncryptSubStarLen < $totalLen) {
                openssl_public_decrypt(hex2bin(substr($Data, $EncryptSubStarLen, $blockSize)), $DecryptTempData, $KeyObj);
                $DecryptRsult .= $DecryptTempData;
                $EncryptSubStarLen += $blockSize;
            }
            return base64_decode($DecryptRsult);
        } catch (Exception $exc) {
            echo $exc->getTraceAsString();
        }
    }

    /**
     * @param $Key_String
     * @param $Key_Type
     * @return int|mixed
     * 获取证书长度
     */
    private static function get_Key_Size($Key_String, $Key_Type = true)
    {
        $Key_Temp = array();
        try {
            if ($Key_Type) {//私钥
                $Key_Temp = openssl_pkey_get_details(openssl_pkey_get_private($Key_String));
            } else if (openssl_pkey_get_public($Key_String)) {//公钥
                $Key_Temp = openssl_pkey_get_details(openssl_pkey_get_public($Key_String));
            } else {
                throw new Exception("Is not a key");
            }
            if (array_key_exists("bits", $Key_Temp)) {
                return $Key_Temp["bits"];
            } else {
                return 0;
            }
        } catch (Exception $ex) {
            $ex->getTrace();
            return 0;
        }
    }
}