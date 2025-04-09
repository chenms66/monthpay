<?php

namespace BaiGe\MonthPay\Validator;

use BaiGe\MonthPay\Exceptions\MonthPayException;

class Validator
{
    /**
     * 校验必填字段
     *
     * @param array $data
     * @param array $requiredFields
     * @throws MonthPayException
     */
    public static function validateRequiredFields(array $data, array $requiredFields)
    {
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new MonthPayException("字段 '{$field}' 不能为空");
            }
        }
    }

}
