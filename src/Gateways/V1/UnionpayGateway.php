<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\UnionpayRequestBuilder;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;
use Exception;

/**
 * 银联（银杏智能支付）网关
 *
 * 参考文档：《银杏智能支付服务接口接入说明》
 *
 * 接口与认证方式：
 *   - 智能选卡（comb=insurance）→ frontquery  OPEN-ACCESS-TOKEN（需先取 token）
 *   - 拉卡 / 短信 / 签约 / 查询 / 解约 / 支付 / 退款 → qmf/order 或 bills/query
 *                                              OPEN-BODY-SIG（appId+timestamp+nonce+SHA256(body) HmacSHA256）
 *
 * 字段约定（按接入说明）：
 *   - 密文（userNameEnc / certNoEnc / mobileEnc / cardNoEnc / smsVerifyCodeEnc / signIdEnc）
 *     存放纯 hex；索引（A001/B001/C001/D001/E001/F001）由对应 xxxIdx 字段单独上送。
 *   - 拉卡 / 短信 / 签约等签类接口的报文体里**不**带 sign 字段，鉴权走 Header。
 *   - 快捷支付 ncfi.treatPay 的 bankCardNo / signNo 需 Base64 编码。
 */
class UnionpayGateway extends AbstractGateway
{
    /** 产品标识：固定 GINKPAY */
    const PROD_CODE = 'GINKPAY';

    /** 业务入口 URL 类型 */
    const URL_FRONTQUERY = 'frontquery'; // 智能选卡（OPEN-ACCESS-TOKEN）
    const URL_SIGN       = 'sign';       // 签约类（拉卡/短信/签约/查询/解约）→ qmf/order
    const URL_PAY        = 'pay';        // 支付类（快捷/退款）→ bills/query

    /** 智能选卡 respCode（文档 §3.3 响应字段说明） */
    const FRONTQUERY_RESP_SUCCESS        = '00'; // 查询成功
    const FRONTQUERY_RESP_FAIL           = '01'; // 查询失败
    const FRONTQUERY_RESP_NOT_SUPPORTED  = '02'; // 不支持查询
    const FRONTQUERY_RESP_FORMAT_ERROR   = '03'; // 查询要素格式有误
    const FRONTQUERY_RESP_SYS_ERROR      = '04'; // 系统异常

    /** 消息类型常量（对应文档 msgType） */
    const MSG_CARD_PULL        = 'card/pull';
    const MSG_CARD_PULL_QUERY  = 'tran-info/query';
    const MSG_CAPTCHA_GENERATE = 'captcha/generate';
    const MSG_SIGN             = 'sign';
    const MSG_SIGN_QUERY       = 'sign-info/query';
    const MSG_SIGN_CANCEL      = 'sign/cancel';
    const MSG_TREATPAY         = 'ncfi.treatPay';
    const MSG_REFUND           = 'ncfi.refund';
    const MSG_PULL_BANK_INFO   = 'pullbankinfo';   // 拉卡支持银行列表查询
    const MSG_PULL_BANK_LIST   = 'pullbanklist';   // 拉卡支持银行列表查询（queryType）

    /** 签约渠道 */
    const SIGN_CHNL_UNIONPAY = 'CARDLESS';          // 银联无卡快捷
    const SIGN_CHNL_EC       = 'EC';                // 连通
    const SIGN_CHNL_EPCC     = 'EPCC_QUICK_PERSON'; // 网联

    /** acctType 取值（拉卡接口 signInfo.acctType） */
    const DEBIT_CARD = 2;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    const ACCT_TYPE_SMS_DEBIT  = '00'; // captcha-generate 借记银行账户
    const ACCT_TYPE_SMS_CREDIT = '01'; // captcha-generate 贷记银行账户

    /** 设备操作系统 */
    const DEVICE_ANDROID = '1';
    const DEVICE_IOS     = '2';
    const DEVICE_OTHER   = '3';

    /** @var UnionpayRequestBuilder */
    private $builder;

    public function __construct(array $config, string $logPath)
    {
        parent::__construct('unionpay', $config, $logPath);
        $this->builder = new UnionpayRequestBuilder($config, $this->log);
    }

    /**
     * 默认 SM4 加密实现。
     *
     * 本 SDK 不在本地做 SM4 加解密（没有 SM4 key），所有 SM4 加密都走
     * sm4.baigebaodev.com 远端服务（builder 内部走 config['sm4_aes_key'] +
     * config['sm4_encrypt_url']）。
     *
     * 该方法保留仅为兼容历史调用；始终返回 null。
     */
    public static function makeDefaultSm4Encryptor(array $config)
    {
        return null;
    }

    /**
     * 默认终端编码 termNo（8 位）。
     * FAQ #8：termNo 可送真实值或随机值；服务器要求长度固定 8。
     */
    public static function generateTermNo(int $length = 8): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, $max)];
        }
        return $str;
    }

    /**
     * 解析不同业务入口的 URL。
     */
    private function resolveApiUrl(string $type): string
    {
        switch ($type) {
            case self::URL_FRONTQUERY:
                return $this->config['frontquery_url'] ?? '';
            case self::URL_PAY:
                return $this->config['pay_url'] ?? '';
            case self::URL_SIGN:
            default:
                return $this->config['sign_url'] ?? '';
        }
    }

    // ================================================================
    // AbstractGateway 兼容入口
    // ================================================================

    public function h5Sign(array $params) { return $this->captchaGenerate($params); }
    public function wxSign(array $params) { return $this->captchaGenerate($params); }
    public function deductMoney(array $params) { return $this->quickPay($params); }

    // ================================================================
    // 3.3.2.7 拉卡（含多绑）
    // ================================================================

    /**
     * 3.3.2.7 拉卡（含多绑）
     *
     * msgType = card/pull
     * 鉴权：OPEN-BODY-SIG
     * 接口：POST {sign_url}（默认 ginkgo-auto-insurance / dtums / qmf / order）
     *
     * 请求字段见文档 §5.2 字段说明。报文体**不**带 sign 字段。
     *
     * @param array $params
     *   必填：num_id / bank_no / bank_name / card_type /
     *         t_name / t_paper_type / t_tel / t_paper_num
     */
    public function banksign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'num_id', 'bank_no', 'bank_name', 'card_type',
            't_name', 't_paper_type', 't_tel', 't_paper_num',
        ]);

        try {
            $signInfo = [
                'termType'         => $params['termType'] ?? '08',
                'sourceIp'         => $params['sourceIp'] ?? Utils::ip(),
                'devId'            => $_SERVER['HTTP_USER_AGENT'] ?? '未知',
                'deviceSystem'     => $params['deviceSystem'] ?? self::DEVICE_OTHER,
                'bankId'           => $params['bank_no'],
                'bankName'         => $params['bank_name'],
                'acctType'         => $params['card_type'] == self::DEBIT_CARD ? '00':'01',
                'userNameIdx'      => 'A001',
                'userNameEnc'      => '',
                'certType'         => '01',
                'certNoIdx'        => 'C001',
                'certNoEnc'        => '',
                'mobileIdx'        => 'D001',
                'mobileEnc'        => '',
                'successReturnUrl' => $this->config['return_url'].$params['num_id'],
                'failReturnUrl'    => $this->config['return_url'],
                'orderId'          => $params['num_id'],
                'userRegisterDate' => date('Ymd'),
                'userLoginType'    => $params['userLoginType'] ??'10000000',
            ];

            $data = [
                'prodCode' => self::PROD_CODE,
                'signChnl' => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo' => $signInfo,
                'msgType'  => self::MSG_CARD_PULL,
            ];

            $encryptMap = [
                'userNameEnc' => ['idx' => 'A001', 'value' => $params['t_name']],
                'certNoEnc'   => ['idx' => 'C001', 'value' => $params['t_paper_num']],
                'mobileEnc'   => ['idx' => 'D001', 'value' => $params['t_tel']],
            ];
            return $this->builder->sendWithBodySig(
                $data,
                $encryptMap,
                'cardPull',
                $this->resolveApiUrl(self::URL_SIGN)
            );
        } catch (Exception $e) {
            $this->log->error('拉卡失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.2.1 签约短信验证码生成
    // ================================================================

    /**
     * 3.3.2.1 签约短信验证码生成
     *
     * msgType = captcha/generate
     * signStatus = "Y"（合规要求：总是重新签约）
     *
     * @param array $params
     *   必填：card_no / cert_type / t_paper_num / t_name / tel /
     *         bank_no / bank_name
     *   选填：acct_type（默认 '00'）/ sign_status（默认 'Y'）
     */
    public function captchaGenerate(array $params)
    {
        Validator::validateRequiredFields($params, [
            'card_no', 'cert_type', 't_paper_num', 't_name', 't_tel',
//            'bank_no', 'bank_name',
        ]);

        try {
            $signInfo = [
                'acctType'    => $params['acct_type'] ?? self::ACCT_TYPE_SMS_DEBIT,
                'cardNoEnc'   => '', 'cardNoIdx'   => 'B001',
                'userNameEnc' => '', 'userNameIdx' => 'A001',
                'certType'    => $params['cert_type'],
                'certNoEnc'   => '', 'certNoIdx'   => 'C001',
                'mobileEnc'   => '', 'mobileIdx'   => 'D001',
//                'bankId'      => $params['bank_no'],
//                'bankName'    => $params['bank_name'],
            ];

            $data = [
                'prodCode'   => self::PROD_CODE,
                'signChnl'   => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo'   => $signInfo,
                'signStatus' => $params['sign_status'] ?? 'N',
                'msgType'    => self::MSG_CAPTCHA_GENERATE,
            ];

            $encryptMap = [
                'cardNoEnc'   => ['idx' => 'B001', 'value' => $params['card_no']],
                'userNameEnc' => ['idx' => 'A001', 'value' => $params['t_name']],
                'certNoEnc'   => ['idx' => 'C001', 'value' => $params['t_paper_num']],
                'mobileEnc'   => ['idx' => 'D001', 'value' => $params['t_tel']],
            ];

            return $this->builder->sendWithBodySig(
                $data,
                $encryptMap,
                'captchaGenerate',
                $this->resolveApiUrl(self::URL_SIGN)
            );
        } catch (Exception $e) {
            $this->log->error('签约短信验证码生成失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.2.2 签约
    // ================================================================

    /**
     * 3.3.2.2 签约
     *
     * msgType = sign
     *
     * @param array $params
     *   必填：card_no / cert_type / t_paper_num / t_name / tel / code
     *   选填：associate_info / associate_ssn（captcha 返回）
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, [
            'card_no', 'cert_type', 't_paper_num', 't_name', 't_tel',
            'code',
        ]);

        try {
            $signInfo = [
                'cardNoEnc'         => '', 'cardNoIdx'         => 'B001',
                'userNameEnc'       => '', 'userNameIdx'       => 'A001',
                'certType'          => $params['cert_type'],
                'certNoEnc'         => '', 'certNoIdx'         => 'C001',
                'mobileEnc'         => '', 'mobileIdx'         => 'D001',
                'smsVerifyCodeEnc'  => '', 'smsVerifyCodeIdx'  => 'E001',
                'associateInfo'     => $params['associate_info'] ?? '',
                'associateTermSsn'  => $params['associate_ssn'] ?? '',
            ];

            $data = [
                'prodCode' => self::PROD_CODE,
                'signChnl' => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo' => $signInfo,
                'msgType'  => self::MSG_SIGN,
            ];

            $encryptMap = [
                'cardNoEnc'        => ['idx' => 'B001', 'value' => $params['card_no']],
                'userNameEnc'      => ['idx' => 'A001', 'value' => $params['t_name']],
                'certNoEnc'        => ['idx' => 'C001', 'value' => $params['t_paper_num']],
                'mobileEnc'        => ['idx' => 'D001', 'value' => $params['t_tel']],
                'smsVerifyCodeEnc' => ['idx' => 'E001', 'value' => $params['code']],
            ];

            return $this->builder->sendWithBodySig(
                $data,
                $encryptMap,
                'signingEndpoint',
                $this->resolveApiUrl(self::URL_SIGN),
                null,
                null,
                true
            );
        } catch (Exception $e) {
            $this->log->error('签约失败：' . $e->getMessage());
            $channelCode = $e instanceof MonthPayException ? $e->getChannelCode() : null;
            $response    = $e instanceof MonthPayException ? $e->getResponse() : null;
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e, $channelCode, $response);
        }
    }
    // ================================================================
    // 3.3.2.5 签约信息查询
    // ================================================================

    /**
     * 3.3.2.5 签约信息查询
     *
     * msgType = sign-info/query
     *
     * @param array $params
     *   必填：card_no
     */
    public function querySign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'card_no',
        ]);

        try {
            $signInfo = [
                'cardNoEnc' => '',
                'cardNoIdx' => 'B001',

            ];

            $data = [
                'prodCode' => self::PROD_CODE,
                'signChnl' => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo' => $signInfo,
                'msgType'  => self::MSG_SIGN_QUERY,
            ];

            $encryptMap['cardNoEnc'] = ['idx' => 'B001', 'value' => $params['card_no']];
            return $this->builder->sendWithBodySig(
                $data,
                $encryptMap,
                'querySign',
                $this->resolveApiUrl(self::URL_SIGN),
                null,
                null,
                true
            );
        } catch (Exception $e) {
            $this->log->error('签约信息查询失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.2.8 拉卡结果查询
    // ================================================================

    /**
     * 3.3.2.8 拉卡结果查询
     *
     * msgType = tran-info/query
     * signInfo: relateSsn + tranTm（来自拉卡接口响应）
     *
     * @param array $params
     *   必填：relate_ssn / tran_time
     */
    public function queryCardPullResult(array $params)
    {
        Validator::validateRequiredFields($params, [
            'relate_ssn', 'tran_time',
        ]);

        try {
            $signInfo = [
                'relateSsn' => $params['relate_ssn'],
                'tranTm'    => $params['tran_time'],
            ];

            $data = [
                'prodCode' => self::PROD_CODE,
                'signChnl' => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo' => $signInfo,
                'msgType'  => self::MSG_CARD_PULL_QUERY,
            ];

            return $this->builder->sendWithBodySig(
                $data,
                [],
                'cardPullQuery',
                $this->resolveApiUrl(self::URL_SIGN),
                null,
                null,
                true
            );
        } catch (Exception $e) {
            $this->log->error('拉卡结果查询失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.2.4 解约
    // ================================================================

    /**
     * 3.3.2.4 解约
     *
     * msgType = sign/cancel
     *
     * @param array $params
     *   必填：trans_time / card_no / cert_type / t_paper_num / t_name
     */
    public function cancelSign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'trans_time', 'card_no',
            'cert_type', 't_paper_num', 't_name',
        ]);

        try {
            $signInfo = [
                'transDateTime' => $params['trans_time'],
                'cardNoEnc'     => '', 'cardNoIdx' => 'B001',
                'mobileIdx'     => 'D001',
                'userNameIdx'   => 'A001',
                'certType'      => $params['cert_type'],
                'certNoEnc'     => '', 'certNoIdx' => 'C001',
            ];

            $data = [
                'prodCode' => self::PROD_CODE,
                'signChnl' => $this->config['signChnl'] ?? self::SIGN_CHNL_UNIONPAY,
                'signInfo' => $signInfo,
                'msgType'  => self::MSG_SIGN_CANCEL,
            ];

            $encryptMap = [
                'cardNoEnc' => ['idx' => 'B001', 'value' => $params['card_no']],
                'certNoEnc' => ['idx' => 'C001', 'value' => $params['t_paper_num']],
            ];

            return $this->builder->sendWithBodySig(
                $data,
                $encryptMap,
                'cancelSign',
                $this->resolveApiUrl(self::URL_SIGN),
                null,
                null,
                true
            );
        } catch (Exception $e) {
            $this->log->error('解约失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.2.6 拉卡支持银行列表查询
    // ================================================================

    /**
     * 3.3.2.6 拉卡支持银行列表查询
     *
     * @param array $params
     *   选填：bank_name / chnl / card_type
     */
    public function queryPayBankList(array $params = [])
    {
        try {
            $data = [
                'queryType' => self::MSG_PULL_BANK_LIST,
                'msgType'   => self::MSG_PULL_BANK_INFO,
            ];
            if (!empty($params['bank_name'])) {
                $data['bankName'] = $params['bank_name'];
            }
            if (!empty($params['chnl'])) {
                $data['chnl'] = $params['chnl'];
            }
            if (!empty($params['card_type'])) {
                $data['cardType'] = $params['card_type'];
            }

            return $this->builder->sendWithBodySig(
                $data,
                [],
                'queryPayBankList',
                $this->resolveApiUrl(self::URL_SIGN)
            );
        } catch (Exception $e) {
            $this->log->error('拉卡支持银行列表查询失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.4.1 快捷支付 (ncfi.treatPay)
    // ================================================================

    /**
     * 3.3.4.1 快捷支付
     *
     * msgType = ncfi.treatPay
     * 鉴权：OPEN-BODY-SIG（用 pay_app_id / pay_app_key）
     * 接口：POST {pay_url}（默认 dtums/bills/query）
     *
     * 报文体不带 sign / signType（鉴权走 Header）。
     * 响应里会有 sign 字段（验签用——本 SDK 不做）。
     *
     * @param array $params
     *   必填：out_trade_no / expect_money / agreement_no / bank_card_no
     *   选填：request_time（默认当前时间）/ mid / tid / instMid /
     *         termType（默认 'PHONE'）/ bizType（默认 '100003'）/
     *         pay_callback
     */
    public function quickPay(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no', 'expect_money', 'agreement_no','card_no',
        ]);

        try {
            $data = [
                'requestTimestamp' => $params['request_time'] ?? date('Y-m-d H:i:s'),
                'mid'              => $params['mid'] ?? ($this->config['mid'] ?? ''),
                'tid'              => $params['tid'] ?? ($this->config['tid'] ?? ''),
                'instMid'          => $params['instMid'] ?? 'TREATPAY',
                'merOrderId'       => $params['out_trade_no'],
                'bankCardNo'       => base64_encode((string)$params['card_no']),
                'totalAmount'      => (string)$params['expect_money'],
                'signNo'           => base64_encode((string)$params['agreement_no']),
                'termType'         => $params['termType'] ?? 'PHONE',
                'bizType'          => $params['bizType'] ?? '120004',//保险选购
                'msgType'          => self::MSG_TREATPAY,
                'notifyUrl'        => $params['pay_callback'] ?? ($this->config['pay_callback'] ?? ''),
            ];
            return $this->builder->sendWithBodySig(
                $data,
                [],
                'quickPay',
                $this->resolveApiUrl(self::URL_PAY),
                (string)($this->config['pay_app_id']  ?? $this->config['app_id'] ?? ''),
                (string)($this->config['pay_app_key'] ?? $this->config['app_key'] ?? '')
            );
        } catch (Exception $e) {
            $this->log->error('快捷支付失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.4.4 退款 (ncfi.refund)
    // ================================================================

    /**
     * 3.3.4.4 退款
     *
     * msgType = ncfi.refund
     * 接口：POST {pay_url}（bills/query）
     *
     * @param array $params
     *   必填：out_trade_no / refund_amount
     *   选填：request_time（默认当前时间）/ mid / tid / instMid /
     *         termType（默认 'PHONE'）
     */
    public function refund(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no', 'refund_amount',
        ]);

        try {
            $data = [
                'requestTimestamp' => $params['request_time'] ?? date('Y-m-d H:i:s'),
                'mid'              => $params['mid'] ?? ($this->config['mid'] ?? ''),
                'tid'              => $params['tid'] ?? ($this->config['tid'] ?? ''),
                'instMid'          => $params['instMid'] ?? 'TREATPAY',
                'merOrderId'       => $params['out_trade_no'],
                'msgType'          => self::MSG_REFUND,
                'refundAmount'     => (string)$params['refund_amount'],
                'termType'         => $params['termType'] ?? 'PHONE',
            ];

            return $this->builder->sendWithBodySig(
                $data,
                [],
                'refund',
                $this->resolveApiUrl(self::URL_PAY),
                (string)($this->config['pay_app_id']  ?? $this->config['app_id'] ?? ''),
                (string)($this->config['pay_app_key'] ?? $this->config['app_key'] ?? '')
            );
        } catch (Exception $e) {
            $this->log->error('退款失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    // ================================================================
    // 3.3.3.1 智能选卡（OPEN-ACCESS-TOKEN 鉴权）
    // ================================================================

    /**
     * 3.3.3.1 智能选卡交易接口
     *
     * 鉴权：OPEN-ACCESS-TOKEN（accessToken 由内部自动获取并缓存到调用结束）
     * 接口：POST {frontquery_url}（默认 datacenter / ginkgoleaf / sandbox / frontquery）
     *
     * 请求字段见文档 §3.2 字段说明：key.certType, key.certNo（SHA256, encryption=1）,
     * key.authFlag=1, key.userId, key.comb=insurance, key.orgCusSendID, key.protocolNo。
     *
     * @param array $params
     *   必填：user_id / org_send_id / protocol_no / t_paper_num（明文，会内部 SHA256）
     *   选填：cert_type（默认 01，居民身份证）
     */
    public function smartCardSelect(array $params)
    {
        Validator::validateRequiredFields($params, [
            'user_id', 'org_send_id', 'protocol_no', 't_paper_num',
        ]);

        $certType = $params['cert_type'] ?? '01';
        if (!in_array($certType, ['01', '02', '03', '04', '05'], true)) {
            throw new MonthPayException("cert_type 取值非法：{$certType}，应为 01-05");
        }
        $certNo = (string)$params['t_paper_num'];
        if (!preg_match('/^\d{15}(\d{2}[0-9Xx])?$/', $certNo)) {
            throw new MonthPayException('t_paper_num 必须为 15/18 位身份证号（待传入 SHA256 明文）');
        }

        try {
            $key = [
                'certType'     => $certType,
                'certNo'       => strtolower(hash('sha256', $certNo)),
                'authFlag'     => '1',
                'userId'       => $params['user_id'],
                'comb'         => 'insurance',
                'orgCusSendID' => $params['org_send_id'],
                'encryption'   => '1',
                'protocolNo'   => $params['protocol_no'],
            ];
            $data = ['key' => $key];

            return $this->builder->sendWithAccessToken(
                $data,
                'smartCardSelect',
                $this->resolveApiUrl(self::URL_FRONTQUERY)
            );
        } catch (Exception $e) {
            $this->log->error('智能选卡失败：' . $e->getMessage());
            $channelCode = $e instanceof MonthPayException ? $e->getChannelCode() : null;
            $response    = $e instanceof MonthPayException ? $e->getResponse() : null;
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e, $channelCode, $response);
        }
    }

    /**
     * 智能选卡 respCode 转语义字符串（文档 §3.3 响应字段说明）。
     *
     * @param string|null $respCode
     * @return string|null
     */
    public static function explainFrontQueryRespCode(?string $respCode): ?string
    {
        static $map = [
            self::FRONTQUERY_RESP_SUCCESS        => '查询成功',
            self::FRONTQUERY_RESP_FAIL           => '查询失败',
            self::FRONTQUERY_RESP_NOT_SUPPORTED  => '不支持查询',
            self::FRONTQUERY_RESP_FORMAT_ERROR   => '查询要素格式有误',
            self::FRONTQUERY_RESP_SYS_ERROR      => '系统异常',
        ];
        return $respCode === null ? null : ($map[$respCode] ?? ('未知 respCode=' . $respCode));
    }

    /**
     * 从智能选卡响应中提取 combiLabels（每张卡的 sm4_card / sm4_mobile / card / bank / card_tag）。
     *
     * @param array $resp smartCardSelect 返回的响应数组
     * @return array<int, array> 索引数组，每项含五个键（文档 §3.3 响应字段）
     */
    public static function extractFrontQueryCards(array $resp): array
    {
        return is_array($resp['combiLabels'] ?? null) ? array_values($resp['combiLabels']) : [];
    }

    // ================================================================
    // 业务辅助
    // ================================================================

    /** 重新发起短信验证码（同 captchaGenerate） */
    public function resendSms(array $params) { return $this->captchaGenerate($params); }
}