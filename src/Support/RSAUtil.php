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

    /**
     * 苏宁易付宝敏感字段加密
     * 加密规范：
     * 1. 使用易付宝公钥进行 RSA 加密
     * 2. 填充方式：PKCS1Padding
     * 3. 编码格式：十六进制（大写）
     * 4. 直接加密原始数据，不需要先 base64 编码
     * 
     * @param string $data 要加密的敏感数据
     * @param string $publicKeyPath 公钥文件路径
     * @return string 十六进制编码的加密结果（大写）
     * @throws Exception
     */
    public static function encryptForSuning($data, $publicKeyPath)
    {
        try {
            if (!file_exists($publicKeyPath)) {
                throw new Exception("公钥文件不存在！路径：" . $publicKeyPath);
            }
            
            // 读取公钥文件内容
            $publicKeyContent = file_get_contents($publicKeyPath);
            
            // 如果是纯密钥内容（没有 PEM 头部），添加 PEM 格式标识
            if (strpos($publicKeyContent, '-----BEGIN PUBLIC KEY-----') === false) {
                // 移除所有换行符和空格
                $cleanKey = str_replace(["\r", "\n", " "], '', $publicKeyContent);
                // 按 64 字符分行
                $lines = str_split($cleanKey, 64);
                // 组装成 PEM 格式
                $publicKeyContent = "-----BEGIN PUBLIC KEY-----\n" . 
                                    implode("\n", $lines) . 
                                    "\n-----END PUBLIC KEY-----";
            }
            
            // 获取公钥资源
            $publicKey = openssl_get_publickey($publicKeyContent);
            if (!$publicKey) {
                throw new Exception("公钥加载失败！");
            }
            
            // RSA 加密（使用 PKCS1Padding）
            $encrypted = '';
            $result = openssl_public_encrypt($data, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING);
            
            if (!$result) {
                throw new Exception("RSA 加密失败：" . openssl_error_string());
            }
            
            // 转换为十六进制编码（大写）
            return strtoupper(bin2hex($encrypted));
            
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * 苏宁易付宝请求签名（私钥签名）
     * 签名规范：
     * 1. 对明文字符串进行 MD5 摘要
     * 2. 使用商户私钥对 MD5 摘要进行 RSA 签名
     * 3. 返回 Base64 编码的签名
     * 
     * @param string $data 要签名的数据（通常是 MD5 摘要后的字符串）
     * @param string $privateKeyPath 私钥文件路径（PEM 格式）
     * @param string $privateKeyPass 私钥密码（如果有）
     * @return string Base64 编码的签名结果
     * @throws Exception
     */
    public static function signForSuning($data, $privateKeyPath, $privateKeyPass = null)
    {
        try {
            if (!file_exists($privateKeyPath)) {
                throw new Exception("私钥文件不存在！路径：" . $privateKeyPath);
            }
            
            // 读取私钥文件内容
            $privateKeyContent = file_get_contents($privateKeyPath);
            
            // 如果是 PFX/PKCS12 格式，使用密码读取
            if (pathinfo($privateKeyPath, PATHINFO_EXTENSION) === 'pfx' || 
                pathinfo($privateKeyPath, PATHINFO_EXTENSION) === 'p12') {
                $pkcs12 = file_get_contents($privateKeyPath);
                $privateKeyArray = [];
                if (!openssl_pkcs12_read($pkcs12, $privateKeyArray, $privateKeyPass)) {
                    throw new Exception("私钥证书读取出错！原因 [证书或密码不匹配]");
                }
                $privateKeyContent = $privateKeyArray['pkey'];
            }
            
            // 如果是纯密钥内容（没有 PEM 头部），添加 PEM 格式标识
            if (strpos($privateKeyContent, '-----BEGIN') === false) {
                $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                                     chunk_split($privateKeyContent, 64, "\n") . 
                                     "-----END PRIVATE KEY-----";
            }
            
            // 加载私钥
            $privateKey = openssl_get_privatekey($privateKeyContent);
            if (!$privateKey) {
                throw new Exception("商户私钥加载失败！");
            }
            
            // RSA 签名（使用 SHA1 算法）
            // 根据苏宁文档示例，签名长度为 171 字符，说明使用的是 SHA1 而非 SHA256
            $signature = '';
            $result = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA1);
            
            if (!$result) {
                throw new Exception("RSA 签名失败：" . openssl_error_string());
            }
            
            // 返回 Base64 编码的签名（URL 安全格式）
            // 将 + 替换为 -，/ 替换为 _，去掉 =
            $base64 = base64_encode($signature);
            $urlSafeBase64 = rtrim(strtr($base64, '+/', '-_'), '=');
            
            return $urlSafeBase64;
            
        } catch (Exception $e) {
            throw $e;
        }
    }
}