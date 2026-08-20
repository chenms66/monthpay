<?php

namespace BaiGe\MonthPay\Gateways;

use BaiGe\MonthPay\Log\LogService;

abstract class AbstractGateway
{
    protected $config;
    protected $log;

    public function __construct(string $channel, array $config, string $logPath)
    {
        $this->config = $config;
        $this->log = new LogService($channel, $logPath);
    }

    protected function logRequest(string $action, $params)
    {
        $logStr = is_array($params)
            ? json_encode($params, 256 | JSON_INVALID_UTF8_SUBSTITUTE)
            : (is_string($params) ? $params : var_export($params, true));
        $this->log->info("请求 {$action} 参数: " . $logStr);
    }

    protected function logResponse(string $action, $response)
    {
        $logStr = is_array($response)
            ? json_encode($response, 256 | JSON_INVALID_UTF8_SUBSTITUTE)
            : (is_string($response) ? $response : var_export($response, true));
        $this->log->info("请求 {$action} 返回: " . $logStr);
    }

    abstract public function h5Sign(array $params);
    abstract public function wxSign(array $params);
    abstract public function deductMoney(array $params);
}
