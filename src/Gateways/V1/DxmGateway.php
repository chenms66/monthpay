<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

class DxmGateway extends AbstractGateway
{
    # ---------------------------
    # 常量定义
    # ---------------------------
    const DEBIT_CARD = 0; // 借记卡
    const CREDIT_CARD = 1; // 信用卡

    const VERSION_V1 = 1;
    const VERSION_V2 = 2;

    const CHARSET_GBK = 1;
    const SIGN_MD5_GBK = 1;
    const CURRENCY_RMB = 1;
    const ENCRYPT_AES = 1;

    const SERVICE_CODE = 1;
    const SERVICE_AUTH = 2;
    const SERVICE_DEDUCT = 3;
    const SERVICE_REFUND = 2;

    public function __construct(array $config, string $logPath)
    {
        parent::__construct('dxm', $config, $logPath);
    }

    # ---------------------------
    # 签约流程
    # ---------------------------

    public function h5Sign(array $params)
    {
        return $this->authEndpoint($params);
    }

    public function wxSign(array $params)
    {
        return $this->authEndpoint($params);
    }

    /**
     * 鉴权接口，发送短信
     */
    public function authEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->buildAuthParams($params);
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['authorization_url'], __FUNCTION__);
    }

    /**
     * 请求签约
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 'code', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->buildAuthParams($params);
        $data['sms_vcode'] = $params['code']; // 短信验证码
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['sign_url'], __FUNCTION__);
    }

    /**
     * 签约查询
     */
    public function querySign(array $params)
    {
        Validator::validateRequiredFields($params, ['agreement_no']);
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_AUTH, self::VERSION_V1),
            [
                'contract_no' => $params['agreement_no'],
                'need_send_sms' => $params['need_send_sms'] ?? '0',
            ]
        );

        return $this->request($data, $this->config['query_sign_url'], __FUNCTION__);
    }

    /**
     * 静默签约
     */
    public function silentSign(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->buildAuthParams($params);
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['silent_signing_url'], __FUNCTION__);
    }

    # ---------------------------
    # 支付 / 退款
    # ---------------------------

    /**
     * 请求扣款
     */
    public function deductMoney(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 'agreement_no', 'goods_name', 'expect_money']);
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_DEDUCT, self::VERSION_V2),
            [
                'contract_no' => $params['agreement_no'],
                'return_url' => $this->config['callback'],
                'return_method' => 2,
                'return_content_type' => 1,
                'order_create_time' => date('YmdHis'),
                'order_no' => $params['out_trade_no'],
                'goods_name' => $params['goods_name'],
                'goods_desc' => $params['goods_desc'] ?? '保险商品代扣',
                'total_amount' => Utils::yuanToCent($params['expect_money']),
                'expire_time' => $params['expire_time'] ?? date('YmdHis', strtotime('+30 minute')),
                'reqip' => Utils::ip(),
            ]
        );
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['pay_url'], __FUNCTION__);
    }

    /**
     * 退款
     */
    public function commonRefund(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 'refund_no', 'refund_amount']);
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_REFUND, self::VERSION_V2),
            [
                'cashback_time' => date('YmdHis'),
                'order_no' => $params['out_trade_no'],
                'sp_refund_no' => $params['refund_no'],
                'cashback_amount' => Utils::yuanToCent($params['refund_amount']),
                'return_url' => $this->config['pay_callback'],
                'return_method' => 1,
                'output_type' => 2,
                'output_charset' => 2,
                'refund_type' => $params['refund_type'] ?? '2',
            ]
        );
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['refund_url'], __FUNCTION__, 'get');
    }

    # ---------------------------
    # 合同管理
    # ---------------------------

    /**
     * 解绑协议
     */
    public function cancelContract(array $params)
    {
        Validator::validateRequiredFields($params, ['agreement_no']);
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_AUTH, self::VERSION_V1),
            ['contract_no' => $params['agreement_no']]
        );
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['cancel_url'], __FUNCTION__);
    }

    # ---------------------------
    # 公共方法
    # ---------------------------

    protected function buildBaseParams(int $serviceCode, int $version = self::VERSION_V1): array
    {
        return [
            'version' => $version,
            'input_charset' => self::CHARSET_GBK,
            'sign_method' => self::SIGN_MD5_GBK,
            'currency' => self::CURRENCY_RMB,
            'sp_no' => $this->config['sp_no'],
            'service_code' => $serviceCode,
        ];
    }

    protected function buildAuthParams(array $params): array
    {
        return array_merge(
            $this->buildBaseParams(self::SERVICE_AUTH, self::VERSION_V1),
            [
                'card_type' => $params['card_type'] ?? self::DEBIT_CARD,
                'id_type' => $params['t_paper_type'],
                'encrypt_method' => self::ENCRYPT_AES,
            ],
            $this->encryptFields($params)
        );
    }

    protected function encryptFields(array $params): array
    {
        return [
            'card_no' => Utils::dxmEncrypt($params['card_no'], $this->config['key']),
            'card_name' => Utils::dxmEncrypt($params['t_name'], $this->config['key']),
            'id_no' => Utils::dxmEncrypt($params['t_paper_num'], $this->config['key']),
            'phone' => Utils::dxmEncrypt($params['t_tel'], $this->config['key']),
        ];
    }

    protected function request(array $params, string $url, string $action, string $method = 'post')
    {
        $this->logRequest($action, $params);
        $result = $method === 'post'
            ? Utils::httpCurl($url, $params)
            : Utils::curl_get($url, $params);
        $this->logResponse($action, $result);
        return $result;
    }

    protected function sign(array $params): string
    {
        return md5(Utils::_getParamsString($params, $this->config['key']));
    }

    /**
     * @param array $params
     * @return array|bool|string
     * @throws MonthPayException
     * 查询支持一键绑卡的银行列表
     */
    public function banksOneClickBind(array $params)
    {
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_AUTH),
            [
                'type' => 0,//1、查询支持绑借记卡的。2、查询支持绑贷记卡类型的。0、查询支持借记卡或贷记卡的
                'pay_amount' => 0,//单位，分，默认传0
            ]
        );
        $data['sign'] = $this->sign($data);

        return $this->request($data, $this->config['banks_one_click_bind'], __FUNCTION__);
    }

    /**
     * @param array $params
     * @return array|bool|string
     * @throws MonthPayException
     * 银行卡签约
     */
    public function bankSign(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 'bank_no', 'card_type', 't_paper_num', 't_paper_type', 't_tel']);
        $data = array_merge(
            $this->buildBaseParams(self::SERVICE_CODE),
            [
                'tran_serial_no' => $params['out_trade_no'],//签约流水号	字母+数字最大50位，不支持特殊字符，串联整个流程，24小时内不可重复。
                'encrypt_method' => self::ENCRYPT_AES,
                'card_name' => Utils::dxmEncrypt($params['t_name'], $this->config['key']),
                'bank_no' => (int)$params['bank_no'],//银行编码	int，查询支持银行列表返回
                'card_type' => $params['card_type'],//卡类型 1、贷记卡、2借记卡
                'certificate_no' => Utils::dxmEncrypt($params['t_paper_num'], $this->config['key']),//身份证号码，使用AES对用户身份要素进加密
                'certificate_type' => $params['t_paper_type'],//目前仅支持身份证，取值固定为1
                'mobile' => Utils::dxmEncrypt($params['t_tel'], $this->config['key']),
                'return_url' => $this->config['callback'],
                'return_method' => 2,
                'page_url' => $this->config['return_url'],//签约成功，页面从银行调回的地址
                'sign_method' => self::SIGN_MD5_GBK,
            ]
        );
        $data['sign'] = $this->sign($data);
        return $this->request($data, $this->config['bank_sign'], __FUNCTION__);
    }

}
