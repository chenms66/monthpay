<?php

namespace BaiGe\MonthPay\Support;

class Signer
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function createAuthorization($url, $data = [], $method = 'POST')
    {
        $mchid = $this->config['mch_id'];
        $serial_no = $this->config['serial_no'];

        $url_parts = parse_url($url);
        $body = [
            'method' => $method,
            'url' => $url_parts['path'] . (!empty($url_parts['query']) ? "?${url_parts['query']}" : ''),
            'time' => time(),
            'nonce' => $this->getRandStr(32),
            'data' => strtolower($method) == 'post' ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ];

        $sign = $this->makeSign($body);
        $schema = 'WECHATPAY2-SHA256-RSA2048';
        $token = sprintf('mchid="%s",nonce_str="%s",timestamp="%d",serial_no="%s",signature="%s"', $mchid, $body['nonce'], $body['time'], $serial_no, $sign);

        return [
            'Content-Type:application/json',
            'Accept:application/json',
            'User-Agent:'.'*/*',
            'Authorization: ' . $schema . ' ' . $token
        ];
    }

    private function makeSign($data)
    {
        $message = '';
        foreach ($data as $value) {
            $message .= $value . "\n";
        }

        $private_key = $this->getPrivateKey($this->config['cert_key']);
        openssl_sign($message, $sign, $private_key, 'sha256WithRSAEncryption');

        return base64_encode($sign);
    }

    private function getPrivateKey($filepath)
    {
        return openssl_get_privatekey(file_get_contents($filepath));
    }

    private function getRandStr($length = 6)
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, $length);
    }
}
