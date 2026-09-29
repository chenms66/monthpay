<?php

namespace BaiGe\MonthPay\Support;

use BaiGe\MonthPay\Exceptions\MonthPayException;

/**
 * 银联（银杏智能支付）请求构建器
 *
 * 参考文档：《银杏智能支付服务接口接入说明》
 *
 * 鉴权方式（两种，按接口使用）：
 *   1) 智能选卡（frontquery）使用 OPEN-ACCESS-TOKEN：
 *        Authorization: OPEN-ACCESS-TOKEN AccessToken="<token>", AppId="<AppId>"
 *        需先通过 POST /v1/token/access 拿 accessToken。
 *
 *   2) 其它业务接口（拉卡 / 短信 / 签约 / 查询 / 解约 / 支付 / 退款 等）使用 OPEN-BODY-SIG：
 *        Authorization: OPEN-BODY-SIG AppId="...", Timestamp="<yyyyMMddHHmmss>", Nonce="...", Signature="..."
 *        其中 Signature = base64(HmacSHA256(appKey, appId + timestamp + nonce + SHA256(body)))
 *
 * 报文体是否带 sign / signType（文档明确）：
 *   - 签类接口（拉卡 / 短信 / 签约 / 查询 / 解约 等，3.3.2.x）报文体**不**带 sign 字段；
 *   - 快捷支付（ncfi.treatPay，3.3.4.x）和其它快捷类接口的响应体里会带 sign（响应验签用），
 *     但请求体不带 sign——鉴权走 OPEN-BODY-SIG Header。
 *   - 老 docx 老接口（bills.getQRCode 等 4.x）报文体带 sign / signType 走 SHA256 签名，
 *     这里仅作为签名工具保留，不在「接入说明」接口中使用。
 *
 * SM4 加密字段约定：
 *   - 密文字段（userNameEnc / certNoEnc / mobileEnc / cardNoEnc / smsVerifyCodeEnc / signIdEnc 等）
 *     存放 SM4Util.sm4enc(idx, plain) 的纯 hex 输出（无 idx 前缀）；
 *   - 索引（A001/B001/C001/D001/E001/F001）由对应的 xxxIdx 字段单独上送。
 *   - 服务器按 idx 与密文匹配对应密钥后解密（FAQ #2 验证示例：
 *       SM4Util.sm4enc("A001", "你好") = F98E4998C09FA5ADAE4CCDB901B5A254）。
 *
 * 关于加密：本 SDK 不带 SM4 算法实现，而是把"待加密字段的 idx+明文"聚合成
 *   JSON 数组 [{key:"A001", data:"..."}, {key:"B001", data:"..."}, ...]
 *   整体用 AES-256-ECB（密钥走 config['sm4_aes_key']，32 字节；无需 IV），加密后
 *   base64_encode 后 POST 到
 *       config['sm4_encrypt_url']   默认 https://sm4.baigebaodev.com/api/sm4/encrypt
 *       config['sm4_decrypt_url']   默认 https://sm4.baigebaodev.com/api/sm4/decrypt
 *   服务端返回密文数组，再按 idx 一一对应回填到原字段。
 *   必须配齐 sm4_aes_key + sm4_encrypt_url 才能发起业务请求。
 */
class UnionpayRequestBuilder
{
    const NUMERIC_CHARS = '0123456789';

    /** 默认 SM4 加解密远端服务（AES-256-ECB 包裹的密文 hex 数组） */
    const DEFAULT_SM4_ENCRYPT_URL = 'https://sm4.baigebaodev.com/api/sm4/encrypt';
    const DEFAULT_SM4_DECRYPT_URL = 'https://sm4.baigebaodev.com/api/sm4/decrypt';

    /** @var array */
    private $config;

    /** @var \BaiGe\MonthPay\Log\LogService */
    private $log;

    public function __construct(array $config, $log)
    {
        $this->config = $config;
        $this->log = $log;
    }

    // =====================================================================
    // 鉴权辅助
    // =====================================================================

    /**
     * 获取 accessToken（智能选卡专用）。
     *
     * 接口：POST {token_url}?appId={appId}
     * 报文：
     */
    public function getAccessToken(): string
    {
        $appId     = (string)($this->config['app_id']  ?? '');
        $appKey    = (string)($this->config['app_key'] ?? '');
        $timestamp = date('YmdHis');
        $nonce     = self::generateNonce(16);
        $signature = sha1($appId . $timestamp . $nonce . $appKey);

        $tokenUrl = $this->config['token_url'] ?? '';
        if ($tokenUrl === '') {
            throw new MonthPayException('未配置 token_url（accessToken 接口地址）');
        }
        $url  = rtrim($tokenUrl, '?') . '?appId=' . urlencode($appId);
        $body = [
            'appId'     => $appId,
            'timestamp' => $timestamp,
            'nonce'     => $nonce,
            'signature' => $signature,
        ];

        $this->log->info('请求 accessToken 参数: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
        $result = self::httpPostJson($url, $body);
        $this->log->info('请求 accessToken 返回: ' . $result);

        $res = json_decode($result, true);
        if (!is_array($res)) {
            throw new MonthPayException('获取 accessToken 返回格式异常: ' . $result);
        }
        if (($res['errCode'] ?? '') !== '0000') {
            throw new MonthPayException('获取 accessToken 失败: ' . ($res['errInfo'] ?? '未知错误'));
        }
        if (empty($res['accessToken'])) {
            throw new MonthPayException('获取 accessToken 失败：响应缺少 accessToken');
        }
        return (string)$res['accessToken'];
    }

    /**
     * 构造 OPEN-BODY-SIG 鉴权 Header。
     *
     * 步骤:
     *   1) testSH = sha256(body)
     *   2) s1 = appId + timestamp + nonce + testSH
     *   3) signature = base64(HmacSHA256(appKey, s1))
     *   4) Header  = 'OPEN-BODY-SIG AppId="...", Timestamp="...", Nonce="...", Signature="..."'
     *
     * @param string $appId      用于签名的 appId（签类用 app_id, 支付类用 pay_app_id）
     * @param string $appKey     用于 HMAC 的 appKey
     * @param string $timestamp  yyyyMMddHHmmss
     * @param string $nonce      随机字符串（demo 用 15 位纯数字）
     * @param string $body       已组装的 JSON 请求体字符串（必须与实际请求一致）
     */
    public static function buildOpenBodySigAuth(
        string $appId,
        string $appKey,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $testSH   = hash('sha256', $body);
        $s1       = $appId . $timestamp . $nonce . $testSH;
        $mac      = hash_hmac('sha256', $s1, $appKey, true);
        $signature = base64_encode($mac);
        return sprintf(
            'OPEN-BODY-SIG AppId="%s", Timestamp="%s", Nonce="%s", Signature="%s"',
            $appId,
            $timestamp,
            $nonce,
            $signature
        );
    }

    /**
     * 构造 OPEN-ACCESS-TOKEN 鉴权 Header（智能选卡专用）。
     */
    public static function buildAccessTokenAuth(string $appId, string $accessToken): string
    {
        return sprintf('OPEN-ACCESS-TOKEN AccessToken="%s", AppId="%s"', $accessToken, $appId);
    }

    // =====================================================================
    // 发送：两种鉴权路径
    // =====================================================================

    /**
     * 使用 OPEN-BODY-SIG 鉴权发送请求（拉卡 / 短信 / 签约 / 支付 / 退款 等业务接口）。
     *
     * 流程：
     *   1) 对 $encryptFields 中需要加密的字段调远端 SM4 服务，结果回填到 $data；
     *   2) 报文体**不**追加 sign / signType 字段（签类接口约定）；
     *   3) 用 appId+timestamp+nonce+SHA256(body) 走 HmacSHA256(appKey,…) 签 Header；
     *   4) POST 业务 URL。
     *
     * @param array   $data          业务参数
     * @param array   $encryptFields 加密字段表，['cardNoEnc'=>['idx'=>'B001','value'=>'卡号明文'], ...]
     * @param string  $action        操作名（日志）
     * @param string  $url           业务 URL
     * @param string|null $appId     优先用 config[app_id]，支付类可传 pay_app_id
     * @param string|null $appKey    优先用 config[app_key]，支付类可传 pay_app_key
     * @param bool    $decryptResponse 响应是否字段级解密（默认 false；签类查询接口传 true）
     */
    public function sendWithBodySig(
        array $data,
        array $encryptFields = [],
        string $action = '',
        string $url = '',
        ?string $appId = null,
        ?string $appKey = null,
        bool $decryptResponse = false
    ): array {
        $appId  = $appId  ?? (string)($this->config['app_id']  ?? '');
        $appKey = $appKey ?? (string)($this->config['app_key'] ?? '');
        if ($appId === '' || $appKey === '') {
            throw new MonthPayException('OPEN-BODY-SIG 鉴权缺少 appId/appKey');
        }

        $this->applySm4Encryptions($data, $encryptFields);
        // 报文体不追加 sign 字段（签类接口约定）。OPEN-BODY-SIG 走 Header。
        $bodyJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $timestamp = date('YmdHis');
        $nonce     = self::generateNumericNonce(15);
        $auth      = self::buildOpenBodySigAuth($appId, $appKey, $timestamp, $nonce, $bodyJson);
        $headers   = [
            'Authorization: ' . $auth,
            'Content-Type: application/json;charset=utf-8',
        ];
        return $this->sendAndHandle($url, $data, $headers, $action, $decryptResponse);
    }

    /**
     * 使用 OPEN-ACCESS-TOKEN 鉴权发送请求（仅智能选卡）。
     *
     * 智能选卡报文体里的 certNo 需要 SHA256 加密后再上送（文档 3.2：encryption="1"）。
     * 这里不再做默认加密——调用方自己预处理。
     *
     * @param string $url  业务 URL（默认 config[frontquery_url]，可被覆盖）
     */
    public function sendWithAccessToken(
        array $data,
        string $action,
        ?string $url = null,
        ?string $appId = null,
        ?string $accessToken = null
    ): array {
        $appId  = $appId  ?? (string)($this->config['app_id'] ?? '');
        $token  = $accessToken ?? $this->getAccessToken();
        if ($appId === '' || $token === '') {
            throw new MonthPayException('OPEN-ACCESS-TOKEN 鉴权缺少 appId 或 accessToken');
        }

        $url     = $url ?: (string)($this->config['frontquery_url'] ?? '');
        $auth    = self::buildAccessTokenAuth($appId, $token);
        $headers = [
            'Authorization: ' . $auth,
            'Content-Type: application/json;charset=utf-8',
        ];
        return $this->sendAndHandle($url, $data, $headers, $action);
    }

    // =====================================================================
    // SM4 加密（嵌套在 signInfo 等子数组中也支持）
    // =====================================================================

    /**
     * 对 $data 中的加密字段做 SM4 加密（远端：sm4.baigebaodev.com）。
     *
     * 流程：
     *   1) 收集所有需要加密的 (idx, plaintext)；
     *   2) 用 config['sm4_aes_key']（32 字节）走 AES-256-ECB 加密整体 JSON；
     *   3) POST 到 config['sm4_encrypt_url']（默认 sm4.baigebaodev.com）：
     *        Body: 加密后的 base64 字符串（raw）
     *        Resp: {"code":200,"data":[{key, data}, ...]}
     *   4) 按 idx 把返回的密文回填到 $data。
     *
     * @param array $data
     * @param array $encryptFields 形如 ['cardNoEnc'=>['idx'=>'B001','value'=>'卡号明文'], ...]
     */
    private function applySm4Encryptions(array &$data, array $encryptFields): void
    {
        // 1) 筛选出"需要真正加密"的字段
        $pending = [];
        foreach ($encryptFields as $field => $spec) {
            $idx   = $spec['idx']   ?? '';
            $plain = $spec['value'] ?? '';
            if ($plain === '' || $plain === null || $idx === '') {
                continue;
            }
            $existing = $this->locateField($data, $field);
            if ($existing === null) {
                continue;
            }
            if (is_string($existing) && $existing !== '') {
                continue;
            }
            $pending[] = [
                'field' => $field,
                'idx'   => $idx,
                'plain' => (string)$plain,
            ];
        }
        if (empty($pending)) {
            return;
        }

        $aesKey     = (string)($this->config['sm4_aes_key']     ?? '');
        $encryptUrl = (string)($this->config['sm4_encrypt_url'] ?? self::DEFAULT_SM4_ENCRYPT_URL);
        if ($aesKey === '' || $encryptUrl === '') {
            throw new MonthPayException(
                'SM4 加密必须配置 sm4_aes_key 和 sm4_encrypt_url（远端加解密服务）。'
            );
        }

        // 2) 聚合 + AES-256-ECB 包裹
        $payload = [];
        foreach ($pending as $item) {
            $payload[] = ['key' => $item['idx'], 'data' => $item['plain']];
        }
        $plainJson  = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cipherB64  = $this->aesEncryptEcbBase64($plainJson);
        $this->log->info('SM4 加密请求（idx 列表）: ' . json_encode(
            array_map(function ($p) { return $p['key']; }, $payload),
            JSON_UNESCAPED_UNICODE
        ));

        // 3) POST 远端 SM4 加密服务
        $rawResp = self::httpPostRaw($encryptUrl, $cipherB64);
        $resp    = json_decode($rawResp, true);
        if (!is_array($resp) || !isset($resp['code']) || (int)$resp['code'] !== 200 || !isset($resp['data'])) {
            throw new MonthPayException('SM4 远端加密失败：' . $rawResp);
        }

        // 4) 按 idx 回填密文
        $idx2cipher = [];
        foreach ($resp['data'] as $row) {
            if (!isset($row['key'], $row['data'])) {
                continue;
            }
            $idx2cipher[(string)$row['key']] = (string)$row['data'];
        }
        foreach ($pending as $item) {
            if (!isset($idx2cipher[$item['idx']])) {
                throw new MonthPayException("SM4 远端加密响应缺少 idx={$item['idx']} 的密文");
            }
            $this->setNestedField($data, $item['field'], $idx2cipher[$item['idx']]);
        }
    }

    /**
     * AES-256-ECB 加密（无 padding 控制，OPENSSL_RAW_DATA）。
     */
    public function aesEncryptEcbBase64(string $plaintext): string
    {
        $aesKey = (string)($this->config['sm4_aes_key'] ?? '');
        $data   = openssl_encrypt($plaintext, 'aes-256-ecb', $aesKey, OPENSSL_RAW_DATA);
        return base64_encode($data);
    }

    /**
     * AES-256-ECB 解密，输入 base64 密文，返回明文。
     */
    public function aesDecryptEcbBase64(string $cipherB64): string
    {
        $aesKey    = (string)($this->config['sm4_aes_key'] ?? '');
        $encrypted = base64_decode($cipherB64);
        return openssl_decrypt($encrypted, 'aes-256-ecb', $aesKey, OPENSSL_RAW_DATA);
    }

    /**
     * POST 一段已加密好的字符串（请求体是字符串本身，Content-Type raw/plain），
     * 返回响应体原文字符串。
     */
    public static function httpPostRaw(string $url, string $rawBody): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Accept: application/json',
            ],
        ]);
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new MonthPayException('cURL 请求失败: ' . $err);
        }
        curl_close($ch);
        return $out;
    }

    /**
     * 远端 SM4 解密（响应解密备用）。
     * 用法：$plainJson = $builder->remoteSm4Decrypt($respCipherBase64);
     */
    public function remoteSm4Decrypt(string $cipherB64): string
    {
        $aesKey     = (string)($this->config['sm4_aes_key'] ?? '');
        $decryptUrl = (string)($this->config['sm4_decrypt_url'] ?? self::DEFAULT_SM4_DECRYPT_URL);
        if ($aesKey === '') {
            throw new MonthPayException('SM4 远端解密缺少 config[sm4_aes_key]');
        }
        $rawResp  = self::httpPostRaw($decryptUrl, $cipherB64);
        $resp     = json_decode($rawResp, true);
        if (!is_array($resp) || !isset($resp['code']) || (int)$resp['code'] !== 200 || !isset($resp['data'])) {
            throw new MonthPayException('SM4 远端解密失败：' . $rawResp);
        }
        return is_array($resp['data'])
            ? json_encode($resp['data'], JSON_UNESCAPED_UNICODE)
            : (string)$resp['data'];
    }

    private function locateField(array $haystack, string $field)
    {
        if (array_key_exists($field, $haystack)) {
            return $haystack[$field];
        }
        foreach ($haystack as $sub) {
            if (is_array($sub)) {
                $found = $this->locateField($sub, $field);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function setNestedField(array &$data, string $field, $value): void
    {
        $hit = false;
        $walker = function (&$v, $k) use ($field, $value, &$hit) {
            if ($k === $field) {
                $v = $value;
                $hit = true;
            }
        };
        array_walk_recursive($data, $walker);
        if (!$hit && array_key_exists($field, $data)) {
            $data[$field] = $value;
        }
    }

    // =====================================================================
    // 老 docx 老接口的 SHA256 签名工具（仅 4.x 快捷类老接口用，文档外不调用）
    // =====================================================================

    /**
     * SHA256 签名字符串生成（老接口：bills.getQRCode / bills.query 等 4.x）。
     * 规则：
     *   - 排除 sign
     *   - 排除空值
     *   - 按参数名 ASCII 升序排序
     *   - 拼接 "k=v" 用 & 连接
     *   - 在字符串末尾追加 key（无 &key= 前缀）
     *   - SHA256 后转大写
     */
    public function signSha256(array $params): string
    {
        $key = (string)($this->config['key'] ?? '');

        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign') {
                continue;
            }
            if ($v === null) {
                continue;
            }
            if (is_string($v) && trim($v) === '') {
                continue;
            }
            if (is_array($v) || is_object($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $filtered[$k] = $v;
        }

        ksort($filtered, SORT_STRING);

        $pieces  = [];
        foreach ($filtered as $k => $v) {
            $pieces[] = $k . '=' . $v;
        }
        $signStr = implode('&', $pieces) . $key;

        return strtoupper(hash('sha256', $signStr));
    }

    // =====================================================================
    // HTTP / 响应处理
    // =====================================================================

    private function sendAndHandle(string $url, array $data, array $headers, string $action, bool $decryptResponse = false): array
    {
        $this->log->info('请求 ' . $action . ' 参数: ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        $result = self::httpPostJson($url, $data, $headers);
        $this->log->info('请求 ' . $action . ' 返回: ' . $result);

        $res = json_decode($result, true);
        if (!is_array($res)) {
            throw new MonthPayException('接口返回格式异常: ' . $result);
        }
        return $this->handleResponse($res, $action, $decryptResponse);
    }

    /**
     * 响应处理。
     *   - 签类接口：errCode !== '0000' 视为失败
     *   - 快捷类 / 网关：errCode 非 '0000'/'SUCCESS' 视为失败
     *   - $decryptResponse=true 时：调用远端 SM4 解密服务，把所有 xxxEnc/xxxIdx 回填明文
     */
    private function handleResponse(array $res, string $action, bool $decryptResponse = false): array
    {
        $err = $res['errCode'] ?? null;
        if ($err !== null && $err !== '0000' && $err !== 'SUCCESS') {
            $msg = $res['errMsg'] ?? $res['errInfo'] ?? '交易失败';
            throw new MonthPayException("{$action} 失败: {$msg}", -1, null, (string)$err, $res);
        }

        // 字段级响应解密（签类查询接口默认 signResult 子树都是密文；
        // 智能选卡约定顶层 encryption === '1'）
        if ($decryptResponse
            || (isset($res['encryption']) && (string)$res['encryption'] === '1')
        ) {
            $this->applySm4ResponseDecryption($res);
        }

        // 快捷支付同步响应里 status=TRADE_SUCCESS 才算成功；TRADE_CLOSED/UNKNOWN 由调用方继续走 notify
        return $res;
    }

    /**
     * 响应字段级解密。
     *
     * 响应体里出现 xxxEnc + xxxIdx 配对时（顶层 或 signInfo 等嵌套子树中），
     * 收集 (idx, cipher) → AES-256-ECB 包裹 → POST config['sm4_decrypt_url']
     * 服务端返回 [{key, data}, ...]（data 为明文），按 xxxEnc 字段名回填。
     *
     * 配置要求：sm4_aes_key + sm4_decrypt_url 必须配齐，否则抛错。
     */
    private function applySm4ResponseDecryption(array &$res): void
    {
        $aesKey     = (string)($this->config['sm4_aes_key']     ?? '');
        $decryptUrl = (string)($this->config['sm4_decrypt_url'] ?? self::DEFAULT_SM4_DECRYPT_URL);
        if ($aesKey === '' || $decryptUrl === '') {
            throw new MonthPayException(
                '响应字段级解密必须配置 sm4_aes_key 和 sm4_decrypt_url（远端加解密服务）。'
            );
        }

        // 1) 递归收集 (path, encField, idxField, cipher)
        //    限定只解 顶层 或 signInfo 子树中"以 Enc 结尾、对应有 XxxIdx"的字段；
        //    xxxIdx 仅作 SM4 加密 key 用，不回写到响应。
        $pending = [];
        $this->collectEncryptedPairs($res, '', $pending);

        if (empty($pending)) {
            return;
        }

        // 2) 聚合 + AES-256-ECB 包裹
        $payload = [];
        foreach ($pending as $p) {
            $payload[] = ['key' => $p['idxValue'], 'data' => $p['cipher']];
        }
        $plainJson  = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $cipherB64  = $this->aesEncryptEcbBase64($plainJson);   // 注：远端解密服务入参也是 AES 包裹
        $this->log->info('SM4 响应解密（idx 列表）: ' . json_encode(
            array_map(function ($p) { return $p['idxValue']; }, $payload),
            JSON_UNESCAPED_UNICODE
        ));

        // 3) POST 远端 SM4 解密服务
        $rawResp = self::httpPostRaw($decryptUrl, $cipherB64);
        $respData = json_decode($rawResp, true);
        if (!is_array($respData) || !isset($respData['code']) || (int)$respData['code'] !== 200 || !isset($respData['data']) || !is_array($respData['data'])) {
            throw new MonthPayException('SM4 远端解密失败：' . $rawResp);
        }

        // 5) 按 idx 把明文回填到原位置（xxxEnc 替换为明文，xxxIdx 删除）
        $idx2plain = [];
        foreach ($respData['data'] as $row) {
            if (isset($row['key'], $row['data'])) {
                $idx2plain[(string)$row['key']] = (string)$row['data'];
            }
        }
        foreach ($pending as $p) {
            if (!isset($idx2plain[$p['idxValue']])) {
                throw new MonthPayException("SM4 远端解密响应缺少 idx={$p['idxValue']} 的明文");
            }
            // 回填明文到 xxxEnc 字段
            $this->writeNestedField($res, $p['encPath'], $idx2plain[$p['idxValue']]);
            // 删除对应的 xxxIdx 字段
            $this->deleteNestedField($res, $p['idxPath']);
        }
    }

    /**
     * 递归收集响应中 (xxxEnc, xxxEncIdx 或 xxxIdx) 配对。
     * 仅在 顶层 / signInfo / signResult 子树中查找，避免误改其它业务字段。
     *
     * idx 字段名约定：
     *   - 优先 xxxEncIdx（如 userNameEncIdx = A001）
     *   - 回退 xxxIdx    （如 cardNoIdx    = B001，旧接口约定）
     */
    private function collectEncryptedPairs(array &$node, string $path, array &$pending): void
    {
        $allowedRoots = ['', 'signInfo', 'signResult'];
        if ($path !== '' && !in_array($path, $allowedRoots, true)) {
            return;
        }
        // 找出本层所有以 "Enc" 结尾的字段，并尝试配对对应 idx 字段
        foreach ($node as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (substr($key, -3) !== 'Enc') {
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            // 优先：XxxEncIdx
            $idxKey   = $key . 'Idx';
            $idxValue = $node[$idxKey] ?? null;
            if (!is_string($idxValue) || $idxValue === '') {
                // 回退：XxxIdx（去掉末尾 Enc 得 base，再加上 Idx）
                $base     = substr($key, 0, -3);
                $idxKey   = $base . 'Idx';
                $idxValue = $node[$idxKey] ?? null;
            }
            if (!is_string($idxValue) || $idxValue === '') {
                continue;
            }
            $pending[] = [
                'encPath'  => $path === '' ? $key : $path . '.' . $key,
                'idxPath'  => $path === '' ? $idxKey : $path . '.' . $idxKey,
                'idxValue' => $idxValue,
                'cipher'   => $value,
            ];
        }
        // 递归 signInfo / signResult 子树
        if (isset($node['signInfo']) && is_array($node['signInfo'])) {
            $this->collectEncryptedPairs($node['signInfo'], 'signInfo', $pending);
        }
        if (isset($node['signResult']) && is_array($node['signResult'])) {
            $this->collectEncryptedPairs($node['signResult'], 'signResult', $pending);
        }
    }

    /**
     * 向嵌套数组写入值。支持点分路径（"signInfo.cardNoEnc"）。
     */
    private function writeNestedField(array &$haystack, string $path, $value): void
    {
        $parts = explode('.', $path);
        $last  = array_pop($parts);
        $ref   = &$haystack;
        foreach ($parts as $p) {
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                $ref[$p] = [];
            }
            $ref = &$ref[$p];
        }
        $ref[$last] = $value;
    }

    /**
     * 删除嵌套数组中的字段（点分路径）。
     */
    private function deleteNestedField(array &$haystack, string $path): void
    {
        $parts = explode('.', $path);
        $last  = array_pop($parts);
        $ref   = &$haystack;
        foreach ($parts as $p) {
            if (!isset($ref[$p]) || !is_array($ref[$p])) {
                return;
            }
            $ref = &$ref[$p];
        }
        unset($ref[$last]);
    }

    public static function httpPostJson(string $url, array $data, array $headers = []): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $defaultHeaders = [
            'Content-Type: application/json;charset=utf-8',
            'Accept: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

        $output = curl_exec($ch);
        if ($output === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new MonthPayException('cURL 请求失败: ' . $err);
        }
        curl_close($ch);
        return $output;
    }

    /**
     * 生成 15 位纯数字 nonce（与 Java demo RandomUtil.randomNumbers(15) 对齐）
     */
    public static function generateNumericNonce(int $length = 15): string
    {
        $max = strlen(self::NUMERIC_CHARS) - 1;
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= self::NUMERIC_CHARS[mt_rand(0, $max)];
        }
        return $str;
    }

    public static function generateNonce(int $length = 16): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($chars) - 1;
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, $max)];
        }
        return $str;
    }
}