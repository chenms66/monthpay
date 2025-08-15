<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

class DxmGateway extends AbstractGateway
{
    # Card types
    const DEBIT_CARD = 0;  # 借记卡
    const CREDIT_CARD = 1;  # 信用卡

    public function __construct(array $config, string $logPath)
    {
        parent::__construct('dxm', $config, $logPath);
    }

    public function h5Sign(array $params)
    {
        return $this->authEndpoint($params);
    }

    public function wxSign(array $params)
    {
        return $this->authEndpoint($params);
    }
    /**
     * @param array $params
     * @return bool|string|void
     * 鉴权接口,发送短信
     */
    public function authEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->comm($params);
        $data['sign'] = $this->sign($data);
        return $this->publicRequest($data, $this->config['authorization_url'], 'authEndpoint');
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 请求签约
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 'code', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->comm($params);
        $data['sms_vcode'] = $params['code'];//短信验证码
        $data['sign'] = $this->sign($data);
        return $this->publicRequest($data, $this->config['sign_url'], 'signingEndpoint');
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 签约查询
     */
    public function querySignUrl(array $params)
    {
        Validator::validateRequiredFields($params, ['agreement_no']);
        $data = [
            'contract_no' => $params['agreement_no'],//协议号
            'version' => 1,//版本号,目前固定为1
            'sp_no' => $this->config['sp_no'],
            'currency' => '1',//币种，默认RMB	目前固定为1，RMB
            'need_send_sms' => $params['need_send_sms'] ?? '0',//如果需要再次签约是否直接发短信	0-不允许发短信 1-必须发短信 2-请求不发短信(可能不发短信)默认为1
            'sign_method' => 1,//签名方式	md5 - gbk，固定为1
        ];
        return $this->publicRequest($data, $this->config['query_sign_url'], 'querySignUrl');
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 静默签约
     */
    public function silentSign(array $params)
    {
        Validator::validateRequiredFields($params, ['card_type', 'card_no', 'code', 't_paper_type', 't_name', 't_paper_num', 't_tel']);
        $data = $this->comm($params);
        $data['sign'] = $this->sign($data);
        return $this->publicRequest($data, $this->config['silent_signing_url'], 'silentSign');
    }

    public function comm(array $params)
    {
        return [
            'version' => 1,//版本号,目前必须为1
            'input_charset' => 1,//字符编码,目前固定为1，GBK
            'service_code' => 2,//服务编号	目前固定为2
            'sp_no' => $this->config['sp_no'],
            'card_type' => $params['card_type'] ?? '0',//1-信用卡 2-借记卡
            'id_type' => $params['t_paper_type'],//证件类型	1-身份证 9-外国人永久居留身份证
            'encrypt_method' => 1, //四要素加密方法	 使用AES对用户四要素进行加密，固定为1
            'card_no' => Utils::dxmEncrypt($params['card_no'],$this->config['key']),
            'card_name' => Utils::dxmEncrypt($params['t_name'],$this->config['key']),
            'id_no' => Utils::dxmEncrypt($params['t_paper_num'],$this->config['key']),
            'phone' => Utils::dxmEncrypt($params['t_tel'],$this->config['key']),
            'sign_method' => 1,//签名方式	md5 - gbk，固定为1
        ];
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 请求扣款
     * out_trade_no 代表
     */
    public function deductMoney(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 'agreement_no', 'goods_name', 'expect_money']);
        $data = [
            'version' => 2,//版本号,目前固定为2
            'contract_no' => $params['agreement_no'],//协议号
            'input_charset' => 1,//字符编码,目前固定为1，GBK
            'service_code' => 3,//服务编号	目前固定为3
            'sp_no' => $this->config['sp_no'],
            'return_url' => $this->config['callback'],//回调地址,度小满主动通知商 户地址
            'return_method' => 2,//通知方式1-GET 2-POST 默认GET
            'return_content_type' => 1,//post回调方式	1-json、如果不传默认urlencode
            'order_create_time' => date('YmdHis'),
            'order_no' => $params['out_trade_no'],//订单号	字母+数字最大50位，不支持特殊字符
            'goods_name' => $params['goods_name'],//商品名称	允许包含中文，不超过64个汉字
            'goods_desc' => $params['goods_desc'] ?? '保险商品代扣',//商品描述信息	允许包含中文，不超过127个汉字
            'total_amount' => Utils::yuanToCent($params['expect_money']),//订单金额，以分为 单位	非负整数
            'currency' => '1',//币种，默认RMB	目前固定为1，RMB
            'expire_time' => $params['expire_time'] ?? date('YmdHis', strtotime('+30 minute')),//交易超时时间	YYYYMMDDHHMMSS，不得早于创建时间，超时后订单会从未知状态变为失败，建议超时时间：30min-2h
            'reqip'=>Utils::ip(),//请求IP,
            'sign_method' => 1,//签名方式	md5 - gbk，固定为1
        ];
        $data['sign'] = $this->sign($data);
        return $this->publicRequest($data, $this->config['pay_url'], 'deductMoney');
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 解绑
     */
    public function relieveAppoint(array $params)
    {
        Validator::validateRequiredFields($params, ['agreement_no']);
        $data = [
            'version' => 1,//版本号,目前必须为1
            'contract_no' => $params['agreement_no'],//协议号
            'sp_no' => $this->config['sp_no'],
            'sign_method' => 1,//签名方式	md5 - gbk，固定为1
        ];
        $data['sign'] = $this->sign($data);
        return $this->publicRequest($data, $this->config['cancel_url'], 'relieveAppoint');
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
        $result = Utils::httpCurl($url, $param);
        $this->logResponse($action, $result);
        return $result;
    }

    /**
     * @param $param
     * @param $url
     * @param $action
     * @return array|string
     * get请求
     */
    public function publicGetRequest($param, $url, $action)
    {
        $this->logRequest($action, $param);
        $result = Utils::curl_get($url, $param);
        $this->logResponse($action, $result);
        return $result;
    }

    public function sign(array $params)
    {
        return md5(Utils::_getParamsString($params, $this->config['key']));
    }

    /**
     * @param array $param
     * @return bool|string|void
     * 退款
     */
    public function commonRefund(array $param)
    {
        Validator::validateRequiredFields($param, ['out_trade_no','refund_no','refund_amount']);

        $data = [
            'service_code' => 2,//服务编号	目前固定为2
            'sp_no' => $this->config['sp_no'],
            'cashback_time' => date('YmdHis'),
            'order_no' => $param['out_trade_no'],//订单号	字母+数字最大50位，不支持特殊字符
            'sp_refund_no'=> $param['refund_no'],//最大32位，纯数字
            'cashback_amount'=> Utils::yuanToCent($param['refund_amount']),
            'currency' => '1',//币种，默认RMB	目前固定为1，RMB
            'return_url' => $this->config['pay_callback'],//回调地址,度小满主动通知商 户地址
            'return_method' => 1,//通知方式1-GET 2-POST 默认GET
            'input_charset' => 1,//字符编码,目前固定为1，GBK
            'output_type' => 2,//1 - xml , 2 - json，建议使用Json
            'output_charset' => 2,//响应格式编码	目前固定为2
            'refund_type'=>$param['refund_type'] ?? '2',//退款类型	1 - 退款至度小满余额 2 - 原路退回，默认使用原路退回
            'version' => 2,//版本号	目前固定为2
            'sign_method' => 1,//签名方式	md5 - gbk，固定为1
        ];
        $data['sign'] = $this->sign($data);
        return $this->publicGetRequest($data, $this->config['refund_url'], 'commonRefund');
    }
}
