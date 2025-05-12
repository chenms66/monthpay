<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\HttpClient;
use BaiGe\MonthPay\Validator\Validator;

class BankGateway extends AbstractGateway
{
    private $token;
    # Card types
    const DEBIT_CARD = 0;  # 借记卡
    const CREDIT_CARD = 1;  # 信用卡

    public function __construct(array $config, string $logPath)
    {
        parent::__construct('bank', $config, $logPath);
        $this->token = $this->getToken();
    }

    public function h5Sign(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'num_id', 'txn_type', 't_name', 't_paper_num', 't_tel', 'bank_name']);
        return $this->cardRequest($params);
    }

    public function wxSign(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'num_id', 'txn_type', 't_name', 't_paper_num', 't_tel', 'bank_name']);
        return $this->cardRequest($params);
    }


    /**
     * @param array $params
     * @return bool|string
     * 执行扣款
     */
    public function deductMoney(array $params)
    {
        Validator::validateRequiredFields($params, ['num_id','txn_type','policy_money','binding_no']);
        $data = [
            'clientOrderId' => $params['num_id'],//商户订单号
            'txnType' => $params['txn_type'],//业务类型
            'sceneType' => 'BANK_H5',//交易场景
            'tradeType' => 'BANK_H5',//支付方式
            'body' => $params['body'] ?? '',//商品描述
            'remark' => $params['remark'] ?? '',//备注
            'expireTime' => $params['expire_time'] ?? '',//超时时间 单位分钟
            'transAmount' => $params['policy_money'],//交易金额 分
            'channelCode' => $this->config['channel_code'],//交易渠道
            'clientNotifyUrl' => $this->config['client_notify_pay_url'],//异步回调通知地址
            'frontNotifyUrl' => $this->config['front_notify_url'],//绑卡完成同步请求地址
            'merchantCode' => $this->config['merchant_code'],//商户号
            'attach' => $params['attach'] ?? '',//附加参数
            'locationCityName' => $params['location_city_name'] ?? '',//商户所在城市
            'bindingNo' => $params['binding_no'] ?? '',//绑卡序列号
            'smsVerification' => $params['smsVerification'] ?? false,//不需要的话这个值传false
            'openId' => $params['open_id'] ?? ''
        ];
        return $this->publicRequest($data, $this->config['pay_url'], 'deductMoney');
    }

    /**
     * @param array $params
     * @return bool|string|null
     * 请求绑卡
     * @throws MonthPayException
     */
    public function cardRequest(array $params)
    {
        if (isset($params['card_type']) && $params['card_type'] == self::CREDIT_CARD) {
            throw new MonthPayException("信用卡不需要绑卡");
        }
        return $this->bindCardRequest($params);
    }

    /**
     * @param array $params
     * @return bool|string|void
     * 绑卡申请
     */
    public function bindCardRequest(array $params)
    {
        Validator::validateRequiredFields($params, ['num_id','txn_type','t_name','t_paper_num','t_tel','bank_name']);
        $data = [
            'clientOrderId' => $params['num_id'],//商户订单号
            'txnType' => $params['txn_type'],//业务类型
            'channelCode' => $this->config['channel_code'],//渠道编码
            'familyName' => $params['t_name'],//持卡人姓名
            'idCard' => $params['t_paper_num'],//证件号码
            'mobile' => $params['t_tel'],//银行预留手机号码
            'bankName' => $params['bank_name'],//银行名称
            'bankId' => '',//联行号 对公必填
            'bankCode' => '',//	银行代码 对公必填
            'cardType' => $params['card_type'] ?? '0',//银行卡类型 借记卡：0 信用卡：1
            'bindingType' => $params['binding_type'] ?? '1',//绑定类型 收款用户：0 付款用户：1
            'payeeType' => $params['payee_type'] ?? '1',//结算类型 对公：0 对私：1 ，对公只需要绑卡申请，不需要确认
            'cv2' => $params['cv2'] ?? '',//安全码 一键绑卡可不传
            'expireDate' => $params['expire_date'] ?? '',//有效期 一键绑卡可不传
            'clientNotifyUrl' => $this->config['client_notify_url'],//异步回调通知地址
            'frontNotifyUrl' => $this->config['front_notify_url'],//绑卡完成同步请求地址
            'bindingWay' => $params['binding_way'] ?? '2',//绑卡方式 静默绑卡:0 短信绑卡:1 一键绑卡:2
        ];
        return $this->publicRequest($data, $this->config['url'], 'bindCardRequest');
    }

    /**
     * @param array $param
     * @return bool|string|void
     * 解除绑卡
     */
    public function commonCancel(array $param)
    {
        Validator::validateRequiredFields($param, ['bank_no']);
        $data = [
            'bindingId' => $params['binding_id'] ?? '',//绑卡标识
            'channelCode' => $this->config['channel_code'],//交易渠道
            'bankNo' => $param['bank_no'],//银行卡号
            'bindingNo' => $params['binding_no'] ?? '',//绑卡序列号
        ];
        return $this->publicRequest($data, $this->config['cancel_url'], 'commonCancel');
    }

    /**
     * @param array $param
     * @return bool|string|void
     * 退款
     */
    public function commonRefund(array $param)
    {
        Validator::validateRequiredFields($param, ['server_order_id','refund_amount']);
        $data = [
            'serverOrderId' => $param['server_order_id'],//平台订单号
            'clientLedgerOrderId' => $params['client_ledger_order_id'] ?? '',//商户分账订单号
            'ledgerOrderId' => $params['ledger_order_id'] ?? '',//平台分账订单号
            'refundAmount' => $param['refund_amount'],//退款金额
            'clientNotifyUrl' => $this->config['client_notify_refund_url'],//异步回调通知地址
            'ledgerRelation' => $param['ledger_relation'] ?? '',//分账交易关系组
        ];
        return $this->publicRequest($data, $this->config['refund_url'], 'commonRefund');
    }

    /**
     * @param $param
     * @param $url
     * @param $action
     * @return bool|string
     * 公共请求
     */
    public function publicRequest($param, $url, $action)
    {
        $this->logRequest($action, $param);
        $result = $this->httpCurl($url, $param, $this->token);
        $this->logResponse($action, $result);
        return $result;
    }


    /**
     * @return mixed
     * 获取token
     * @throws MonthPayException
     */
    public function getToken()
    {
        $param = [
            'client_id' => $this->config['client_id'],//应用ID
            'client_secret' => $this->config['client_secret'],//应用秘钥
            'grant_type' => 'client_credentials',//grant_type
            'scope' => 'snsapi_openid',//作用域
        ];
        $this->logRequest('getToken', $param);
        $result = $this->httpCurls($this->config['token_url'], $param);
        $this->logResponse('getToken', $result);
        $result = json_decode($result, true);
        if (empty($result['access_token'])) {
            throw new MonthPayException("token获取失败");
        }
        return $result;
    }

    public function httpCurls($url, $data)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        curl_setopt($curl, CURLOPT_TIMEOUT, 30); // 设置超时限制防止死循环
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }

    /**
     * http请求
     * @param $url
     * @param $data
     * @return bool|string
     */
    public function httpCurl($url, $data, $token)
    {
        $headers = array(
            "Content-Type:application/json;charset=utf-8",
            "Accept:application/json;charset=utf-8",
            "Authorization:" . $token['token_type'] . ' ' . $token['access_token'],
        );
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        if (!empty($data)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, 256));
        }
        curl_setopt($curl, CURLOPT_TIMEOUT, 3600 * 5);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return $output;
    }
}
