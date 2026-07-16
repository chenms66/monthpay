<?php

namespace BaiGe\MonthPay\Support;

use BaiGe\MonthPay\sm\sm\RtSm2;
use BaiGe\MonthPay\sm\sm\RtSm4;
use BaiGe\MonthPay\sm\ecc\RtEccFactory;
use Exception;

/**
 * 合利宝 SM2/SM4 加解密工具类
 * 基于合利宝官方 demo 的 sm 库封装
 */
class HelipaySM
{
    /**
     * 从 CER 证书提取 SM2 公钥（hex 格式，128位）
     */
    public static function getPublicKeyFromCER(string $cerPath): string
    {
        if (!file_exists($cerPath)) {
            throw new Exception("证书文件不存在：$cerPath");
        }

        $raw = file_get_contents($cerPath);

        // 判断是 PEM 还是 DER 格式
        if (strpos($raw, '-----BEGIN') !== false) {
            // 标准 PEM 格式
            $pem = $raw;
        } elseif (ctype_print(str_replace(["\r\n", "\r", "\n", ' ', "\t"], '', $raw))) {
            // 纯文本 base64（无 PEM 头），补上 PEM 头
            $clean = str_replace(["\r\n", "\r", "\n", ' ', "\t"], '', $raw);
            $pem = "-----BEGIN CERTIFICATE-----\n" .
                chunk_split($clean, 64, "\n") .
                "-----END CERTIFICATE-----\n";
        } else {
            // DER 二进制格式，转为 PEM
            $pem = "-----BEGIN CERTIFICATE-----\n" .
                chunk_split(base64_encode($raw), 64, "\n") .
                "-----END CERTIFICATE-----\n";
        }

        // 尝试 OpenSSL 解析
        $cert = @openssl_x509_read($pem);
        if ($cert !== false) {
            $keyDetail = @openssl_pkey_get_details($cert);
            if ($keyDetail !== false && isset($keyDetail['ec']['x']) && isset($keyDetail['ec']['y'])) {
                return $keyDetail['ec']['x'] . $keyDetail['ec']['y'];
            }
        }

        // OpenSSL 不支持 SM2，使用纯 PHP DER 解析
        // $raw 可能是 base64 文本，需要解码为二进制
        $der = $raw;
        if (!ctype_print(str_replace(["\r\n", "\r", "\n", ' ', "\t"], '', $raw))) {
            // 已经是二进制
        } else {
            // base64 文本，解码
            $der = base64_decode(str_replace(["\r\n", "\r", "\n", ' ', "\t"], '', $raw));
        }
        return self::extractPubKeyFromDER($der);
    }

    /**
     * 从 DER 格式证书提取公钥（纯 PHP 实现，绕过 OpenSSL SM2 限制）
     */
    private static function extractPubKeyFromDER(string $der): string
    {
        // BIT STRING (0x03): 0x00(unused bits) + 0x04(EC point) + 64 bytes(X+Y)
        // 长度 = 1 + 1 + 64 = 66 = 0x42
        $pos = 0;
        while ($pos < strlen($der) - 66) {
            if (ord($der[$pos]) === 0x03) {
                $len = ord($der[$pos + 1]);
                if ($len === 0x42) {
                    // pos+2 = unused bits (0x00), pos+3 = 0x04 (uncompressed point)
                    if (ord($der[$pos + 2]) === 0x00 && ord($der[$pos + 3]) === 0x04) {
                        $pubKey = substr($der, $pos + 4, 64);
                        if (strlen($pubKey) === 64) {
                            return bin2hex($pubKey);
                        }
                    }
                }
                // 长格式长度: 0x81 0x42
                if ($len === 0x81 && ord($der[$pos + 2]) === 0x42) {
                    if (ord($der[$pos + 3]) === 0x00 && ord($der[$pos + 4]) === 0x04) {
                        $pubKey = substr($der, $pos + 5, 64);
                        if (strlen($pubKey) === 64) {
                            return bin2hex($pubKey);
                        }
                    }
                }
            }
            $pos++;
        }

        throw new Exception("无法从 DER 证书中提取公钥");
    }

    /**
     * 从 PFX 文件提取 SM2 私钥（hex 格式，64位）
     * 优先使用 OpenSSL，失败时使用纯 PHP ASN.1/DER 解析
     */
    public static function getPrivateKeyFromPFX(string $pfxPath, string $password): string
    {
        if (!file_exists($pfxPath)) {
            throw new Exception("私钥文件不存在：$pfxPath");
        }

        $certStore = file_get_contents($pfxPath);

        // 尝试 OpenSSL
        if (openssl_pkcs12_read($certStore, $certInfo, $password)) {
            $privateKey = openssl_pkey_get_private($certInfo['pkey']);
            if ($privateKey !== false) {
                $keyDetail = openssl_pkey_get_details($privateKey);
                if ($keyDetail !== false && isset($keyDetail['ec']['d'])) {
                    return $keyDetail['ec']['d'];
                }
            }
        }

        // OpenSSL 不支持 SM2，使用纯 PHP 解析
        return self::extractPrivKeyFromPFX($certStore, $password);
    }

    /**
     * 纯 PHP ASN.1/DER 解析 PFX 提取私钥
     */
    private static function extractPrivKeyFromPFX(string $pfxDer, string $password): string
    {
        $offset = 0;
        $pfx = self::parseDer($pfxDer, $offset);
        if (!$pfx || $pfx['tag'] !== 0x30) return null;

        $pfxItems = self::parseSeq($pfx['value']);
        if (count($pfxItems) < 2) return null;

        $authSafe = $pfxItems[1];
        if ($authSafe['tag'] !== 0x30) return null;

        $authSafeItems = self::parseSeq($authSafe['value']);
        if (count($authSafeItems) < 2) return null;

        $contentOid = self::parseOid($authSafeItems[0]['value']);
        $contentWrapper = $authSafeItems[1];
        if ($contentWrapper['tag'] !== 0xA0) return null;

        // data (1.2.840.113549.1.7.1) 包含 pkcs8ShroudedKeyBag
        if ($contentOid === '1.2.840.113549.1.7.1') {
            $octetOffset = 0;
            $octetString = self::parseDer($contentWrapper['value'], $octetOffset);
            if (!$octetString || $octetString['tag'] !== 0x04) return null;

            $safeContentsOffset = 0;
            $safeContents = self::parseDer($octetString['value'], $safeContentsOffset);
            if (!$safeContents || $safeContents['tag'] !== 0x30) return null;

            $contentInfoList = self::parseSeq($safeContents['value']);

            foreach ($contentInfoList as $contentInfo) {
                if ($contentInfo['tag'] !== 0x30) continue;

                $ciItems = self::parseSeq($contentInfo['value']);
                if (count($ciItems) < 2) continue;

                $ciOid = self::parseOid($ciItems[0]['value']);

                if ($ciOid === '1.2.840.113549.1.7.1') {
                    $ciContent = $ciItems[1];
                    if ($ciContent['tag'] !== 0xA0) continue;

                    $dataOffset = 0;
                    $dataOctetString = self::parseDer($ciContent['value'], $dataOffset);
                    if (!$dataOctetString || $dataOctetString['tag'] !== 0x04) continue;

                    $dataSafeContentsOffset = 0;
                    $dataSafeContents = self::parseDer($dataOctetString['value'], $dataSafeContentsOffset);
                    if (!$dataSafeContents || $dataSafeContents['tag'] !== 0x30) continue;

                    $dataBags = self::parseSeq($dataSafeContents['value']);

                    foreach ($dataBags as $bag) {
                        if ($bag['tag'] !== 0x30) continue;

                        $bagItems = self::parseSeq($bag['value']);
                        if (count($bagItems) < 2) continue;

                        $bagOid = self::parseOid($bagItems[0]['value']);

                        // pkcs8ShroudedKeyBag: 1.2.840.113549.1.12.10.1.2
                        if ($bagOid === '1.2.840.113549.1.12.10.1.2') {
                            $bagValue = $bagItems[1];
                            if ($bagValue['tag'] !== 0xA0) continue;

                            $encPrivKeyOffset = 0;
                            $encPrivKey = self::parseDer($bagValue['value'], $encPrivKeyOffset);
                            if (!$encPrivKey || $encPrivKey['tag'] !== 0x30) continue;

                            $encPrivKeyItems = self::parseSeq($encPrivKey['value']);
                            if (count($encPrivKeyItems) < 2) continue;

                            $encAlgo = $encPrivKeyItems[0];
                            if ($encAlgo['tag'] !== 0x30) continue;

                            $encAlgoItems = self::parseSeq($encAlgo['value']);
                            if (count($encAlgoItems) < 1) continue;

                            $encAlgoOid = self::parseOid($encAlgoItems[0]['value']);

                            // PBES2: 1.2.840.113549.1.5.13
                            if ($encAlgoOid === '1.2.840.113549.1.5.13') {
                                $encryptedData = $encPrivKeyItems[1];
                                if ($encryptedData['tag'] !== 0x04) continue;

                                if (count($encAlgoItems) < 2) continue;
                                $pbes2Params = $encAlgoItems[1];
                                if ($pbes2Params['tag'] !== 0x30) continue;

                                $pbes2Items = self::parseSeq($pbes2Params['value']);
                                if (count($pbes2Items) < 2) continue;

                                $kdf = $pbes2Items[0];
                                if ($kdf['tag'] !== 0x30) continue;

                                $kdfItems = self::parseSeq($kdf['value']);
                                if (count($kdfItems) < 2) continue;

                                $kdfOid = self::parseOid($kdfItems[0]['value']);
                                if ($kdfOid !== '1.2.840.113549.1.5.12') continue;

                                $pbkdf2Params = $kdfItems[1];
                                if ($pbkdf2Params['tag'] !== 0x30) continue;

                                $pbkdf2Items = self::parseSeq($pbkdf2Params['value']);
                                if (count($pbkdf2Items) < 2) continue;

                                $salt = $pbkdf2Items[0];
                                if ($salt['tag'] !== 0x04) continue;

                                $iterCount = self::parseInteger($pbkdf2Items[1]['value']);

                                $encScheme = $pbes2Items[1];
                                if ($encScheme['tag'] !== 0x30) continue;

                                $encSchemeItems = self::parseSeq($encScheme['value']);
                                if (count($encSchemeItems) < 2) continue;

                                $encSchemeOid = self::parseOid($encSchemeItems[0]['value']);

                                $cipher = null;
                                $keyLen = 32;
                                if ($encSchemeOid === '2.16.840.1.101.3.4.1.42') {
                                    $cipher = 'aes-256-cbc';
                                    $keyLen = 32;
                                } elseif ($encSchemeOid === '1.2.840.113549.3.7') {
                                    $cipher = 'des-ede3-cbc';
                                    $keyLen = 24;
                                }

                                if (!$cipher) continue;

                                $ivData = $encSchemeItems[1];
                                if ($ivData['tag'] !== 0x04) continue;
                                $iv = $ivData['value'];

                                // 确定哈希算法（默认 sha256）
                                $hashAlgo = 'sha256';
                                if (count($pbkdf2Items) >= 4 && $pbkdf2Items[3]['tag'] === 0x30) {
                                    $prfItems = self::parseSeq($pbkdf2Items[3]['value']);
                                    if (count($prfItems) >= 1 && $prfItems[0]['tag'] === 0x06) {
                                        $prfOid = self::parseOid($prfItems[0]['value']);
                                        $hashMap = [
                                            '1.2.840.113549.2.7' => 'sha1',
                                            '1.2.840.113549.2.8' => 'sha224',
                                            '1.2.840.113549.2.9' => 'sha256',
                                            '1.2.840.113549.2.10' => 'sha384',
                                            '1.2.840.113549.2.11' => 'sha512',
                                        ];
                                        $hashAlgo = $hashMap[$prfOid] ?? 'sha256';
                                    }
                                }

                                $derivedKey = hash_pbkdf2($hashAlgo, $password, $salt['value'], $iterCount, $keyLen, true);
                                $decrypted = openssl_decrypt($encryptedData['value'], $cipher, $derivedKey, OPENSSL_RAW_DATA, $iv);
                                if ($decrypted === false) continue;

                                // 解析 PKCS#8 → ECPrivateKey
                                $pkcs8Offset = 0;
                                $pkcs8 = self::parseDer($decrypted, $pkcs8Offset);
                                if (!$pkcs8 || $pkcs8['tag'] !== 0x30) continue;

                                $pkcs8Items = self::parseSeq($pkcs8['value']);
                                if (count($pkcs8Items) < 3) continue;

                                $privKeyOctet = $pkcs8Items[2];
                                if ($privKeyOctet['tag'] !== 0x04) continue;

                                $ecKeyOffset = 0;
                                $ecKey = self::parseDer($privKeyOctet['value'], $ecKeyOffset);
                                if (!$ecKey || $ecKey['tag'] !== 0x30) continue;

                                $ecKeyItems = self::parseSeq($ecKey['value']);
                                if (count($ecKeyItems) < 2) continue;

                                $privKeyBytes = $ecKeyItems[1];
                                if ($privKeyBytes['tag'] !== 0x04) continue;

                                return bin2hex($privKeyBytes['value']);
                            }
                        }
                    }
                }

                // encryptedData (1.2.840.113549.1.7.6)
                if ($ciOid === '1.2.840.113549.1.7.6') {
                    $encDataContent = $ciItems[1];
                    if ($encDataContent['tag'] !== 0xA0) continue;

                    $encDataOffset = 0;
                    $encDataOctet = self::parseDer($encDataContent['value'], $encDataOffset);
                    if (!$encDataOctet || $encDataOctet['tag'] !== 0x04) continue;

                    $encDataInnerOffset = 0;
                    $encDataInner = self::parseDer($encDataOctet['value'], $encDataInnerOffset);
                    if (!$encDataInner || $encDataInner['tag'] !== 0x30) continue;

                    $encDataItems = self::parseSeq($encDataInner['value']);
                    if (count($encDataItems) < 2) continue;

                    $encDataAlgo = $encDataItems[0];
                    if ($encDataAlgo['tag'] !== 0x30) continue;

                    $encDataAlgoItems = self::parseSeq($encDataAlgo['value']);
                    if (count($encDataAlgoItems) < 1) continue;

                    $encDataAlgoOid = self::parseOid($encDataAlgoItems[0]['value']);

                    if ($encDataAlgoOid === '1.2.840.113549.1.5.13') {
                        $encDataValue = $encDataItems[1];
                        if ($encDataValue['tag'] !== 0x04) continue;

                        if (count($encDataAlgoItems) < 2) continue;
                        $pbes2Params = $encDataAlgoItems[1];
                        if ($pbes2Params['tag'] !== 0x30) continue;

                        $pbes2Items = self::parseSeq($pbes2Params['value']);
                        if (count($pbes2Items) < 2) continue;

                        $kdf = $pbes2Items[0];
                        if ($kdf['tag'] !== 0x30) continue;

                        $kdfItems = self::parseSeq($kdf['value']);
                        if (count($kdfItems) < 2) continue;

                        $kdfOid = self::parseOid($kdfItems[0]['value']);
                        if ($kdfOid !== '1.2.840.113549.1.5.12') continue;

                        $pbkdf2Params = $kdfItems[1];
                        if ($pbkdf2Params['tag'] !== 0x30) continue;

                        $pbkdf2Items = self::parseSeq($pbkdf2Params['value']);
                        if (count($pbkdf2Items) < 2) continue;

                        $salt = $pbkdf2Items[0];
                        if ($salt['tag'] !== 0x04) continue;

                        $iterCount = self::parseInteger($pbkdf2Items[1]['value']);

                        $encScheme = $pbes2Items[1];
                        if ($encScheme['tag'] !== 0x30) continue;

                        $encSchemeItems = self::parseSeq($encScheme['value']);
                        if (count($encSchemeItems) < 2) continue;

                        $encSchemeOid = self::parseOid($encSchemeItems[0]['value']);

                        $cipher = null;
                        $keyLen = 32;
                        if ($encSchemeOid === '2.16.840.1.101.3.4.1.42') {
                            $cipher = 'aes-256-cbc';
                            $keyLen = 32;
                        } elseif ($encSchemeOid === '1.2.840.113549.3.7') {
                            $cipher = 'des-ede3-cbc';
                            $keyLen = 24;
                        }

                        if (!$cipher) continue;

                        $ivData = $encSchemeItems[1];
                        if ($ivData['tag'] !== 0x04) continue;
                        $iv = $ivData['value'];

                        $hashAlgo = 'sha256';
                        if (count($pbkdf2Items) >= 4 && $pbkdf2Items[3]['tag'] === 0x30) {
                            $prfItems = self::parseSeq($pbkdf2Items[3]['value']);
                            if (count($prfItems) >= 1 && $prfItems[0]['tag'] === 0x06) {
                                $prfOid = self::parseOid($prfItems[0]['value']);
                                $hashMap = [
                                    '1.2.840.113549.2.7' => 'sha1',
                                    '1.2.840.113549.2.8' => 'sha224',
                                    '1.2.840.113549.2.9' => 'sha256',
                                    '1.2.840.113549.2.10' => 'sha384',
                                    '1.2.840.113549.2.11' => 'sha512',
                                ];
                                $hashAlgo = $hashMap[$prfOid] ?? 'sha256';
                            }
                        }

                        $derivedKey = hash_pbkdf2($hashAlgo, $password, $salt['value'], $iterCount, $keyLen, true);
                        $decrypted = openssl_decrypt($encDataValue['value'], $cipher, $derivedKey, OPENSSL_RAW_DATA, $iv);
                        if ($decrypted === false) continue;

                        $decOffset = 0;
                        $decSafeContents = self::parseDer($decrypted, $decOffset);
                        if (!$decSafeContents || $decSafeContents['tag'] !== 0x30) continue;

                        $decBags = self::parseSeq($decSafeContents['value']);

                        foreach ($decBags as $bag) {
                            if ($bag['tag'] !== 0x30) continue;

                            $bagItems = self::parseSeq($bag['value']);
                            if (count($bagItems) < 2) continue;

                            $bagOid = self::parseOid($bagItems[0]['value']);

                            if ($bagOid === '1.2.840.113549.1.12.10.1.2') {
                                $bagValue = $bagItems[1];
                                if ($bagValue['tag'] !== 0xA0) continue;

                                $encPrivKeyOffset = 0;
                                $encPrivKey = self::parseDer($bagValue['value'], $encPrivKeyOffset);
                                if (!$encPrivKey || $encPrivKey['tag'] !== 0x30) continue;

                                $encPrivKeyItems = self::parseSeq($encPrivKey['value']);
                                if (count($encPrivKeyItems) < 3) continue;

                                $privKeyOctet = $encPrivKeyItems[2];
                                if ($privKeyOctet['tag'] !== 0x04) continue;

                                $ecKeyOffset = 0;
                                $ecKey = self::parseDer($privKeyOctet['value'], $ecKeyOffset);
                                if (!$ecKey || $ecKey['tag'] !== 0x30) continue;

                                $ecKeyItems = self::parseSeq($ecKey['value']);
                                if (count($ecKeyItems) < 2) continue;

                                $privKeyBytes = $ecKeyItems[1];
                                if ($privKeyBytes['tag'] !== 0x04) continue;

                                return bin2hex($privKeyBytes['value']);
                            }
                        }
                    }
                }
            }
        }

        // encryptedData (1.2.840.113549.1.7.6) 在顶层
        if ($contentOid === '1.2.840.113549.1.7.6') {
            $encDataContent = $contentWrapper;
            if ($encDataContent['tag'] !== 0xA0) return null;

            $encDataOffset = 0;
            $encDataOctet = self::parseDer($encDataContent['value'], $encDataOffset);
            if (!$encDataOctet || $encDataOctet['tag'] !== 0x04) return null;

            $encDataInnerOffset = 0;
            $encDataInner = self::parseDer($encDataOctet['value'], $encDataInnerOffset);
            if (!$encDataInner || $encDataInner['tag'] !== 0x30) return null;

            $encDataItems = self::parseSeq($encDataInner['value']);
            if (count($encDataItems) < 2) return null;

            $encDataAlgo = $encDataItems[0];
            if ($encDataAlgo['tag'] !== 0x30) return null;

            $encDataAlgoItems = self::parseSeq($encDataAlgo['value']);
            if (count($encDataAlgoItems) < 1) return null;

            $encDataAlgoOid = self::parseOid($encDataAlgoItems[0]['value']);

            if ($encDataAlgoOid === '1.2.840.113549.1.5.13') {
                $encDataValue = $encDataItems[1];
                if ($encDataValue['tag'] !== 0x04) return null;

                if (count($encDataAlgoItems) < 2) return null;
                $pbes2Params = $encDataAlgoItems[1];
                if ($pbes2Params['tag'] !== 0x30) return null;

                $pbes2Items = self::parseSeq($pbes2Params['value']);
                if (count($pbes2Items) < 2) return null;

                $kdf = $pbes2Items[0];
                if ($kdf['tag'] !== 0x30) return null;

                $kdfItems = self::parseSeq($kdf['value']);
                if (count($kdfItems) < 2) return null;

                $kdfOid = self::parseOid($kdfItems[0]['value']);
                if ($kdfOid !== '1.2.840.113549.1.5.12') return null;

                $pbkdf2Params = $kdfItems[1];
                if ($pbkdf2Params['tag'] !== 0x30) return null;

                $pbkdf2Items = self::parseSeq($pbkdf2Params['value']);
                if (count($pbkdf2Items) < 2) return null;

                $salt = $pbkdf2Items[0];
                if ($salt['tag'] !== 0x04) return null;

                $iterCount = self::parseInteger($pbkdf2Items[1]['value']);

                $encScheme = $pbes2Items[1];
                if ($encScheme['tag'] !== 0x30) return null;

                $encSchemeItems = self::parseSeq($encScheme['value']);
                if (count($encSchemeItems) < 2) return null;

                $encSchemeOid = self::parseOid($encSchemeItems[0]['value']);

                $cipher = null;
                $keyLen = 32;
                if ($encSchemeOid === '2.16.840.1.101.3.4.1.42') {
                    $cipher = 'aes-256-cbc';
                    $keyLen = 32;
                } elseif ($encSchemeOid === '1.2.840.113549.3.7') {
                    $cipher = 'des-ede3-cbc';
                    $keyLen = 24;
                }

                if (!$cipher) return null;

                $ivData = $encSchemeItems[1];
                if ($ivData['tag'] !== 0x04) return null;
                $iv = $ivData['value'];

                $hashAlgo = 'sha256';
                if (count($pbkdf2Items) >= 4 && $pbkdf2Items[3]['tag'] === 0x30) {
                    $prfItems = self::parseSeq($pbkdf2Items[3]['value']);
                    if (count($prfItems) >= 1 && $prfItems[0]['tag'] === 0x06) {
                        $prfOid = self::parseOid($prfItems[0]['value']);
                        $hashMap = [
                            '1.2.840.113549.2.7' => 'sha1',
                            '1.2.840.113549.2.8' => 'sha224',
                            '1.2.840.113549.2.9' => 'sha256',
                            '1.2.840.113549.2.10' => 'sha384',
                            '1.2.840.113549.2.11' => 'sha512',
                        ];
                        $hashAlgo = $hashMap[$prfOid] ?? 'sha256';
                    }
                }

                $derivedKey = hash_pbkdf2($hashAlgo, $password, $salt['value'], $iterCount, $keyLen, true);
                $decrypted = openssl_decrypt($encDataValue['value'], $cipher, $derivedKey, OPENSSL_RAW_DATA, $iv);
                if ($decrypted === false) return null;

                $decOffset = 0;
                $decSafeContents = self::parseDer($decrypted, $decOffset);
                if (!$decSafeContents || $decSafeContents['tag'] !== 0x30) return null;

                $decBags = self::parseSeq($decSafeContents['value']);

                foreach ($decBags as $bag) {
                    if ($bag['tag'] !== 0x30) continue;

                    $bagItems = self::parseSeq($bag['value']);
                    if (count($bagItems) < 2) continue;

                    $bagOid = self::parseOid($bagItems[0]['value']);

                    if ($bagOid === '1.2.840.113549.1.12.10.1.2') {
                        $bagValue = $bagItems[1];
                        if ($bagValue['tag'] !== 0xA0) continue;

                        $encPrivKeyOffset = 0;
                        $encPrivKey = self::parseDer($bagValue['value'], $encPrivKeyOffset);
                        if (!$encPrivKey || $encPrivKey['tag'] !== 0x30) continue;

                        $encPrivKeyItems = self::parseSeq($encPrivKey['value']);
                        if (count($encPrivKeyItems) < 3) continue;

                        $privKeyOctet = $encPrivKeyItems[2];
                        if ($privKeyOctet['tag'] !== 0x04) continue;

                        $ecKeyOffset = 0;
                        $ecKey = self::parseDer($privKeyOctet['value'], $ecKeyOffset);
                        if (!$ecKey || $ecKey['tag'] !== 0x30) continue;

                        $ecKeyItems = self::parseSeq($ecKey['value']);
                        if (count($ecKeyItems) < 2) continue;

                        $privKeyBytes = $ecKeyItems[1];
                        if ($privKeyBytes['tag'] !== 0x04) continue;

                        return bin2hex($privKeyBytes['value']);
                    }
                }
            }
        }

        return null;
    }

    // ===== ASN.1/DER 解析辅助方法 =====

    private static function parseDer($data, &$offset)
    {
        if ($offset >= strlen($data)) return null;

        $tag = ord($data[$offset++]);
        $len = ord($data[$offset++]);

        if ($len & 0x80) {
            $lenBytes = $len & 0x7F;
            $len = 0;
            for ($i = 0; $i < $lenBytes; $i++) {
                $len = ($len << 8) | ord($data[$offset++]);
            }
        }

        $value = substr($data, $offset, $len);
        $offset += $len;

        return ['tag' => $tag, 'len' => $len, 'value' => $value];
    }

    private static function parseSeq($data)
    {
        $items = [];
        $offset = 0;
        while ($offset < strlen($data)) {
            $item = self::parseDer($data, $offset);
            if (!$item) break;
            $items[] = $item;
        }
        return $items;
    }

    private static function parseOid($data)
    {
        if (strlen($data) < 2) return '';
        $bytes = array_values(unpack('C*', $data));
        $oid = [];
        $oid[] = intdiv($bytes[0], 40);
        $oid[] = $bytes[0] % 40;

        $val = 0;
        for ($i = 1; $i < count($bytes); $i++) {
            $val = ($val << 7) | ($bytes[$i] & 0x7F);
            if (!($bytes[$i] & 0x80)) {
                $oid[] = $val;
                $val = 0;
            }
        }
        return implode('.', $oid);
    }

    private static function parseInteger($data)
    {
        $val = 0;
        $bytes = array_values(unpack('C*', $data));
        foreach ($bytes as $byte) {
            $val = ($val << 8) | $byte;
        }
        return $val;
    }

    // ===== SM2 加解密/签名/验签 =====

    /**
     * SM2 加密
     */
    public static function encrypt(string $plaintext, string $publicKey): string
    {
        $sm2 = new RtSm2('base64', true);
        return $sm2->doEncrypt($plaintext, $publicKey);
    }

    /**
     * SM2 解密
     */
    public static function decrypt(string $ciphertext, string $privateKey): string
    {
        $sm2 = new RtSm2('hex', true);
        return $sm2->doDecrypt($ciphertext, $privateKey);
    }

    /**
     * SM2 签名
     */
    public static function sign(string $data, string $privateKey, string $userId = '1234567812345678'): string
    {
        $sm2 = new RtSm2('base64', true);
        return $sm2->doSign($data, $privateKey, $userId);
    }

    /**
     * SM2 验签
     */
    public static function verify(string $data, string $sign, string $publicKey, string $userId = '1234567812345678'): bool
    {
        $sm2 = new RtSm2('base64', true);
        return $sm2->verifySign($data, $sign, $publicKey, $userId);
    }

    // ===== SM4 加解密 =====

    /**
     * 生成 16 位 SM4 随机密钥（与 demo generateRandomKey 一致）
     */
    public static function generateSm4Key(): string
    {
        $str = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $len = strlen($str) - 1;
        $randstr = '';
        for ($i = 0; $i < 16; $i++) {
            $randstr .= $str[mt_rand(0, $len)];
        }
        return $randstr;
    }

    /**
     * SM4 加密（CBC 模式，输出 base64）
     */
    public static function sm4Encrypt(string $data, string $key, string $ivBase64): string
    {
        $sm4 = new RtSm4($key);
        return $sm4->encrypt($data, 'sm4', base64_decode($ivBase64), 'base64');
    }

    /**
     * SM4 解密（CBC 模式，输入 base64）
     */
    public static function sm4Decrypt(string $data, string $key, string $ivBase64): string
    {
        $sm4 = new RtSm4($key);
        return $sm4->decrypt($data, 'sm4', base64_decode($ivBase64), 'base64');
    }
}
