<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Config\BizType;
use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;

/**
 * 快钱协议支付网关
 *
 * 基于快钱UMGW协议支付接口，使用PKI证书加密/签名
 * 文档：https://open.99bill.com/menu!access.do?menuClass=1&mid=1&pid=16&contentId=1
 *
 * 配置项说明：
 * - member_code: 快钱会员号（11位）
 * - merchant_id: 商户编号（15位）
 * - terminal_id: 终端编号（8位）
 * - merchant_cert_path: 商户私钥证书路径(.pfx)
 * - merchant_cert_password: 商户私钥证书密码
 * - platform_cert_path: 快钱公钥证书路径(.cer)
 * - temp_dir: 临时文件目录（PKI加解密需要文件路径，默认使用系统临时目录）
 * - request_url: 快钱请求地址
 * - callback: 同步回调地址
 * - pay_callback: 异步通知地址
 */
class KqGateway extends AbstractGateway
{
    // 报文类型
    const MSG_SIGN_APPLY = 'A1001';       // 签约申请
    const MSG_SIGN_VERIFY = 'A1002';      // 签约短信验证
    const MSG_AGREE_PAY = 'A1003';        // 协议支付
    const MSG_REFUND = 'A9007';           // 退款
    const MSG_PCI_QUERY = 'A9003';        // PCI数据查询
    const MSG_PCI_UNBIND = 'A9002';       // PCI数据解绑
    const MSG_BANK_SIGN = 'A9014';        // 银网联一键绑卡
    const MSG_BANK_SIGN_CONFIRM = 'A9031'; // API一键绑卡确认
    /** 借记卡 */
    const DEBIT_CARD = 2;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    protected $cardTypeMap = [
        self::DEBIT_CARD  => '0002',
        self::CREDIT_CARD => '0001',
    ];
    protected $tempDir;

    // 商户证书缓存（cert + pkey），避免每次PKI操作重复读取.pfx文件
    protected $merchantCertCache;

    public function __construct(array $config, string $logPath)
    {
        parent::__construct('kq', $config, $logPath);
        $this->tempDir = $config['temp_dir'];
        // 统一转为正斜杠，OpenSSL 内部 fopen 不支持反斜杠
        $this->tempDir = str_replace('\\', '/', $this->tempDir);
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    # ---------------------------
    # 签约流程
    # ---------------------------

    public function h5Sign(array $params)
    {
        return $this->signApply($params);
    }

    public function wxSign(array $params)
    {
        return $this->signApply($params);
    }

    /**
     * 签约申请（发送短信验证码）
     * 报文类型: A1001
     *
     * 必填参数:
     * - card_no: 银行卡号
     * - t_name: 持卡人姓名
     * - t_paper_type: 证件类型（0-身份证）
     * - t_paper_num: 证件号码
     * - t_tel: 手机号码
     * - protocol_version: 用户授权协议版本号
     * - protocol_no: 用户授权协议流水号
     *
     * 可选参数:
     * - customer_id: 客户号（默认使用证件号）
     * - card_type: 卡类型 0-借记卡 1-信用卡
     * - expired_date: 卡有效期(信用卡必填, 格式MMYY)
     * - cvv2: 卡校验码(信用卡必填)
     * - bind_type: 接入方式 0-商户绑定 1-成员绑定 2-飞凡专用
     * - device_type: 设备类型 01-电脑 02-手机 03-平板
     * - source_ip: 客户端IP（默认自动获取）
     * - app_name: 交易发起应用名称
     * - re_mark: 备注
     */
    public function signApply(array $params)
    {
        Validator::validateRequiredFields($params, [
            'card_no', 't_name', 't_paper_num', 't_tel', 'num_id'
        ]);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['customer_id'] ?? $params['t_paper_num'],
            'pan' => $params['card_no'],
            'cardHolderName' => $params['t_name'],
            'idType' => (isset($params['t_paper_type']) && $params['t_paper_type'] == 1) ? '0' : ($params['t_paper_type'] ?? '0'),
            'cardHolderId' => $params['t_paper_num'],
            'phoneNo' => $params['t_tel'],
            'bindType' => $params['bind_type'] ?? '0',
            'deviceType' => $params['device_type'] ?? '02',
            'sourceIp' => $params['source_ip'] ?? Utils::ip(),
            'appName' => $params['app_name'] ?? '',
            'protocolVersion' => $params['protocol_version'] ?? 'v1',
            'protocolNo' => $params['num_id'],
            'reMark' => $params['re_mark'] ?? '',
        ];

        // 信用卡必填字段
        if (isset($params['card_type']) && $params['card_type'] == self::CREDIT_CARD) {
            $body['expiredDate'] = $params['expired_date'] ?? '';
            $body['cvv2'] = $params['cvv2'] ?? '';
        }

        return $this->sendRequest(self::MSG_SIGN_APPLY, $body, __FUNCTION__, $params['num_id']);
    }

    /**
     * 签约短信验证
     * 报文类型: A1002
     *
     * 必填参数:
     * - customer_id: 客户号
     * - card_no: 银行卡号
     * - t_tel: 手机号码
     * - code: 短信验证码
     * - token: 签约申请返回的令牌信息
     *
     * 可选参数:
     * - bind_type: 接入方式（0:商户绑定 1:成员绑定 2:飞凡专用）
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, ['t_paper_num', 'card_no', 't_tel', 'code', 'unique_code','out_contract_code']);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['t_paper_num'],
            'pan' => $params['card_no'],
            'phoneNo' => $params['t_tel'],
            'validCode' => $params['code'],
            'token' => $params['unique_code'],
            'bindType' => $params['bind_type'] ?? '0',
        ];

        return $this->sendRequest(self::MSG_SIGN_VERIFY, $body, __FUNCTION__, $params['out_contract_code']);
    }

    # ---------------------------
    # 支付 / 退款
    # ---------------------------

    /**
     * 协议支付（扣款）
     * 报文类型: A1003
     *
     * 必填参数:
     * - out_trade_no: 商户订单号（作为externalRefNumber）
     * - customer_id: 客户号
     * - pay_token: 支付协议号（签约成功后返回的payToken）
     * - expect_money: 支付金额（元）
     *
     * 可选参数:
     * - product_name: 商品简称
     * - product_num: 商品数量
     * - ext: 扩展字段1
     * - ext1: 扩展字段2
     * - tr3_url: 异步通知地址
     * - bank_id: 银行代码
     * - bind_type: 接入方式（0:商户绑定 1:成员绑定 2:飞凡专用）
     * - device_type: 付款方设备类型（01:电脑 02:手机 03:平板 04:可穿戴 05:数字电视 06:条码 99:其他）
     * - source_ip: 客户端IP
     * - service_type: 业务种类
     * - settle_merchant_id: 结算商户号
     */
    public function deductMoney(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 't_paper_num', 'agreement_no', 'expect_money']);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['t_paper_num'],
            'payToken' => $params['agreement_no'],
            'amount' => Utils::yuanToCent($params['expect_money']),
            'entryTime' => date('YmdHis'),
            'productName' => $params['product_name'] ?? '',
            'productNum' => $params['product_num'] ?? '1',
            'ext' => $params['ext'] ?? '',
            'ext1' => $params['ext1'] ?? '',
            'tr3Url' => $params['tr3_url'] ?? $this->config['pay_callback'] ?? '',
            'bankId' => $params['bank_id'] ?? '',
            'bindType' => $params['bind_type'] ?? '0',
            'deviceType' => $params['device_type'] ?? '02',
            'sourceIp' => $params['source_ip'] ?? Utils::ip(),
            'serviceType' => $params['service_type'] ?? '',
            'settleMerchantId' => $params['settle_merchant_id'] ?? '',
        ];

        return $this->sendRequest(self::MSG_AGREE_PAY, $body, __FUNCTION__, $params['out_trade_no']);
    }

    public function silentSign(array $params)
    {
        return $this->pciQuery($params);
    }

    /**
     * 退款
     * 报文类型: A9007
     *
     * 必填参数:
     * - refund_no: 退款流水号（作为externalRefNumber）
     * - out_trade_no: 原快钱交易号（系统参考号）
     * - refund_amount: 退款金额（元）
     *
     * 可选参数:
     * - settle_merchant_id: 结算商户号
     */
    public function commonRefund(array $params)
    {
        Validator::validateRequiredFields($params, ['out_trade_no', 'refund_amount', 'refund_no']);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'origRefNumber' => $params['out_trade_no'],
            'entryTime' => date('YmdHis'),
            'amount' => Utils::yuanToCent($params['refund_amount']),
            'settleMerchantId' => $params['settle_merchant_id'] ?? '',
            'tr3Url' => $this->config['refund_result'],
        ];

        return $this->sendRequest(self::MSG_REFUND, $body, __FUNCTION__, $params['refund_no']);
    }

    # ---------------------------
    # PCI数据管理
    # ---------------------------

    /**
     * PCI数据查询
     * 报文类型: A9003
     *
     * 必填参数:
     * - customer_id: 客户号
     *
     * 可选参数:
     * - card_type: 卡类型（0001信用卡 0002借记卡）
     * - storable_pan: 缩略卡号
     * - pay_token: 支付协议号
     * - bank_id: 银行代码
     * - bind_type: 接入方式
     */
    public function pciQuery(array $params)
    {
        Validator::validateRequiredFields($params, ['t_paper_num']);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['t_paper_num'],
            'cardType' => $cardTypeMap[$params['card_type']] ?? '0002',
            'storablePan' => $params['storable_pan'] ?? '',
            'payToken' => $params['pay_token'] ?? '',
            'bankId' => $params['bank_id'] ?? '',
            'bindType' => $params['bind_type'] ?? '',
        ];

        return $this->sendRequest(self::MSG_PCI_QUERY, $body, __FUNCTION__, '');
    }

    /**
     * PCI数据解绑
     * 报文类型: A9002
     *
     * 必填参数:
     * - customer_id: 客户号
     *
     * 可选参数:
     * - pan: 银行卡号
     * - pay_token: 支付协议号（与storable_pan二选一）
     * - storable_pan: 缩略卡号（与pay_token二选一）
     * - bind_type: 接入方式
     */
    public function pciUnbind(array $params)
    {
        Validator::validateRequiredFields($params, ['t_paper_num']);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'customerId' => $params['t_paper_num'],
            'pan' => $params['pan'] ?? '',
            'payToken' => $params['pay_token'] ?? '',
            'storablePan' => $params['storable_pan'] ?? '',
            'bindType' => $params['bind_type'] ?? '',
        ];

        return $this->sendRequest(self::MSG_PCI_UNBIND, $body, __FUNCTION__, '');
    }

    # ---------------------------
    # 一键绑卡
    # ---------------------------

    /**
     * API一键绑卡（发起绑卡请求）
     * 报文类型: A9030
     *
     * 必填参数:
     * - out_trade_no: 商户订单号（作为externalRefNumber）
     * - customer_id: 客户号
     * - t_name: 持卡人姓名
     * - t_paper_type: 证件类型
     * - t_paper_num: 证件号码
     * - tr3_url: 异步通知地址
     *
     * 可选参数:
     * - card_no: 银行卡号
     * - t_tel: 手机号码
     * - bank_id: 银行简称
     * - card_type: 卡类型（0001信用卡 0002借记卡）
     * - bind_type: 接入方式（0:商户绑定 1:成员绑定 2:飞凡专用）
     * - device_type: 设备类型（01:电脑 02:手机 03:平板 04:可穿戴 05:数字电视 06:条码 99:其他）
     * - source_ip: 客户端IP
     * - app_name: 交易发起应用名称
     * - re_mark: 备注
     */
    public function bankSign(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no', 't_name', 't_paper_num','bank_no'
        ]);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['t_paper_num'],
            'cardHolderName' => $params['t_name'],
            'idType' => (isset($params['t_paper_type']) && $params['t_paper_type'] == 1) ? '0' : ($params['t_paper_type'] ?? '0'),
            'cardHolderId' => $params['t_paper_num'],
            'pan' => $params['card_no'] ?? '',
            'phoneNo' => $params['t_tel'] ?? '',
            'bankId' => $params['bank_no'] ?? '',
            'cardType' => $cardTypeMap[$params['card_type']] ?? '0002',
            'clbckUrl' => $this->config['return_url'],
            'tr3Url' => $this->config['sign_callback'],
            'bindType' => $params['bind_type'] ?? '',
            'deviceType' => $params['device_type'] ?? '',
            'sourceIp' => $params['source_ip'] ?? '',
            'appName' => $params['app_name'] ?? '',
            'reMark' => $params['re_mark'] ?? '',
        ];
        if(isset($params['biz_type']) && $params['biz_type'] == BizType::UNION_PAY){
            $body['unionPayData'] = [
                'errClbckUrl'=>$this->config['return_url'],
                'userRegTime'=>date('Ymd'),
                'userLoginMethod'=>'动码',
                'sourceMacAddr'=>Utils::ip(),
            ];
        }

        return $this->sendRequest(self::MSG_BANK_SIGN, $body, __FUNCTION__, $params['out_trade_no']);
    }

    /**
     * API一键绑卡确认
     * 报文类型: A9031
     *
     * 必填参数:
     * - out_trade_no: 商户订单号（需与A9030保持一致）
     * - customer_id: 客户号
     * - bind_token: 绑卡令牌（A9030返回的bindToken）
     * - user_agree_flag: 用户同意绑卡标识（固定值1）
     * - user_agree_date: 用户同意绑卡授权时间（格式yyyyMMddHHmmss）
     * - sign_infos_list: 签约绑卡列表（A9030返回的signInfosList）
     *
     * 可选参数:
     * - device_type: 设备类型
     * - source_ip: 客户端IP
     * - app_name: 交易发起应用名称
     */
    public function bankSignConfirm(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no', 'customer_id', 'bind_token',
            'user_agree_flag', 'user_agree_date', 'sign_infos_list'
        ]);

        $body = [
            'merchantId' => $this->config['merchant_id'],
            'terminalId' => $this->config['terminal_id'],
            'customerId' => $params['customer_id'],
            'deviceType' => $params['device_type'] ?? '02',
            'sourceIp' => $params['source_ip'] ?? Utils::ip(),
            'appName' => $params['app_name'] ?? '',
            'bindToken' => $params['bind_token'],
            'userAgreeFlag' => $params['user_agree_flag'],
            'userAgreeDate' => $params['user_agree_date'],
            'signInfosList' => $params['sign_infos_list'],
        ];

        return $this->sendRequest(self::MSG_BANK_SIGN_CONFIRM, $body, __FUNCTION__, $params['out_trade_no']);
    }

    # ---------------------------
    # 核心请求方法
    # ---------------------------

    /**
     * 发送请求到快钱UMGW
     * 流程：组装报文头 -> PKI加密加签 -> HTTP POST -> 解析响应 -> PKI解密验签
     *
     * @param string $messageType 报文类型
     * @param array $body 明文请求体
     * @param string $action 操作名称（用于日志）
     * @return array 解密后的响应（包含head和responseBody）
     * @throws MonthPayException
     */
    protected function sendRequest(string $messageType, array $body, string $action, string $externalRefNumber = '')
    {
        // 组装报文头
        $head = [
            'version' => '1.0.0',
            'messageType' => $messageType,
            'memberCode' => $this->config['member_code'],
        ];
        // PCI数据查询/解绑接口不需要externalRefNumber
        if ($externalRefNumber !== '') {
            $head['externalRefNumber'] = $externalRefNumber;
        }

        // PKI加密加签：快钱公钥加密 + 商户私钥签名
        $requestBody = $this->seal($body);

        $request = [
            'head' => $head,
            'requestBody' => $requestBody,
        ];
        $this->logRequest($action, ['head' => $head, 'body' => $body]);

        // 发送HTTP请求
        $response = $this->httpPost($this->config['request_url'], json_encode($request, JSON_UNESCAPED_UNICODE));

        $this->logResponse($action, $response);

        // 解析响应
        $respArr = json_decode($response, true);

        if (!is_array($respArr) || !isset($respArr['head'])) {
            throw new MonthPayException('快钱接口返回格式异常', '-1');
        }
        // PKI解密验签：商户私钥解密 + 快钱公钥验签
        $signedData = $respArr['responseBody']['signedData'] ?? '';
        $envelopedData = $respArr['responseBody']['envelopedData'] ?? '';

        $decryptBody = $this->unseal($signedData, $envelopedData);

        $decryptArr = json_decode($decryptBody, true);
        $this->logResponse($action . '_解密', $decryptArr);

        $respCode = $decryptArr['bizResponseCode'] ?? '';

        if ($respCode !== '0000') {
            throw new MonthPayException(
                $decryptArr['bizResponseMessage'] ?? '交易失败',
                '-1',
                null,
                $respCode,
                $decryptArr
            );
        }
        return $decryptArr;
    }

    # ---------------------------
    # PKI 加密/签名/解密/验签
    # ---------------------------

    /**
     * PKI加密加签（请求方向）
     * 使用快钱公钥加密 + 商户私钥签名
     *
     * @param array $body 明文请求体
     * @return array ['signedData' => ..., 'envelopedData' => ...]
     */
    public function seal(array $body): array
    {
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
        $salt = $this->config['member_code'] . '_' . time() . '_' . mt_rand(1000, 9999);

        $signedData = $this->getSignedData($jsonBody, $salt);
        $envelopedData = $this->getEnvelopedData($jsonBody, $salt);

        if (empty($signedData) || empty($envelopedData)) {
            throw new MonthPayException('PKI加密加签失败，signedData或envelopedData为空');
        }

        return [
            'signedData' => $signedData,
            'envelopedData' => $envelopedData,
        ];
    }

    /**
     * PKI解密验签（响应方向）
     * 使用商户私钥解密 + 快钱公钥验签
     *
     * @param string $signedData 快钱返回的签名
     * @param string $envelopedData 快钱返回的密文
     * @return string 解密后的明文
     * @throws MonthPayException
     */
    public function unseal(string $signedData, string $envelopedData): string
    {
        $salt = $this->config['member_code'] . '_' . time() . '_' . mt_rand(1000, 9999);
        $encPath = $this->tempDir . 'respEnc_' . $salt . '.txt';
        $decPath = $this->tempDir . 'respDec_' . $salt . '.txt';
        $signPath = $this->tempDir . 'respSign_' . $salt . '.txt';
        $unSignPath = $this->tempDir . 'unSign_' . $salt . '.txt';

        try {
            // 先解密
            $decryptData = $this->getDecryptData($envelopedData, $salt);

            if (empty($decryptData)) {
                throw new MonthPayException('快钱响应解密失败');
            }

            // 再验签（getVerifyFlag 需要从文件读取解密数据，与 DEMO 一致）
            $verified = $this->getVerifyFlag($signedData, $salt);

            if (!$verified) {
                throw new MonthPayException('快钱响应验签失败');
            }

            return $decryptData;
        } finally {
            // 统一清理所有临时文件
            $this->cleanupTempFiles($encPath, $decPath, $signPath, $unSignPath);
        }
    }

    /**
     * 读取商户证书和私钥（.pfx文件）
     * 带缓存，同一请求周期内只读取一次
     *
     * @return array ['cert' => ..., 'pkey' => ...]
     * @throws MonthPayException
     */
    protected function getMerchantCertAndKey(): array
    {
        if ($this->merchantCertCache !== null) {
            return $this->merchantCertCache;
        }

        $certPath = $this->config['merchant_cert_path'];
        $certPassword = $this->config['merchant_cert_password'] ?? '123456';

        $pfx = file_get_contents($certPath);
        if ($pfx === false) {
            throw new MonthPayException("无法读取商户证书文件: {$certPath}");
        }

        $certs = [];
        openssl_pkcs12_read($pfx, $certs, $certPassword);

        if (empty($certs['cert'])) {
            throw new MonthPayException('商户证书解析失败，请检查证书密码');
        }
        if (empty($certs['pkey'])) {
            throw new MonthPayException('商户私钥解析失败，请检查证书密码');
        }

        $this->merchantCertCache = [
            'cert' => $certs['cert'],
            'pkey' => $certs['pkey'],
        ];

        return $this->merchantCertCache;
    }

    /**
     * 读取快钱公钥证书(.cer)
     */
    protected function getPlatformCert(): string
    {
        $certPath = $this->config['platform_cert_path'];
        $pubKey = file_get_contents($certPath);
        if ($pubKey === false) {
            throw new MonthPayException("无法读取快钱公钥证书文件: {$certPath}");
        }
        return $pubKey;
    }

    /**
     * PKCS7加密（使用快钱公钥）
     * 将明文body用快钱公钥加密，返回Base64密文
     */
    protected function getEnvelopedData(string $originalData, string $salt): string
    {
        $dataPath = $this->tempDir . 'data_' . $salt . '.txt';
        $encodePath = $this->tempDir . 'endata_' . $salt . '.txt';

        try {
            // 写入明文到临时文件
            file_put_contents($dataPath, $originalData);
            $fp = fopen($encodePath, "wb");
            if ($fp === false) {
                throw new MonthPayException("Unable to open file: {$encodePath}");
            }
            fclose($fp);

            // 使用快钱公钥加密，AES-128-CBC算法
            openssl_pkcs7_encrypt($dataPath, $encodePath, $this->getPlatformCert(), null, PKCS7_BINARY, OPENSSL_CIPHER_AES_128_CBC);

            // 读取加密结果，去除SMIME头（前191字符），提取纯Base64密文
            $encodedData = file_get_contents($encodePath);
            return str_replace(["\r\n", "\r", "\n", "\\"], "", substr($encodedData, 191));
        } finally {
            $this->cleanupTempFiles($dataPath, $encodePath);
        }
    }

    /**
     * PKCS7签名（使用商户私钥）
     * 将明文body用商户私钥签名，返回Base64签名
     */
    protected function getSignedData(string $originalData, string $salt): string
    {
        $dataPath = $this->tempDir . 'origdata_' . $salt . '.txt';
        $signPath = $this->tempDir . 'signdata_' . $salt . '.txt';

        try {
            file_put_contents($dataPath, $originalData);
            $fp = fopen($signPath, "wb");
            if ($fp === false) {
                throw new MonthPayException("Unable to open file: {$signPath}");
            }
            fclose($fp);

            $certAndKey = $this->getMerchantCertAndKey();
            openssl_pkcs7_sign(
                $dataPath, $signPath,
                $certAndKey['cert'],
                $certAndKey['pkey'],
                [],
                PKCS7_BINARY
            );

            // 读取签名结果，去除SMIME头（前186字符），提取纯Base64签名
            $signData = file_get_contents($signPath);
            return str_replace(["\r\n", "\r", "\n"], "", substr($signData, 186));
        } finally {
            $this->cleanupTempFiles($dataPath, $signPath);
        }
    }

    /**
     * PKCS7解密（使用商户私钥）
     * 将快钱返回的密文用商户私钥解密，返回明文
     * 注意：输入数据须遵守SMIME格式规范，请勿做增删、对齐等操作
     */
    protected function getDecryptData(string $envelopedData, string $salt): string
    {
        $encPath = $this->tempDir . 'respEnc_' . $salt . '.txt';
        $decPath = $this->tempDir . 'respDec_' . $salt . '.txt';

        try {
            // SMIME格式封装，注意需要三个换行（与 DEMO 一致，使用实际换行符）
            $txt = "MIME-Version: 1.0
Content-Disposition: attachment; filename=\"smime.p7m\"
Content-Type: application/x-pkcs7-mime; smime-type=enveloped-data; name=\"smime.p7m\"
Content-Transfer-Encoding: base64" . "\n\n\n" . $envelopedData;
            file_put_contents($encPath, $txt);

            $fp = fopen($decPath, "wb");

            if ($fp === false) {
                throw new MonthPayException("Unable to open file: {$decPath}");
            }
            fclose($fp);

            $certAndKey = $this->getMerchantCertAndKey();
            $result = openssl_pkcs7_decrypt(
                $encPath, $decPath,
                $certAndKey['cert'],
                $certAndKey['pkey']
            );

            if ($result) {
                return file_get_contents($decPath);
            }

            $this->log->error('PKI解密失败: ' . openssl_error_string());
            return '';
        } finally {
            // 只清理加密文件，解密文件由 unseal 统一清理（getVerifyFlag 需要读取）
            $this->cleanupTempFiles($encPath);
        }
    }

    /**
     * PKCS7验签（使用快钱公钥）
     * 验证快钱返回的签名是否有效，并校验证书DN是否匹配
     *
     * @return bool 验签是否通过
     */
    protected function getVerifyFlag(string $signedData, string $salt): bool
    {
        $signPath = $this->tempDir . 'respSign_' . $salt . '.txt';
        $unSignPath = $this->tempDir . 'unSign_' . $salt . '.txt';
        $decPath = $this->tempDir . 'respDec_' . $salt . '.txt';

        try {
            if (!file_exists($decPath)) {
                $this->log->error('验签失败：解密文件不存在: ' . $decPath);
                return false;
            }
            $decryptData = file_get_contents($decPath);

            // 将签名数据和明文组装成SMIME格式
            file_put_contents($signPath, $this->formatSmimeSignData($signedData, $decryptData));

            $fp = fopen($unSignPath, "wb");
            if ($fp === false) {
                throw new MonthPayException("Unable to open file: {$unSignPath}");
            }
            fclose($fp);

            // PKCS7验签，提取签名者证书到unSignPath
            // Windows 上 openssl_pkcs7_verify 内部 fopen 会报 "No such process" 错误，
            // 这是 OpenSSL 的已知 bug，用 @ 抑制后检查返回值即可
            $flag = @openssl_pkcs7_verify($signPath, PKCS7_NOVERIFY, $unSignPath);

            if ($flag === true) {
                // 校验提取的证书是否与快钱公钥证书DN一致
                $clientCer = file_get_contents($unSignPath);
                $clientDN = openssl_x509_parse($clientCer);

                $platformCer = $this->getPlatformCert();
                $platformDN = openssl_x509_parse($platformCer);

                if (!is_array($clientDN)) {
                    $this->log->error('验签证书解析错误');
                    return false;
                }

                if (time() < intval($clientDN['validFrom_time_t']) || time() > intval($clientDN['validTo_time_t'])) {
                    $this->log->error('验签证书已过期或尚未生效');
                    return false;
                }

                if ($clientDN['subject'] === $platformDN['subject']
                    && $clientDN['serialNumber'] === $platformDN['serialNumber']) {
                    $this->log->info('PKI验签成功');
                    return true;
                }

                $this->log->error('验签失败：证书DN不匹配');
                return false;
            } elseif ($flag === 0) {
                $this->log->error('PKI验签失败：签名无效');
                return false;
            } else {
                $this->log->error('PKI验签错误：' . openssl_error_string());
                return false;
            }
        } finally {
            $this->cleanupTempFiles($signPath, $unSignPath);
        }
    }

    /**
     * 清理临时文件
     * 使用try/finally确保异常时也能清理
     */
    protected function cleanupTempFiles(string ...$files): void
    {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * 格式化SMIME签名数据
     * 内容须遵守SMIME格式规范，请勿做增删、对齐等操作
     */
    protected function formatSmimeSignData(string $signedData, string $decryptData): string
    {
        $chunkedSign = chunk_split($signedData, 76, "\n");
        $boundary = "----" . md5($chunkedSign);

        return <<<EOD
MIME-Version: 1.0
Content-Type: multipart/signed; protocol="application/x-pkcs7-signature"; micalg=sha256; boundary="$boundary"

This is an S/MIME signed message

--$boundary
$decryptData
--$boundary
Content-Type: application/x-pkcs7-signature; name="smime.p7s"
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename="smime.p7s"

$chunkedSign

--$boundary--


EOD;
    }

    # ---------------------------
    # HTTP 请求
    # ---------------------------

    /**
     * 发送POST请求到快钱
     *
     * @param string $url 请求地址
     * @param string $data JSON请求体
     * @return string 响应内容
     * @throws MonthPayException
     */
    protected function httpPost(string $url, string $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-type: application/json;charset=utf-8',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)");

        $output = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($output === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new MonthPayException("HTTP请求失败: {$error}");
        }

        if ($httpCode != 200) {
            curl_close($ch);
            throw new MonthPayException("HTTP状态码异常: {$httpCode}");
        }

        curl_close($ch);
        return $output;
    }
}
