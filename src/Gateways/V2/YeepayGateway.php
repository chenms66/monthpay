<?php

namespace BaiGe\MonthPay\Gateways\V2;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

/**
 * 易宝支付网关
 * 
 * 基于易宝 YOP-RSA2048-SHA256 签名协议实现以下功能：
 * 1. 一键绑卡（绑卡请求 + 交易下单 + 绑卡支付下单 + 确认支付）
 * 2. 协议签约（绑卡请求 + 短验确认 + 交易下单 + 绑卡支付下单 + 确认支付）
 * 3. 申请退款
 * 
 * 签名协议参考: https://open.yeepay.com/docs
 */
class YeepayGateway extends AbstractGateway
{
    /* =========================
     | 常量定义
     * =========================*/

    /** 借记卡 */
    const DEBIT_CARD = 2;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    /** 成功状态码 */
    const CODE_SUCCESS = 'NOP00000';
    const TOKEN_SUCCESS = 'OPR00000';
    const PAY_CONFIRM = 'CAS00000';
    const PAY_ORDER_PROCESSING = '00105';

    /** API 路径 */
    const API_FAST_BIND_CARD_REQUEST = '/rest/v1.0/frontcashier/bindcard/netsunion/request';
    const API_BIND_CARD_REQUEST = '/rest/v1.0/frontcashier/agreement/sign/request';
    const API_RESEND_SMS = '/rest/v1.0/frontcashier/agreement/sign/sms';
    const API_BIND_CARD_CONFIRM = '/rest/v1.0/frontcashier/agreement/sign/confirm';
    const API_TRADE_REFUND = '/rest/v1.0/trade/refund';
    const API_QUERY = '/rest/v1.0/frontcashier/bindcard/bindcardlist';
    const PAY = '/rest/v1.0/cnppay/agreement/pay/request';

    /** 易宝API基础地址 */
    protected $baseUrl;

    /** 应用密钥 */
    protected $appKey;

    /** 私钥内容 */
    protected $privateKey;

    /** 易宝公钥内容 */
    protected $publicKey;

    /**
     * 构造方法
     */
    public function __construct(array $config, string $logPath)
    {
        parent::__construct('yeepay', $config, $logPath);

        $this->baseUrl = $config['base_url'] ?? 'https://openapi.yeepay.com/yop-center';
        $this->appKey = $config['app_key'] ?? ($config['app_id'] ?? '');

        // 读取私钥文件
        $privateKeyPath = $config['private_key'] ?? '';
        if (file_exists($privateKeyPath)) {
            $this->privateKey = file_get_contents($privateKeyPath);
        } else {
            $this->privateKey = $privateKeyPath;
        }

        // 读取公钥文件
        $publicKeyPath = $config['yeepay_callback_public'] ?? '';
        if (file_exists($publicKeyPath)) {
            $this->publicKey = file_get_contents($publicKeyPath);
        } else {
            $this->publicKey = $publicKeyPath;
        }
    }

    /* =====================================================
     | H5 / 微信签约入口
     * =====================================================*/

    public function h5Sign(array $params)
    {
        return $this->bindCardRequest($params);
    }

    public function wxSign(array $params)
    {
        return $this->bindCardRequest($params);
    }

    /* =====================================================
     | 一键绑卡流程
     * =====================================================*/

    /**
     * 一键绑卡请求
     */
    public function bankSign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
            'bank_no',
            't_name',
            't_paper_num',
            't_tel',
        ]);

        $data = $this->buildBaseParams();
        $data['merchantFlowId'] = $params['num_id'];
        $data['userNo'] = $params['t_paper_num'];
        $data['userType'] = $params['user_type'] ?? 'ID_CARD';
        $data['userName'] = $params['t_name'];
        $data['idCardNo'] = $params['t_paper_num'];
        $data['idCardType'] = $params['t_paper_type'] ?? 'ID';
        $data['bankCode'] = $params['bank_no'];
        $data['cardType'] = $params['card_type'] == self::DEBIT_CARD ? 'OD' : 'OC';
        $data['pageReturnUrl'] = $this->config['return_url'] ?? '';
        $data['bindNotifyUrl'] = $this->config['sign_callback'] ?? '';
        $data['phone'] = $params['t_tel'];
        return $this->request($data, self::API_FAST_BIND_CARD_REQUEST, __FUNCTION__);
    }

    public function pay(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no',
            'expect_money',
            'agreement_no',
            't_paper_num'
        ]);

        $data = $this->buildBaseParams();
        $data['orderId'] = $params['out_trade_no'];
        $data['orderAmount'] = $params['expect_money'];
        $data['bindId'] = $params['agreement_no'];
        $data['goodsName'] = $params['goods_name'] ?? '交易订单';
        $data['payScene'] = $params['payScene'] ?? 'FAST';
        $data['notifyUrl'] = $this->config['callback'] ?? '';
        $data['userNo'] = $params['t_paper_num'];
        $data['userType'] = $params['user_type'] ?? 'ID_CARD';
        return $this->request($data, self::PAY, __FUNCTION__);
    }


    /* =====================================================
     | 协议签约流程
     * =====================================================*/

    /**
     * 第一步：协议支付-签约请求
     */
    public function bindCardRequest(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
            'card_no',
            't_name',
            't_paper_num',
            't_tel',
            'card_type'
        ]);

        $data = $this->buildBaseParams();
        $data['merchantFlowId'] = $params['num_id'];
        $data['userNo'] = $params['t_paper_num'];
        $data['userType'] = $params['user_type'] ?? 'ID_CARD';
        $data['bankCardNo'] = $params['card_no'];
        $data['userName'] = $params['t_name'];
        $data['idCardNo'] = $params['t_paper_num'];
        $data['mobilePhoneNo'] = $params['t_tel'];
        $data['cardType'] = $params['card_type'] == self::DEBIT_CARD ? 'OD' : 'OC';//仅贷记卡支持(OC)、仅借记卡支持(OD)、借记卡、贷记卡均支持(DC)
        $data['idCardType'] = $params['id_card_type'] ?? 'ID';
        return $this->request($data, self::API_BIND_CARD_REQUEST, __FUNCTION__);
    }

    /**
     * 第二步：协议支付-签约确认
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
            'code',
        ]);

        $data = $this->buildBaseParams();
        $data['merchantFlowId'] = $params['num_id'];
        $data['smsCode'] = $params['code'];
        return $this->request($data, self::API_BIND_CARD_CONFIRM, __FUNCTION__);
    }


    public function resendSms(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
        ]);

        $data = $this->buildBaseParams();
        $data['merchantFlowId'] = $params['num_id'];
        return $this->request($data, self::API_RESEND_SMS, __FUNCTION__);
    }

    /* =====================================================
     | 扣款（交易下单）
     * =====================================================*/

    public function deductMoney(array $params)
    {
        return $this->pay($params);
    }

    /* =====================================================
     | 申请退款
     * =====================================================*/

    /**
     * 申请退款
     */
    public function commonRefund(array $params)
    {
        Validator::validateRequiredFields($params, [
            'refund_amount',
            'out_trade_no',
            'refund_no'
        ]);

        $data = $this->buildBaseParams();
        $data['refundRequestId'] = $params['refund_no'];
        $data['refundAmount'] = $params['refund_amount'];
        $data['uniqueOrderNo'] = $params['out_trade_no'];
        $data['notifyUrl'] = $this->config['pay_callback'];

        return $this->request($data, self::API_TRADE_REFUND, __FUNCTION__);
    }

    /* =====================================================
     | 查询
     * =====================================================*/

    /**
     * 查询
     */
    public function querySign(array $params)
    {
        Validator::validateRequiredFields($params, ['t_paper_num']);

        $data = $this->buildBaseParams();
        $data['userNo'] = $params['t_paper_num'];
        $data['userType'] = $params['user_type'] ?? 'ID_CARD';
        $data['bindId'] = $params['agreement_no'] ?? '';
        return $this->request($data, self::API_QUERY, __FUNCTION__);
    }

    /* =====================================================
     | 静默签约
     * =====================================================*/

    /**
     * 静默签约
     */
    public function silentSign(array $params)
    {
        return $this->querySign($params);
    }

    /* =====================================================
     | 核心方法（提取自SDK）
     * =====================================================*/

    /**
     * 构建基础参数
     */
    protected function buildBaseParams(): array
    {
        return [
            'parentMerchantNo' => $this->config['parent_merchant_no'] ?? '',
            'merchantNo' => $this->config['merchant_no'],
        ];
    }

    /**
     * 发送请求（仿照DxmGateway模式）
     * @throws MonthPayException
     */
    protected function request(array $params, string $apiPath, string $action)
    {
        $this->logRequest($action, $params);
        // 生成请求ID和时间戳
        $requestId = $this->generateUuid();
        $timestamp = gmdate('Y-m-d\TH:i:s\Z', time());
        // 生成签名
        $headers = $this->sign($params, $apiPath, $requestId, $timestamp);
        // 发送请求（使用表单格式，与SDK一致）
        $url = $this->baseUrl . $apiPath;
        // 使用 http_build_query，与 HttpRequest.php line 99 一致
        foreach ($params as &$v){
            $v = urlencode($v);
        }
        $body = http_build_query($params, '', '&');
        $rawResponse = $this->curlPostForm($url, $body, $headers);
        // 解析响应
        $result = json_decode($rawResponse, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log->error("JSON解析错误: " . json_last_error_msg() . ", 原始响应: " . $rawResponse);
            throw new MonthPayException('接口返回格式异常: ' . json_last_error_msg());
        }

        if (!is_array($result)) {
            $this->log->error("响应不是数组: " . $rawResponse);
            throw new MonthPayException('接口返回格式异常');
        }
        $this->log->debug("Raw Response 原始响应: " . $rawResponse);

        $this->logResponse($action, $result);

        return $this->handleResponse($result);
    }

    /**
     * RSA-SHA256 签名（提取自YopRsaClient::SignRsaParameter）
     */
    protected function sign(array $params, string $apiPath, string $requestId, string $timestamp): array
    {
        $appKey = $this->appKey;
        $protocolVersion = "yop-auth-v2";
        $EXPIRED_SECONDS = "1800";

        // 1. 构造 authString
        $authString = $protocolVersion . "/" . $appKey . "/" . $timestamp . "/" . $EXPIRED_SECONDS;

        // 2. 需要签名的 headers（仅 x-yop-request-id）
        $headersToSignSet = ['x-yop-request-id'];

        // 3. 构造基础headers
        $headers = [
            'x-yop-appkey' => $appKey,
            'x-yop-request-id' => $requestId,
        ];

        // 4. 规范化 URI
        $canonicalURI = $this->normalizePath($apiPath);

        // 5. 处理查询字符串（仿照SDK的getCanonicalQueryString）
        // SDK: 如果jsonParam为空，则处理paramMap
        $canonicalQueryString = $this->getCanonicalQueryString($params);

        // 6. 过滤需要签名的 headers
        $headersToSign = $this->getHeadersToSign($headers, $headersToSignSet);

        // 7. 规范化 headers
        $canonicalHeader = $this->getCanonicalHeaders($headersToSign);

        // 8. 拼接已签名的 headers 名称
        $signedHeaders = '';
        foreach ($headersToSign as $key => $value) {
            $signedHeaders .= strlen($signedHeaders) == 0 ? "" : ";";
            $signedHeaders .= $key;
        }
        $signedHeaders = strtolower($signedHeaders);

        // 9. 构造待签名字符串
        $canonicalRequest = $authString . "\n"
            . "POST" . "\n"
            . $canonicalURI . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeader;
        // 10. RSA-SHA256 签名
        $privateKey = $this->privateKey;
        
        // 支持 PKCS#1 和 PKCS#8 格式
        if (strpos($privateKey, '-----BEGIN PRIVATE KEY-----') === false && 
            strpos($privateKey, '-----BEGIN RSA PRIVATE KEY-----') === false) {
            $privateKey = "-----BEGIN PRIVATE KEY-----\n" .
                wordwrap($privateKey, 64, "\n", true) .
                "\n-----END PRIVATE KEY-----";
        }

        $privateKeyObj = openssl_pkey_get_private($privateKey);
        if ($privateKeyObj === false) {
            throw new MonthPayException("私钥格式不正确");
        }
        $encodedData = '';
        openssl_sign($canonicalRequest, $encodedData, $privateKeyObj, "SHA256");
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privateKeyObj);
        }

        // 11. Base64Url 编码
        $signToBase64 = $this->base64UrlEncode($encodedData);
        $signToBase64 .= '$SHA256';

        // 12. 构造 Authorization
        $authorization = "YOP-RSA2048-SHA256 " . $authString . "/" . $signedHeaders . "/" . $signToBase64;
        // 13. 返回请求头数组
        return [
            'x-yop-appkey:' . $appKey,
            'x-yop-request-id:' . $requestId,
            'Authorization:' . $authorization,
            'x-yop-sdk-langs:php',
            'x-yop-sdk-version:3.1.13',
            'Content-Type:application/x-www-form-urlencoded',
        ];
    }

    /**
     * 规范化 URI 路径（提取自HttpUtils::normalizePath）
     */
    protected function normalizePath($path)
    {
        if ($path == null) {
            return "/";
        } else if (strpos($path, '/') === 0) {
            return str_replace("%2F", "/", rawurlencode($path));
        } else {
            return "/" . str_replace("%2F", "/", rawurlencode($path));
        }
    }

    /**
     * 规范化字符串（提取自HttpUtils::normalize）
     */
    protected function normalize($value)
    {
        return rawurlencode($value);
    }

    /**
     * 获取规范化查询字符串（提取自YopRsaClient::getCanonicalQueryString）
     * SDK逻辑：包含所有参数（包括空值），按字母排序，rawurlencode 值
     */
    protected function getCanonicalQueryString(array $params): string
    {
        $list = [];
        foreach ($params as $k => $v) {
            // 排除 Authorization
            if (strcasecmp($k, 'Authorization') == 0) {
                continue;
            }
            // SDK包含空值，不做排除
            $list[] = $k . '=' . rawurlencode((string)$v);
        }
        sort($list);
        return implode('&', $list);
    }

    /**
     * 获取需要签名的 headers（提取自YopRsaClient::getHeadersToSign）
     */
    protected function getHeadersToSign($headers, $headersToSign)
    {
        $ret = [];
        if ($headersToSign != null) {
            $tempSet = [];
            foreach ($headersToSign as $header) {
                $tempSet[] = strtolower(trim($header));
            }
            $headersToSign = $tempSet;
        }

        foreach ($headers as $key => $value) {
            if ($value != null && !empty($value)) {
                if (($headersToSign == null && $this->isDefaultHeaderToSign($key)) || 
                    ($headersToSign != null && in_array(strtolower($key), $headersToSign) && $key != "Authorization")) {
                    $ret[$key] = $value;
                }
            }
        }
        ksort($ret);
        return $ret;
    }

    /**
     * 判断是否为默认需要签名的 header
     */
    protected function isDefaultHeaderToSign($header)
    {
        $header = strtolower(trim($header));
        $defaultHeadersToSign = ["host", "content-type"];
        return strpos($header, "x-yop-") === 0 || in_array($header, $defaultHeadersToSign);
    }

    /**
     * 规范化 headers（提取自YopRsaClient::getCanonicalHeaders）
     */
    protected function getCanonicalHeaders($headers)
    {
        if (empty($headers)) {
            return "";
        }

        $headerStrings = [];
        foreach ($headers as $key => $value) {
            if ($key == null) {
                continue;
            }
            if ($value == null) {
                $value = "";
            }
            $key = $this->normalize(strtolower(trim($key)));
            $value = $this->normalize(trim($value));
            $headerStrings[] = $key . ':' . $value;
        }

        sort($headerStrings);
        $strQuery = "";
        foreach ($headerStrings as $kv) {
            $strQuery .= strlen($strQuery) == 0 ? "" : "\n";
            $strQuery .= $kv;
        }
        return $strQuery;
    }

    /**
     * 发送表单 POST 请求（与SDK一致，使用 application/x-www-form-urlencoded）
     */
    protected function curlPostForm(string $url, string $body, array $headers): string
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        
        $output = curl_exec($curl);
        
        if (curl_errno($curl)) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new MonthPayException("HTTP请求失败: " . $error);
        }
        
        curl_close($curl);
        return $output;
    }

    /**
     * 响应处理（参照SuningGateway）
     */
    protected function handleResponse(array $res): array
    {
        // 检查返回码
        if (isset($res['result']['code'])) {
            $respCode = $res['result']['code'];
            if ($respCode !== self::CODE_SUCCESS && $respCode !== self::TOKEN_SUCCESS && $respCode !== self::PAY_CONFIRM && $respCode !== self::PAY_ORDER_PROCESSING) {
                $msg = $res['result']['message'] ?? $res['result']['description'] ?? '交易失败';
                throw new MonthPayException($msg, '-1', null, $respCode);
            }
        }
        return $res['result'];
    }

    /**
     * 生成 UUID
     */
    protected function generateUuid(): string
    {
        $uid = uniqid("", true);
        $data = $_SERVER['REQUEST_TIME'] ?? time();
        $hash = hash('ripemd128', $uid . $data);

        return substr($uid, 0, 14) .
            substr($uid, 15, 24) .
            substr($hash, 0, 10);
    }

    /**
     * Base64Url 编码（提取自Base64Url::encode）
     */
    protected function base64UrlEncode($data, $use_padding = false): string
    {
        $encoded = strtr(base64_encode($data), '+/', '-_');
        return $use_padding ? $encoded : rtrim($encoded, '=');
    }

    /**
     * Base64Url 解码
     */
    protected function base64UrlDecode($data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * 解密数字信封（
     * @param string $source 待解密内容
     * @return string 已解密内容
     * @throws MonthPayException
     */
    public function decrypt($source): string
    {
        if (empty($this->privateKey)) {
            throw new MonthPayException('商户私钥未配置');
        }

        if (empty($this->publicKey)) {
            throw new MonthPayException('易宝公钥未配置');
        }

        if (!extension_loaded('openssl')) {
            throw new MonthPayException('php需要openssl扩展支持');
        }

        // 格式化私钥（统一使用 PKCS#8 格式，与构造函数读取的格式保持一致）
        $privateKeyContent = $this->privateKey;
        if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
            $privateKeyContent = "-----BEGIN PRIVATE KEY-----\n" .
                wordwrap($privateKeyContent, 64, "\n", true) .
                "\n-----END PRIVATE KEY-----";
        }

        $privateKey = openssl_get_privatekey($privateKeyContent);
        if (!$privateKey) {
            throw new MonthPayException('商户私钥不可用');
        }

        // 分解参数
        $args = explode('$', $source);
        if (count($args) != 4) {
            throw new MonthPayException('加密数据格式错误');
        }

        $encryptedRandomKeyToBase64 = $args[0];
        $encryptedDataToBase64 = $args[1];
        $digestAlg = $args[3];

        // 用私钥对随机密钥进行解密
        $randomKey = '';
        openssl_private_decrypt($this->base64UrlDecode($encryptedRandomKeyToBase64), $randomKey, $privateKey);
        openssl_free_key($privateKey);

        // 用随机密钥解密数据
        $encryptedData = openssl_decrypt($this->base64UrlDecode($encryptedDataToBase64), "AES-128-ECB", $randomKey, OPENSSL_RAW_DATA);
        if ($encryptedData === false) {
            throw new MonthPayException('数据解密失败');
        }

        // 分解签名和数据
        $signToBase64 = substr(strrchr($encryptedData, '$'), 1);
        $sourceData = substr($encryptedData, 0, strlen($encryptedData) - strlen($signToBase64) - 1);

        // 格式化公钥
        $publicKeyContent = $this->publicKey;
        if (strpos($publicKeyContent, '-----BEGIN PUBLIC KEY-----') === false) {
            $publicKeyContent = "-----BEGIN PUBLIC KEY-----\n" .
                wordwrap($publicKeyContent, 64, "\n", true) .
                "\n-----END PUBLIC KEY-----";
        }

        $publicKey = openssl_pkey_get_public($publicKeyContent);
        if (!$publicKey) {
            throw new MonthPayException('易宝公钥加载失败');
        }

        // 验签
        $res = openssl_verify($sourceData, $this->base64UrlDecode($signToBase64), $publicKey, $digestAlg);
        openssl_free_key($publicKey);
        if ($res !== 1) {
            throw new MonthPayException('响应报文验签失败');
        }

        return $sourceData;
    }

    /**
     * 金额转分
     */
    protected function amountToCent($amount): int
    {
        return (int)bcmul($amount, '100', 0);
    }
}
