<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\HttpClient;
use BaiGe\MonthPay\Validator\Validator;

class WechatGateway extends AbstractGateway
{

    const URL_H5_SIGN             = 'https://api.mch.weixin.qq.com/v3/papay/insurance-sign/contracts/pre-entrust-sign/h5';//h5签约地址
    const URL_WX_SIGN             = 'https://api.mch.weixin.qq.com/v3/papay/insurance-sign/contracts/pre-entrust-sign/jsapi';//微信签约地址
    const URL_SIGN_LIST           = 'https://api.mch.weixin.qq.com/v3/papay/insurance-sign/policy_periods/plan-id/';////保险商户查询保险扣费周期列表API
    const URL_RELIEVE_APPOINT     = 'https://api.mch.weixin.qq.com/v3/papay/insurance-sign/contracts/plan-id/';//解约地址
    const URL_WITHHOLDING         = 'https://api.mch.weixin.qq.com/v3/papay/insurance-pay/policy-periods/contract-id/';////预扣款
    const URL_DEDUCT_MONEY        = 'https://api.mch.weixin.qq.com/v3/papay/insurance-pay/transactions/apply';////扣款接口

    private $httpClient;
    public function __construct(array $config, string $logPath)
    {
        parent::__construct('wechat', $config, $logPath);
        $this->httpClient = new HttpClient($this->config);
    }

    /**
     * @param $params
     * @return array
     * 公共信息
     */
    public function commParam($params,$is_wx = false){
        $data =  [
            'out_contract_code'=>$params['out_contract_code'],//【商户签约协议号】 商户侧的签约协议号，商户侧需保证唯一性。只能是数字、大小写字母的组合
            'insured_display_name'=>$this->maskInsuredName($params['b_name']),//【被保人的展示名称】 保险被保人的姓名掩码，由商户传入，用于在签约页面展示。如为个人保单，则只保留最后一个字符，除最后一个字符外全部字符（包括“.”等符号）均使用替代；如姓名长度大于4个字符，统一用3个“”+1位明文展示，如***G，如为家庭或者团体保单，传入的格式为“多人保单-**明等”。固定前缀为“多人保单-”，后面为投保人代表的姓名掩码及“等”字
            'policy_start_date'=>substr($params['s_time'],0,10),//【保险保单的开始日期】 保险保单的开始日期 ，遵循rfc3339标准格式，格式为yyyy-MM-DD，yyyy-MM-DD表示年月日，例如：2015-05-20表示，北京时间2015年5月20日。
            'policy_end_date'=>substr($params['e_time'],0,10),//【保险保单的结束日期】 保险保单的结束时间，遵循rfc3339标准格式，格式为yyyy-MM-DD，yyyy-MM-DD表示年月日，例如：2015-05-20表示，北京时间2015年5月20日。
            'out_user_code'=>$params['out_user_code'],
            'policy_periods'=>$params['withhold_log'],
            'description'=>'保险商品代扣',//【商品描述】 若商户希望在进行签约后立即进行首期自动续费，必须传入商品描述。
            'can_auto_reinsure'=> $params['renewal_status'],//【是否自动重新投保】 指扣费计划到期后，商家按照最新费率为用户重新投保，不保证投保成功，投保成功后将生成新的保单号。是否自动续保与是否自动重新投保不能同时设置。
            'plan_id'=>(int)$params['plan_id'],
            'contract_notify_url'=>$this->config['notify_url'],
            'transaction_notify_url'=>$this->config['callback'],
            'appid'=>$this->config['appid']
        ];
        if(isset($params['out_trade_no']) && !empty($params['out_trade_no'])){
            $data['out_trade_no'] = (string)$params['out_trade_no'];////【商户订单号】 若商户希望在进行签约后立即进行首期自动续费，必须传入商户系统内部订单号。只能是数字、大小写字母_-*且在同一个商户号下唯一
        }
        if(isset($params['expect_money']) && !empty($params['expect_money'])){////【扣费金额信息】 若商户希望在进行签约后立即进行首期自动续费，必须传入扣费金额信息，必须等于首个扣费周期的的预约金额。
            $data['amount']['total'] = $this->yuanToCent($params['expect_money']);
        }
        if($is_wx){
            $data['openid'] = $params['openid'];
        }
        return $data;
    }

    /**
     * @param array $params
     * @return mixed
     * 微信H5签约
     */
    public function h5Sign(array $params)
    {
        Validator::validateRequiredFields($params, ['out_contract_code','b_name','s_time','e_time','renewal_status','withhold_log','plan_id','openid']);
        $params = $this->commParam($params);
        $this->logRequest('h5Sign', $params);
        $response = $this->httpClient->post(self::URL_H5_SIGN,$params);
        $this->logResponse('h5Sign', $response);
        return json_decode($response, true);
    }

    /**
     * @param array $params
     * @return bool|string
     * 微信内置签约
     */
    public function wxSign(array $params)
    {
        Validator::validateRequiredFields($params, ['out_contract_code','b_name','s_time','e_time','renewal_status','withhold_log','plan_id','openid']);
        $params = $this->commParam($params,true);
        $this->logRequest('wxSign', $params);
        $response = $this->httpClient->post(self::URL_WX_SIGN,$params);
        $this->logResponse('wxSign', $response);
        return json_decode($response, true);
    }

    /**
     * @param array $params
     * @return bool|string
     * 微信预扣款
     */
    public function preDeduct(array $params){
        Validator::validateRequiredFields($params, ['expect_money','agreement_no','period']);
        $data = [
            'appid'=>$this->config['appid'],
            'scheduled_amount'=>[
                'total'=>$this->yuanToCent($params['expect_money']),
            ]
        ];
        $url = self::URL_WITHHOLDING.$params['agreement_no'].'/policy-period-id/'.$params['period'].'/schedule';
        $this->logRequest('preDeduct', $data);
        $response = $this->httpClient->post($url, $data);
        $this->logResponse('preDeduct', $response);
        return json_decode($response, true);
    }


    /**
     * @param array $params
     * @return bool|string
     * 微信执行扣款
     */
    public function deductMoney(array $params){
        Validator::validateRequiredFields($params, ['out_trade_no','agreement_no','period','expect_money']);
        $data = [
            'appid'=>$this->config['appid'],
            'out_trade_no'=>$params['out_trade_no'],
            'description'=>'保险扣款',
            'transaction_notify_url'=>$this->config['callback'],
            'contract_id'=>$params['agreement_no'],
            'policy_period_id'=>$params['period'],
            'amount'=>[
                'total'=>$this->yuanToCent($params['expect_money']),
            ]
        ];
        $this->logRequest('deductMoney', $data);
        $return_info = $this->httpClient->post(self::URL_DEDUCT_MONEY, $data);
        $this->logResponse('deductMoney', $return_info);
        return $return_info;
    }

    /**
     * @param $param array 请求参数
     * @param $url string 请求地址
     * @param $action string 请求方法
     * @return mixed
     * 公共请求
     */
    public function publicRequest(array $param, string $url, string $action){
        $this->logRequest($action, $param);
        $return_info = $this->httpClient->post($url, $param);
        $this->logResponse($action, $return_info);
        return json_decode($return_info, true);
    }

    private function maskInsuredName($name)
    {
        return '***' . mb_substr($name, -1, 1);
    }

    private function yuanToCent($amount)
    {
        return (int) bcmul($amount, 100);
    }

}
