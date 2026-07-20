<?php

namespace BaiGe\MonthPay\Support;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use Exception;

/**
 * 合利宝请求构建器
 *
 * 封装签名构建、SM4加密、请求发送、响应验签等通用逻辑
 */
class HelipayRequestBuilder
{
    /** @var array 配置 */
    private $config;

    /** @var \Monolog\Logger 日志 */
    private $log;

    /**
     * @param array $config 配置
     * @param \Monolog\Logger $log 日志实例
     */
    public function __construct(array $config, $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    /**
     * 构建签名请求并发送
     *
     * @param array $fields 请求字段（按顺序定义）
     * @param array $encryptFields 需要SM4加密的字段列表
     * @param array $excludeFields 不参与签名的字段列表
     * @param string $action 操作名称（用于日志）
     * @return array 响应数据
     * @throws MonthPayException
     */
    public function buildAndSend(array $fields, array $encryptFields = [], array $excludeFields = [], string $action = '')
    {
        $sm4Key = '';
        $sm4Iv = '';
        $isEncrypt = false;

        // 有加密字段时生成SM4密钥
        if (!empty($encryptFields)) {
            $sm4Key = HelipaySM::generateSm4Key();
            $sm4Iv = $this->config['sm4_iv'];
        }

        $signStr = '';
        $data = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                $value = '';
            }

            // SM4加密
            if (in_array($key, $encryptFields) && !empty($value)) {
                $isEncrypt = true;
                $value = HelipaySM::sm4Encrypt($value, $sm4Key, $sm4Iv);
            }

            // 拼接签名串
            if (!in_array($key, $excludeFields)) {
                $signStr .= '&' . strval($value);
            }

            $data[$key] = $value;
        }

        // 有加密字段时加密SM4密钥
        if ($isEncrypt) {
            $publicKey = HelipaySM::getPublicKeyFromCER($this->config['helipay_public_cert_path']);
            $data['encryptionKey'] = HelipaySM::encrypt($sm4Key, $publicKey);
        }
        // SM2签名
        $data['sign'] = HelipaySM::sign(
            $signStr,
            HelipaySM::getPrivateKeyFromPFX(
                $this->config['customer_private_cert_path'],
                $this->config['customer_private_cert_pwd']
            ),
            $this->config['sm2_user_id']
        );
        return $this->sendAndHandle($data, $action);
    }

    /**
     * 构建签名请求并发送（无SM4加密）
     *
     * @param array $fields 请求字段（按顺序定义）
     * @param array $excludeFields 不参与签名的字段列表
     * @param string $action 操作名称（用于日志）
     * @return array 响应数据
     * @throws MonthPayException
     */
    public function send(array $fields, array $excludeFields = [], string $action = '')
    {
        $signStr = '';
        $data = [];

        foreach ($fields as $key => $value) {
            if ($value === null) {
                $value = '';
            }

            if (!in_array($key, $excludeFields)) {
                $signStr .= '&' . strval($value);
            }

            $data[$key] = $value;
        }

        // SM2签名
        $data['sign'] = HelipaySM::sign(
            $signStr,
            HelipaySM::getPrivateKeyFromPFX(
                $this->config['customer_private_cert_path'],
                $this->config['customer_private_cert_pwd']
            ),
            $this->config['sm2_user_id']
        );

        return $this->sendAndHandle($data, $action);
    }

    /**
     * 发送请求并处理响应
     *
     * @param array $data 请求数据（已签名）
     * @param string $action 操作名称
     * @return array 响应数据
     * @throws MonthPayException
     */
    private function sendAndHandle(array $data, string $action)
    {
        $result = $this->request($data, $this->config['api_url'], $action);
        $res = json_decode($result, true);
        if (!is_array($res)) {
            throw new MonthPayException('接口返回格式异常: ' . $result);
        }

        $this->handleResponse($res);

        return $res;
    }

    /**
     * 统一请求入口
     *
     * @param array $params 请求参数
     * @param string $url 请求地址
     * @param string $action 操作名称
     * @return string 响应内容
     * @throws MonthPayException
     */
    private function request(array $params, string $url, string $action)
    {
        $this->log->info('请求 ' . $action . ' 参数: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

        $result = Utils::httpCurl($url, $params);

        $this->log->info('请求 ' . $action . ' 返回: ' . $result);

        return $result;
    }

    /**
     * 响应处理（验签 + 检查状态）
     *
     * @param array $res 响应数据
     * @throws MonthPayException
     */
    private function handleResponse(array $res)
    {
        $this->verifyResponse($res);
        $respCode = isset($res['rt2_retCode']) ? $res['rt2_retCode'] : '';
        if ($respCode !== '0000') {
            $msg = isset($res['rt3_retMsg']) ? $res['rt3_retMsg'] : '交易失败';
            throw new MonthPayException($msg, -1, null, $respCode);
        }
    }

    /**
     * 响应验签
     *
     * @param array $res 响应数据
     * @throws MonthPayException
     */
    public function verifyResponse(array $res)
    {
        if (!isset($res['sign'])) {
            throw new MonthPayException('响应中缺少签名');
        }

        $sign = $res['sign'];
        $excludeFields = ['sign', 'signatureType', 'smsStatus', 'smsMsg', 'smsConfirm', 'ruleJson', 'receiverFee', 'offlineFee', 'presetSplitAmount','unTransId','splittableAmount','payTransId'];

        $signFields = [];
        foreach ($res as $key => $value) {
            if (in_array($key, $excludeFields)) {
                continue;
            }
            $signFields[$key] = $value;
        }

        uksort($signFields, 'strnatcmp');
        $signStr = '';
        foreach ($signFields as $value) {
            $signStr .= '&' . strval($value === null ? '' : $value);
        }
        $publicKey = HelipaySM::getPublicKeyFromCER($this->config['helipay_public_cert_path']);
        $sm2UserId = $this->config['sm2_user_id'];
        $verified = HelipaySM::verify($signStr, $sign, $publicKey, $sm2UserId);

        if (!$verified) {
            $this->log->error('响应验签失败', [
                'signStr' => $signStr,
                'sign' => $sign,
                'response' => $res
            ]);
            throw new MonthPayException('响应验签失败');
        }
    }
}
