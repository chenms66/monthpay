<?php

namespace BaiGe\MonthPay\Support;

use Exception;

class Tools
{

    /**
     * @return false|int
     * 生成时间戳
     */
    public static function getTransid()
    {
        return strtotime(date('Y-m-d H:i:s', time()));
    }

    /**
     * @return int
     * 生成四位随机数
     */
    public static function getRand4()
    {
        return rand(1000, 9999);
    }

    /**
     * @param $datef
     * @return false|string
     * 生成四位随机数
     */
    public static function getTime($datef = "Y-m-d H:i:s")
    {
        return date($datef, time());
    }

    /**
     * @param $DArray
     * @return string
     * 排序输出k=v&k1=v1.....格式
     */
    public static function SortAndOutString($DArray)
    {
        $TempData = array();
        foreach ($DArray as $Key => $Value) {
            if (!self::isBlank($Value)) {
                $TempData[$Key] = $Value;
            }
        }
        ksort($TempData);//排序
        return http_build_query($TempData);
    }

    /**
     * @param $Strings
     * @return bool
     * 判断是否空值
     */
    public static function isBlank($Strings)
    {
        $Strings = trim($Strings);
        if ((empty($Strings) || ($Strings == null)) && (strlen($Strings) <= 0)) {
            return true;
        } else {
            return FALSE;
        }
    }

    /**
     * @param $data
     * @param $key
     * @return mixed
     * 删除数组元素
     */
    public static function array_remove($data, $key)
    {
        if (!array_key_exists($key, $data)) {
            return $data;
        }
        $keys = array_keys($data);
        $index = array_search($key, $keys);
        if ($index !== FALSE) {
            array_splice($data, $index, 1);
        }
        return $data;
    }

    /**
     * @param $Strings
     * @return string
     * 获取信封中的key值
     * @throws Exception
     */
    public static function getAesKey($Strings)
    {
        $KeyArray = explode("|", $Strings);
        if (count($KeyArray) == 2) {
            $KeyArray[1] = trim($KeyArray[1]);
            if (!empty($KeyArray[1])) {
                return $KeyArray[1];
            } else {
                throw new Exception("Key is Null!");
            }
        } else {
            throw new Exception("Data format is incorrect!");
        }
    }

}

