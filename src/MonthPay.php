<?php

namespace BaiGe\MonthPay;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\V1\WechatGateway;
use BaiGe\MonthPay\Validator\Validator;

class MonthPay
{
    private  $gateway;

    public function __construct(string $channel, array $config, string $logPath)
    {
        switch ($channel ?? '') {
            case 'wechat':
                $this->gateway = new WechatGateway($config ?? [],$logPath ?? '');
                break;
            default:
                throw new MonthPayException("渠道不存在");
        }
    }

    /**
     * @param array $params
     * @return null
     * @throws MonthPayException
     * 微信h5签约
     */
    public function h5Sign(array $params)
    {
        try {
            // 校验必填字段
            Validator::validateRequiredFields($params, ['out_contract_code','b_name','s_time','e_time','renewal_status','withhold_log','plan_id']);

            return $this->gateway->h5Sign($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }


    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 微信内置签约
     */
    public function wxSign(array $params): bool|string
    {
        try {
            // 校验必填字段
            Validator::validateRequiredFields($params, ['out_contract_code','b_name','s_time','e_time','renewal_status','withhold_log','plan_id','openid']);

            return $this->gateway->wxSign($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 微信预扣款
     */
    public function preDeduct(array $params): bool|string
    {
        try {
            // 校验必填字段
            Validator::validateRequiredFields($params, ['expect_money','agreement_no','period']);

            return $this->gateway->preDeduct($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 微信预扣款
     */
    public function deductMoney(array $params): bool|string
    {
        try {
            // 校验必填字段
            Validator::validateRequiredFields($params, ['out_trade_no','agreement_no','period','expect_money']);

            return $this->gateway->deductMoney($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    public function gateway()
    {
        return $this->gateway;
    }
}
