<?php

namespace BaiGe\MonthPay\Support;


class SHA1Util
{
    /**
     * @param $Strings
     * @return false|string
     * SHA1摘要
     */
    public static function Sha1AndHex($Strings)
    {
        return openssl_digest($Strings, "SHA1");
    }
}