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

    protected function logRequest(string $action, array $params)
    {
        $this->log->info("请求 {$action} 参数: " . json_encode($params, 256));
    }

    protected function logResponse(string $action, $response)
    {
        $this->log->info("请求 {$action} 返回: " . var_export($response, true));
    }

    abstract public function h5Sign(array $params);
    abstract public function wxSign(array $params);
    abstract public function preDeduct(array $params);
}
