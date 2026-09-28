<?php

namespace BaiGe\MonthPay\Exceptions;

class MonthPayException extends \Exception
{
    /**
     * 渠道业务码（如 errCode / respCode），与 $code 区分：
     *   - $code          系统侧错误码（HTTP/网关异常等）
     *   - $channelCode   渠道原始返回的业务码
     */
    protected $channelCode;

    /**
     * 渠道原始响应数据（数组）。失败时由 handleResponse 注入，
     * 调用方可通过 getResponse() 拿到完整报文，便于排查。
     */
    protected $response;

    public function __construct(
        $message = "",
        $code = 0,
        $previous = null,
        $channelCode = null,
        $response = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->channelCode = $channelCode;
        $this->response = $response;
    }

    public function getChannelCode()
    {
        return $this->channelCode;
    }

    /**
     * 获取渠道原始响应数据（数组形态）。
     * 成功路径调用方直接拿到返回值；仅在异常分支由 SDK 自动注入。
     */
    public function getResponse(): array
    {
        return is_array($this->response) ? $this->response : [];
    }

    /**
     * 设置/覆盖渠道原始响应数据。
     * 主要给 catch 块使用：捕获到 MonthPayException 后可补充上游响应。
     */
    public function setResponse(array $response): self
    {
        $this->response = $response;
        return $this;
    }
}