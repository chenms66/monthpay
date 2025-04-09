<?php

namespace BaiGe\MonthPay\Support;

class HttpClient
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function post($url, $data)
    {
        $headers = $this->createAuthorization($url, $data);
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    private function createAuthorization($url, $data)
    {
        // 使用 Signer 进行请求认证
        return (new Signer($this->config))->createAuthorization($url, $data);
    }
}
