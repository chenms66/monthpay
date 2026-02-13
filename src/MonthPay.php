<?php

namespace BaiGe\MonthPay;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\V1\BankGateway;
use BaiGe\MonthPay\Gateways\V1\BaofuGateway;
use BaiGe\MonthPay\Gateways\V1\DxmGateway;
use BaiGe\MonthPay\Gateways\V1\WechatGateway;
use BaiGe\MonthPay\Validator\Validator;

class MonthPay
{
    public $gateway;

    public function __construct(string $channel, array $config, $logPath = '')
    {
        switch ($channel ?? '') {
            case 'wechat':
                $this->gateway = new WechatGateway($config ?? [],$logPath);
                break;
            case 'bank':
                $this->gateway = new BankGateway($config ?? [],$logPath);
                break;
            case 'dxm':
                $this->gateway = new DxmGateway($config ?? [],$logPath);
                break;
            case 'baofu':
                $this->gateway = new BaofuGateway($config ?? [],$logPath);
                break;
            default:
                throw new MonthPayException("渠道不存在");
        }
    }

    /**
     * @param array $params
     * @return array
     * 微信h5签约
     */
    public function h5Sign(array $params)
    {
        try {
            $result = $this->gateway->h5Sign($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    public function silentsign(array $params)
    {
        try {
            $result = $this->gateway->silentsign($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    public function signingEndpoint(array $params)
    {
        try {
            $result = $this->gateway->signingEndpoint($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }
    /**
     * @param array $params
     * @return array
     * 微信内置签约
     */
    public function wxSign(array $params)
    {
        try {
            $result = $this->gateway->wxSign($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 微信预扣款
     */
    public function preDeduct(array $params)
    {
        try {
            $result = $this->gateway->preDeduct($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 扣款
     */
    public function deductMoney(array $params)
    {
        try {
            $result = $this->gateway->deductMoney($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 银行卡绑卡
     */
    public function cardBankRequest(array $params){
        try {
            $result = $this->gateway->cardRequest($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 银行卡解绑
     */
    public function commonBankCancel(array $params){
        try {
            $result = $this->gateway->commonCancel($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 银行卡退款
     */
    public function commonBankRefund(array $params){
        try {
            $result = $this->gateway->commonRefund($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return array
     * 银行卡签约
     */
    public function banksign(array $params){
        try {
            $result = $this->gateway->banksign($params);
            return $this->formatResponse($result);
        } catch (MonthPayException $e) {
            return $this->formatError($e->getMessage());
        }
    }

    /**
     * 统一响应格式
     * @param mixed $response
     * @return array
     */
    protected function formatResponse($response)
    {
        // 处理字符串响应
        if (is_string($response)) {
            // 尝试解析JSON
            $parsed = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $response = $parsed;
            }
        }

        // 处理数组响应
        if (is_array($response)) {
            return [
                'result' => 0,
                'msg' => 'success',
                'data' => $response
            ];
        }

        // 处理其他类型响应
        return [
            'result' => 0,
            'msg' => 'success',
            'data' => $response
        ];
    }

    /**
     * 统一错误格式
     * @param string $message
     * @return array
     */
    protected function formatError($message)
    {
        return [
            'result' => -1,
            'msg' => $message,
            'data' => null
        ];
    }

    public function gateway()
    {
        return $this->gateway;
    }
}
