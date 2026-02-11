<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\AESUtil;
use BaiGe\MonthPay\Support\RSAUtil;
use BaiGe\MonthPay\Support\SHA1Util;
use BaiGe\MonthPay\Support\SignatureUtils;
use BaiGe\MonthPay\Support\Tools;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

/**
 * 宝付协议支付网关
 *
 * 说明：
 * 1. 所有请求统一走 requestSigned()
 * 2. 所有签名逻辑统一封装
 * 3. 所有数字信封与 AES 解密统一处理
 * 4. 金额统一使用分（int）避免精度问题
 */
class BaofuGateway extends AbstractGateway
{
    /* =========================
     | 常量定义
     * =========================*/

    /** 借记卡 */
    const DEBIT_CARD = 0;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    /** 成功状态码 */
    const RESP_SUCCESS = 'S';

    /** 失败状态码 */
    const RESP_FAIL = 'F';

    /** 处理中状态码 */
    const RESP_WAIT = 'I';

    /**
     * 构造方法
     */
    public function __construct(array $config, string $logPath)
    {
        parent::__construct('baofu', $config, $logPath);
    }

    /* =====================================================
     | 签约流程
     * =====================================================*/

    /**
     * H5 签约
     */
    public function h5Sign(array $params)
    {
        return $this->authEndpoint($params);
    }

    /**
     * 微信签约
     */
    public function wxSign(array $params)
    {
        return $this->authEndpoint($params);
    }

    /**
     * 预签约（发送短信）
     *
     * txn_type = 01
     */
    public function authEndpoint(array $params, $txn_type = '01')
    {
        Validator::validateRequiredFields($params, [
            'card_type','card_no','t_paper_type',
            't_name','t_paper_num','t_tel','num_id'
        ]);

        // 组装银行卡信息
        $cardInfo = implode('|', [
            $params['card_no'],
            $params['t_name'],
            $params['t_paper_num'],
            $params['t_tel']
        ]);

        $data = $this->buildBaseData($params['num_id'], $txn_type) + [
                'card_type'    => $params['card_type'] == self::DEBIT_CARD ? '101' : '102',
                'id_card_type' => '0'.$params['t_paper_type'],
                'acc_info'     => $this->encryptWithBase64($cardInfo),
            ];

        return $this->requestSigned($data);
    }

    /**
     * 确认签约
     *
     * txn_type = 02
     */
    public function signingEndpoint(array $params, $txn_type = '02')
    {
        Validator::validateRequiredFields($params, [
            'unique_code','code','num_id'
        ]);

        $unique = $params['unique_code'].'|'.$params['code'];

        $data = $this->buildBaseData($params['num_id'], $txn_type) + [
                'unique_code' => $this->encryptWithBase64($unique),
            ];

        return $this->requestSigned($data);
    }

    /**
     * 查询签约
     *
     * txn_type = 03
     */
    public function querySign(array $params, $txn_type = '03')
    {
        Validator::validateRequiredFields($params, ['num_id','card_no']);

        $data = $this->buildBaseData($params['num_id'], $txn_type) + [
                'acc_no' => $this->encryptWithBase64($params['card_no']),
            ];

        return $this->requestSigned($data);
    }

    /**
     * 静默签约
     * 先查询是否已签约，若存在协议号则直接扣款
     */
    public function silentSign(array $params)
    {
        $res = $this->querySign($params);

        if (empty($res)) {
            return null;
        }

        $agreementNo = is_string($res)
            ? explode('|', $res)[0] ?? null
            : ($res['protocols'] ?? null);

        if (!$agreementNo) {
            return null;
        }

        $params['agreement_no'] = $agreementNo;

        return $this->deductMoney($params);
    }

    /* =====================================================
     | 支付 / 退款
     * =====================================================*/

    /**
     * 协议扣款
     *
     * txn_type = 08
     */
    public function deductMoney(array $params, $txn_type = '08')
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no','agreement_no','expect_money','num_id'
        ]);

        $data = $this->buildBaseData($params['num_id'], $txn_type) + [
                'trans_id'    => $params['out_trade_no'],
                'protocol_no' => $this->encryptWithBase64($params['agreement_no']),
                'txn_amt'     => $this->amountToCent($params['expect_money']), // 金额转分
                'risk_item'   => json_encode(['goodsCategory'=>'05'], JSON_UNESCAPED_UNICODE),
                'return_url'  => $this->config['callback'],
            ];

        return $this->requestSigned($data);
    }

    /**
     * 退款接口
     *
     * txn_type = 331
     */
    public function commonRefund(array $params, $txn_type = '331')
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no','refund_no','refund_amount'
        ]);

        $content = [
            'terminal_id'     => $this->config['terminal_id'],
            'member_id'       => $this->config['mch_id'],
            'txn_sub_type'    => '09',
            'refund_type'     => '8',
            'trans_id'        => $params['out_trade_no'],
            'refund_order_no' => $params['refund_no'],
            'trans_serial_no' => $params['out_trade_no'].date('YmdHis').rand(100000,999999),
            'refund_reason'   => '退款',
            'refund_amt'      => $this->amountToCent($params['refund_amount']),
            'refund_time'     => date('YmdHis'),
        ];

        $json = json_encode($content, JSON_UNESCAPED_UNICODE);

        // 使用商户私钥加密
        $encrypted = RSAUtil::encryptByPFXFile(
            $json,
            $this->config['private_key'],
            $this->config['key_pwd']
        );

        $payload = [
            'version'      => $this->config['version'],
            'member_id'    => $this->config['mch_id'],
            'terminal_id'  => $this->config['terminal_id'],
            'txn_type'     => $txn_type,
            'txn_sub_type' => '09',
            'data_type'    => 'json',
            'data_content' => $encrypted,
        ];

        $result = Utils::httpCurl($this->config['refund_url'], $payload);

        $decrypted = RSAUtil::decryptByCERFile(
            $result,
            $this->config['public_key']
        );

        return json_decode($decrypted, true);
    }

    /* =====================================================
     | 核心请求流程
     * =====================================================*/

    /**
     * 统一签名并发送请求
     */
    protected function requestSigned(array $data)
    {
        $signed = $this->sign($data);

        return $this->request($signed, $this->config['authorization_url'], __FUNCTION__);
    }

    /**
     * 统一请求入口
     */
    protected function request(array $params, string $url, string $action, string $method = 'post')
    {
        $this->logRequest($action, $params);

        $result = $method === 'post'
            ? Utils::httpCurl($url, $params)
            : Utils::curl_get($url, $params);

        $this->logResponse($action, $result);

        return $this->handleResponse($result);
    }

    /**
     * 验签 + 状态校验
     */
    protected function handleResponse(string $result)
    {
        parse_str($result, $data);

        if (!isset($data['signature'])) {
            throw new MonthPayException('缺少签名参数');
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $signStr = Tools::SortAndOutString($data);
        $sha1 = SHA1Util::Sha1AndHex(urldecode($signStr));

        if (!SignatureUtils::VerifySign(
            $sha1,
            $this->config['public_key'],
            $signature
        )) {
            throw new MonthPayException('验签失败');
        }

        if (($data['resp_code'] ?? null) !== self::RESP_SUCCESS) {
            throw new MonthPayException($data['resp_msg'] ?? '交易失败');
        }

        return $this->decryptResponse($data);
    }

    /**
     * 解密返回业务字段
     */
    protected function decryptResponse(array $data)
    {
        if (!isset($data['dgtl_envlp'])) {
            return $data;
        }

        $aesKey = $this->extractAesKey($data['dgtl_envlp']);

        foreach ($data as $k => $v) {
            if ($k !== 'dgtl_envlp' && $this->isEncryptedField($k)) {
                $data[$k] = base64_decode(
                    AESUtil::AesDecrypt($v, $aesKey)
                );
            }
        }

        return $data;
    }

    /* =====================================================
     | 工具方法
     * =====================================================*/

    /**
     * 构建基础公共参数
     */
    protected function buildBaseData(string $msgId, string $txnType): array
    {
        return [
            'send_time'  => date('Y-m-d H:i:s'),
            'msg_id'     => $msgId,
            'version'    => $this->config['version'],
            'terminal_id'=> $this->config['terminal_id'],
            'txn_type'   => $txnType,
            'member_id'  => $this->config['mch_id'],
            'dgtl_envlp' => $this->digitalEnvelope(),
        ];
    }

    /**
     * 统一签名
     */
    protected function sign(array $data): array
    {
        $str = Utils::SortAndOutString($data);
        $sha1 = SHA1Util::Sha1AndHex(urldecode($str));

        $data['signature'] = SignatureUtils::Sign(
            $sha1,
            $this->config['private_key'],
            $this->config['key_pwd']
        );

        return $data;
    }

    /**
     * 生成数字信封
     */
    protected function digitalEnvelope(): string
    {
        return RSAUtil::encryptByCERFile(
            "01|".$this->config['key'],
            $this->config['public_key']
        );
    }

    /**
     * Base64 后 AES 加密
     */
    protected function encryptWithBase64(string $plain): string
    {
        return AESUtil::AesEncrypt(
            base64_encode($plain),
            $this->config['key']
        );
    }

    /**
     * 从数字信封中提取 AES Key
     */
    protected function extractAesKey(string $env): string
    {
        $decrypted = RSAUtil::decryptByPFXFile(
            $env,
            $this->config['private_key'],
            $this->config['key_pwd']
        );

        return Tools::getAesKey($decrypted);
    }

    /**
     * 金额转分（避免 float 精度问题）
     */
    protected function amountToCent(string $amount): int
    {
        return (int)bcmul($amount, '100', 0);
    }

    /**
     * 判断哪些字段需要解密
     */
    protected function isEncryptedField(string $field): bool
    {
        return in_array($field, [
            'unique_code','protocol_no','protocols'
        ]);
    }
}
