<?php

namespace BaiGe\MonthPay;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\V1\BankGateway;
use BaiGe\MonthPay\Gateways\V1\WechatGateway;
use BaiGe\MonthPay\Validator\Validator;

class MonthPay
{
    private  $gateway;

    public function __construct(string $channel, array $config, $logPath = '')
    {
        switch ($channel ?? '') {
            case 'wechat':
                $this->gateway = new WechatGateway($config ?? [],$logPath);
                break;
            case 'bank':
                $this->gateway = new BankGateway($config ?? [],$logPath);
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
    public function wxSign(array $params)
    {
        try {
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
    public function preDeduct(array $params)
    {
        try {
            return $this->gateway->preDeduct($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string
     * @throws MonthPayException
     * 扣款
     */
    public function deductMoney(array $params)
    {
        try {
            return $this->gateway->deductMoney($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string|null
     * @throws MonthPayException
     * 银行卡绑卡
     */
    public function cardBankRequest(array $params){
        try {
            return $this->gateway->cardRequest($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string|null
     * @throws MonthPayException
     * 银行卡解绑
     */
    public function commonBankCancel(array $params){
        try {
            return $this->gateway->commonCancel($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return bool|string|null
     * @throws MonthPayException
     * 银行卡退款
     */
    public function commonBankRefund(array $params){
        try {
            return $this->gateway->commonRefund($params);
        } catch (MonthPayException $e) {
            throw new MonthPayException($e->getMessage());
        }
    }


    public function gateway()
    {
        return $this->gateway;
    }
}
