<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\RSAUtil;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

/**
 * 苏宁易付宝协议支付网关
 * 
 * 说明：
 * 1. 所有请求统一走 requestSigned()
 * 2. 所有签名逻辑统一封装
 * 3. 金额统一使用分（int）避免精度问题
 * 4. 敏感字段使用 RSA 公钥加密（十六进制编码）
 */
class SuningGateway extends AbstractGateway
{
    /* =========================
     | 常量定义
     * =========================*/

    /** 借记卡 */
    const DEBIT_CARD = 0;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    /** 成功状态码 */
    const RESP_SUCCESS = 'XY_0000';

    /**
     * 构造方法
     */
    public function __construct(array $config, string $logPath)
    {
        parent::__construct('suning', $config, $logPath);
    }

    /* =====================================================
     | 签约流程
     * =====================================================*/

    /**
     * H5 签约
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function h5Sign(array $params)
    {
        return $this->authEndpoint($params);
    }

    /**
     * 微信签约
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function wxSign(array $params)
    {
        return $this->authEndpoint($params);
    }

    /**
     * 预签约（发送短信）
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function authEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, [
            'card_no',
            't_name',
            't_paper_num',
            't_tel',
            'num_id'
        ]);

        $requestBody = [
            'encryptCardInfo' => $this->encryptField(json_encode([
                'cardHolderName' => $params['t_name'],
                'certType' => '01',
                'certNo' => $params['t_paper_num'],
                'mobileNo' => $params['t_tel'],
                'cardNo' => $params['card_no'],
            ], JSON_UNESCAPED_UNICODE)),
            'merchantUserNo' => $params['t_tel'],
            'salerMerchantNo' => $this->config['mch_id'],
            'clientIp' => Utils::ip(),
            'goodsType' => $this->config['goodsType'],
            'productCode' => '00010000674',
            'version' => '2.5',
            'requestNo' => $params['num_id'] . '-' . rand(10000, 99999),
            'signTactics' => '01',
            'cancelNotifyUrl' => $this->config['sign_callback'],
        ];

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode($requestBody, JSON_UNESCAPED_UNICODE)]),
            $this->config['authorization_url']
        );
    }

    /**
     * 确认签约（短信验证码）
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
            'msg_id',
            'code',
        ]);

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode([
                'salerMerchantNo' => $this->config['mch_id'],
                'msgId' => $params['msg_id'],
                'requestNo' => $params['num_id'] . '-' . rand(10000, 99999),
                'verificationCode' => $params['code'],
                'version' => '2.2',
                'clientIp' => Utils::ip(),
                'productCode' => '00010000674',
            ], JSON_UNESCAPED_UNICODE)]),
            $this->config['sign_url']
        );
    }

    /**
     * 查询签约
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function querySign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id',
            't_tel',
        ]);

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode([
                'merchantUserNo' => $params['t_tel'],
                'serialNo' => $params['num_id'] . '-' . rand(10000, 99999),
                'version' => '3.2',
            ], JSON_UNESCAPED_UNICODE)]),
            $this->config['query_sign_url']
        );
    }

    /**
     * 静默签约
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function silentSign(array $params)
    {
        $queryRes = $this->querySign($params);
        if (isset($queryRes['responseCode']) && $queryRes['responseCode'] == self::RESP_SUCCESS) {
            $params['agreement_no'] = $queryRes['data'][0]['contractNo'];
            return $params;
        }
        return $queryRes;
    }

    /* =====================================================
     | 支付 / 退款
     * =====================================================*/

    /**
     * 协议扣款
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function deductMoney(array $params)
    {
        Validator::validateRequiredFields($params, [
            'agreement_no',
            'expect_money',
            'num_id',
        ]);

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode([
                'clientIp' => Utils::ip(),
                'contractNo' => $params['agreement_no'],
                'currency' => 'CNY',
                'orderAmount' => $this->amountToCent($params['expect_money']),
                'goodsType' => $this->config['goodsType'],
                'notifyUrl' => $this->config['callback'],
                'orderName' => base64_encode($params['num_id']),
                'orderTime' => date('YmdHms'),
                'outOrderNo' => $params['out_trade_no'],
                'productCode' => '00010000674',
                'requestNo' => $params['num_id'] . '-' . rand(10000, 99999),
                'version' => '3.6',
            ], JSON_UNESCAPED_UNICODE)]),
            $this->config['pay_url']
        );
    }

    /**
     * 退款接口
     * @param array $params
     * @return array
     * @throws MonthPayException
     */
    public function commonRefund(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no',
            'refund_no',
            'refund_amount',
            'orderTime'
        ]);

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode([
                'submitTime' => date('YmdHms'),
                'notifyUrl' => $this->config['pay_callback'],
                'version' => '3.2',
                'refundOrderNo' => $params['refund_no'],
                'origMerchantOrderNo' => $params['out_trade_no'],
                'origOutOrderNo' => $params['out_trade_no'],
                'origOrderTime' => $params['orderTime'],
                'refundOrderTime' => date('YmdHms'),
                'refundAmount' => $this->amountToCent($params['refund_amount']),
            ], JSON_UNESCAPED_UNICODE)]),
            $this->config['refund_url']
        );
    }

    /**
     * 银行卡签约（一键绑卡）
     * @param array $params
     * @param string $cardType 借记卡：DEBIT / 贷记卡：CREDIT
     * @return array
     * @throws MonthPayException
     */
    public function bankSign(array $params, $cardType = 'DEBIT')
    {
        Validator::validateRequiredFields($params, [
            't_name',
            't_paper_num',
            'out_trade_no',
            'bank_no',
            't_tel'
        ]);

        return $this->requestSigned(
            array_merge($this->buildBaseData(), ['requestBody' => json_encode([
                'encryptCardInfo' => $this->encryptField(json_encode([
                    'cardHolderName' => $params['t_name'],
                    'certType' => '01',
                    'certNo' => $params['t_paper_num'],
                    'bankCode' => $params['bank_no'],
                    'cardType' => $cardType,
                ], JSON_UNESCAPED_UNICODE)),
                'merchantUserNo' => $params['t_tel'],
                'salerMerchantNo' => $this->config['mch_id'],
                'clientIp' => Utils::ip(),
                'goodsType' => $this->config['goodsType'],
                'productCode' => '00010000674',
                'version' => '2.6',
                'requestNo' => $params['out_trade_no'],
                'signTactics' => '02',
                'cancelNotifyUrl' => $this->config['sign_callback'],
                'notifyUrl' => $this->config['sign_callback'],
                'returnUrl' => $this->config['return_url'],
            ], JSON_UNESCAPED_UNICODE)]),
            $this->config['authorization_url']
        );
    }

    /* =====================================================
     | 核心请求流程
     * =====================================================*/

    /**
     * 统一签名并发送请求
     * @param array $data
     * @param string $url
     * @return array
     * @throws MonthPayException
     */
    protected function requestSigned(array $data, $url)
    {
        $data['sign'] = $this->sign($data);
        return $this->request($data, $url, 'requestSigned');
    }

    /**
     * 统一请求入口
     * @param array $params
     * @param string $url
     * @param string $action
     * @return array
     * @throws MonthPayException
     */
    protected function request(array $params, $url, $action)
    {
        $this->logRequest($action, $params);
        $result = $this->sendJsonRequest($url, $params);
        $this->logResponse($action, $result);

        $res = json_decode($result, true);
        if (!is_array($res)) {
            throw new MonthPayException('接口返回格式异常');
        }

        return $this->handleResponse($res);
    }

    /**
     * 发送 JSON 请求
     * @param string $url
     * @param array $params
     * @return string
     * @throws MonthPayException
     */
    protected function sendJsonRequest($url, array $params)
    {
        $jsonBody = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $headers = [
            'content-type: application/json',
            'Accept: application/json',
            'charset=UTF-8',
            'Content-Length: ' . strlen($jsonBody)
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 记录响应日志
        $this->log->info('HTTP 状态码：' . $httpCode);
        $this->log->info('错误信息：' . $error);

        if ($error) {
            throw new MonthPayException('请求失败：' . $error);
        }

        return $result;
    }

    /**
     * 响应处理（验签 + 检查状态）
     * @param array $res
     * @return array
     * @throws MonthPayException
     */
    protected function handleResponse(array $res)
    {
        // 先验签（如果有 gsSign）
        if (isset($res['gsSign'])) {
            $this->verifyResponseSign($res);
        }

        // 检查返回码
        $respCode = isset($res['responseCode']) ? $res['responseCode'] : '';
        if ($respCode !== self::RESP_SUCCESS) {
            $msg = isset($res['responseMsg']) ? $res['responseMsg'] : '交易失败';
            throw new MonthPayException($msg, '-1', null, $respCode);
        }

        return $res;
    }

    /**
     * 响应报文验签
     * 验签步骤：
     * 1. 排除 gsSign 字段
     * 2. 其他字段按 Key 排序
     * 3. 拼接 Key1=Value1&Key2=Value2&...
     * 4. 对明文字符串进行 MD5 摘要
     * 5. 使用易付宝公钥验证签名
     * @param array $res
     * @throws MonthPayException
     */
    protected function verifyResponseSign(array $res)
    {
        $gsSign = $res['gsSign'];
        unset($res['gsSign']);
        
        // 按 Key 排序
        ksort($res);

        // 拼接字符串
        $signStr = '';
        foreach ($res as $key => $value) {
            if ($value !== '' && $value !== null) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $signStr .= $key . '=' . $value . '&';
            }
        }
        $signStr = rtrim($signStr, '&');

        // MD5 摘要
        $md5Str = strtoupper(md5($signStr));

        // 使用易付宝公钥验签
        $publicKey = $this->config['public_key'];
        if (empty($publicKey)) {
            throw new MonthPayException('公钥未配置，无法验签');
        }
        
        $publicKeyContent = is_file($publicKey) ? file_get_contents($publicKey) : $publicKey;

        // 如果是纯密钥内容（没有 PEM 头部），添加 PEM 格式标识
        if (strpos($publicKeyContent, '-----BEGIN PUBLIC KEY-----') === false) {
            $cleanKey = str_replace(["\r", "\n", " "], '', $publicKeyContent);
            $lines = str_split($cleanKey, 64);
            $publicKeyContent = "-----BEGIN PUBLIC KEY-----\n" .
                                implode("\n", $lines) .
                                "\n-----END PUBLIC KEY-----";
        }

        $publicKey = openssl_get_publickey($publicKeyContent);
        if (!$publicKey) {
            throw new MonthPayException('易付宝公钥加载失败');
        }

        // 将 URL-safe Base64 转换为标准 Base64
        // 将 - 替换为 +，将 _ 替换为 /
        $base64 = strtr($gsSign, '-_', '+/');
        // 补充 = 填充（Base64 长度必须是 4 的倍数）
        $padding = strlen($base64) % 4;
        if ($padding) {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        $signature = base64_decode($base64);
        
        $result = openssl_verify($md5Str, $signature, $publicKey, OPENSSL_ALGO_SHA1);

        if ($result !== 1) {
            throw new MonthPayException('响应报文验签失败');
        }
    }

    /* =====================================================
     | 工具方法
     * =====================================================*/

    /**
     * 构建基础公共参数
     * @return array
     */
    protected function buildBaseData()
    {
        return [
            'timestamp' => date('YmdHms'),
            'appId' => $this->config['appid'],
            'version' => '1.0',
            'signType' => 'RSA2',
            'signkeyIndex' => $this->config['signkeyIndex'],
        ];
    }

    /**
     * 统一签名（RSA 签名）
     * 签名步骤：
     * 1. 移除 signType 和 signkeyIndex 字段
     * 2. 按 Key 的 ASCII 码排序（a-z）
     * 3. 拼接 Key1=Value1&Key2=Value2&...（value 如果是数组/对象，转成 JSON 字符串）
     * 4. 对明文字符串进行 MD5 摘要
     * 5. 使用商户私钥对 MD5 摘要进行 RSA 签名
     * @param array $data
     * @return string
     */
    protected function sign(array $data)
    {
        // 移除不参与签名的字段
        unset($data['signType']);
        unset($data['signkeyIndex']);
        unset($data['sign']);
        
        // 按 Key 的 ASCII 码排序
        ksort($data);
        
        // 拼接字符串
        $signStr = '';
        foreach ($data as $key => $value) {
            if ($value !== '' && $value !== null) {
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $signStr .= $key . '=' . $value . '&';
            }
        }
        $signStr = rtrim($signStr, '&');
        
        // 对明文字符串进行 MD5 摘要
        $md5Str = strtoupper(md5($signStr));
        
        // 使用苏宁专用签名方法
        return RSAUtil::signForSuning($md5Str, $this->config['private_key']);
    }

    /**
     * 敏感字段加密（RSA 公钥加密）
     * 加密规范：
     * 1. 使用易付宝公钥进行 RSA 加密
     * 2. 填充方式：PKCS1Padding
     * 3. 编码格式：十六进制（大写）
     * @param string $value
     * @return string
     * @throws MonthPayException
     */
    protected function encryptField($value)
    {
        if (empty($this->config['user_public'])) {
            throw new MonthPayException('易付宝公钥未配置');
        }
        
        return RSAUtil::encryptForSuning($value, $this->config['user_public']);
    }

    /**
     * 金额转分（避免 float 精度问题）
     * @param string $amount
     * @return int
     */
    protected function amountToCent($amount)
    {
        return (int)bcmul($amount, '100', 0);
    }
}
