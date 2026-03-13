<?php

namespace BaiGe\MonthPay\Exceptions;

class MonthPayException extends \Exception
{
    protected $channelCode;
    
    public function __construct($message = "", $code = 0, $previous = null, $channelCode = null)
    {
        parent::__construct($message, $code, $previous);
        $this->channelCode = $channelCode;
    }
    
    public function getChannelCode()
    {
        return $this->channelCode;
    }
}
