<?php

namespace BaiGe\MonthPay\Gateways\V1;

use BaiGe\MonthPay\Exceptions\MonthPayException;
use BaiGe\MonthPay\Gateways\AbstractGateway;
use BaiGe\MonthPay\Support\HelipayRequestBuilder;
use BaiGe\MonthPay\Support\Utils;
use BaiGe\MonthPay\Validator\Validator;
use Exception;

/**
 * 合利宝支付网关
 *
 * 支持接口：
 * - 4.4 鉴权绑卡预下单
 * - 4.5 鉴权绑卡短信
 * - 4.6 鉴权绑卡
 * - 4.7 绑卡支付预下单
 * - 4.9 绑卡支付
 * - 4.13 用户绑定银行卡信息查询
 * - 4.15 一键绑卡
 * - 3.1 快捷退款
 */
class HelipayGateway extends AbstractGateway
{
    /** 鉴权绑卡预下单交易类型 */
    const BIZ_TYPE_BIND_CARD_PRE_ORDER = 'QuickPayBindCardPreOrder';

    /** 鉴权绑卡交易类型 */
    const BIZ_TYPE_CONFIRM_BIND_CARD = 'ConfirmBindCard';

    /** 绑卡支付预下单交易类型 */
    const BIZ_TYPE_BIND_PAY_PRE_ORDER = 'QuickPayBindPayPreOrder';

    /** 绑卡支付交易类型 */
    const BIZ_TYPE_CONFIRM_BIND_PAY = 'ConfirmBindPay';

    /** 快捷退款交易类型 */
    const BIZ_TYPE_QUICK_PAY_REFUND = 'QuickPayRefund';

    /** 一键绑卡交易类型 */
    const BIZ_TYPE_QUICK_BIND_CARD = 'QuickBindCard';

    /** 用户绑定银行卡信息查询交易类型 */
    const BIZ_TYPE_BANK_CARD_BIND_LIST = 'BankCardbindList';

    /** 借记卡 */
    const DEBIT_CARD = 2;

    /** 信用卡 */
    const CREDIT_CARD = 1;

    /** @var HelipayRequestBuilder */
    private $builder;

    /**
     * 构造方法
     */
    public function __construct(array $config, string $logPath)
    {
        parent::__construct('helipay', $config, $logPath);
        $this->builder = new HelipayRequestBuilder($config, $this->log);
    }

    /**
     * H5 签约（鉴权绑卡预下单）
     */
    public function h5Sign(array $params)
    {
        return $this->bindCardPreOrder($params);
    }

    /**
     * 微信签约（鉴权绑卡预下单）
     */
    public function wxSign(array $params)
    {
        return $this->bindCardPreOrder($params);
    }

    /**
     * 协议扣款
     */
    public function deductMoney(array $params)
    {
        return $this->bindCardPayPreOrder($params);
    }

    /**
     * 4.4 鉴权绑卡预下单
     */
    public function bindCardPreOrder(array $params)
    {
        Validator::validateRequiredFields($params, [
            't_paper_num',
            'num_id',
            't_name',
            'card_no',
            't_tel',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_BIND_CARD_PRE_ORDER,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_userId' => $params['t_paper_num'],
                'P4_orderId' => $params['num_id'],
                'P5_timestamp' => date('YmdHis'),
                'P6_payerName' => $params['t_name'],
                'P7_idCardType' => $this->config['id_card_type'] ?? 'IDCARD',
                'P8_idCardNo' => $params['t_paper_num'],
                'P9_cardNo' => $params['card_no'],
                'P10_year' => $params['year'] ?? null,
                'P11_month' => $params['month'] ?? null,
                'P12_cvv2' => $params['cvv2'] ?? null,
                'P13_phone' => $params['t_tel'],
                'sendValidateCode' => isset($params['send_sms']) ? $params['send_sms'] : 'TRUE',
                'protocolType' => $this->config['protocol_type'] ?? 'protocol',
                'signatureType' => 'SM3WITHSM2',
            ];

            return $this->builder->buildAndSend(
                $fields,
                ['P8_idCardNo', 'P9_cardNo', 'P10_year', 'P11_month', 'P12_cvv2', 'P13_phone'],
                ['sendValidateCode', 'signatureType', 'protocolType'],
                'bindCardPreOrder'
            );

        } catch (Exception $e) {
            $this->log->error('鉴权绑卡预下单失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 4.6 鉴权绑卡
     */
    public function signingEndpoint(array $params)
    {
        Validator::validateRequiredFields($params, [
            'unique_code',
            'code',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_CONFIRM_BIND_CARD,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_orderId' => $params['unique_code'],
                'P4_timestamp' => date('YmdHis'),
                'P5_validateCode' => $params['code'],
                'signatureType' => 'SM3WITHSM2',
            ];

            return $this->builder->buildAndSend(
                $fields,
                ['P5_validateCode'],
                ['signatureType'],
                'bindCard'
            );

        } catch (Exception $e) {
            $this->log->error('鉴权绑卡失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 4.7 绑卡支付预下单
     */
    public function bindCardPayPreOrder(array $params)
    {
        Validator::validateRequiredFields($params, [
            'agreement_no',
            't_paper_num',
            'out_trade_no',
            'expect_money',
            't_tel',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_BIND_PAY_PRE_ORDER,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_bindId' => $params['agreement_no'],
                'P4_userId' => $params['t_paper_num'],
                'P5_orderId' => $params['out_trade_no'],
                'P6_timestamp' => date('YmdHis'),
                'P7_currency' => $this->config['currency'] ?? 'CNY',
                'P8_orderAmount' => $params['expect_money'],
                'P9_goodsName' => $params['goods_name'] ?? '保费',
                'P10_goodsDesc' => $params['goods_desc'] ?? '',
                'P11_terminalType' => 'OTHER',
                'P12_terminalId' => '122121212121',
                'P13_orderIp' => Utils::ip(),
                'P14_period' => $params['period'] ?? '',
                'P15_periodUnit' => $params['period_unit'] ?? '',
                'P16_serverCallbackUrl' => $this->config['callback'],
                'sendValidateCode' => isset($params['send_sms']) ? $params['send_sms'] : 'TRUE',
                'goodsQuantity' => '1',
                'userAccount' => $params['t_tel'],
                'appType' => $this->config['app_type'] ?? 'OTHER',
                'appName' => $this->config['app_name'] ?? '白鸽宝保险经纪有限公司',
                'dealSceneType' => $this->config['deal_scene_type'] ?? 'INSURANCE',
                'signatureType' => 'SM3WITHSM2',
                'lbs' => $params['lbs'] ?? '',
                'dealSceneParams' => $params['deal_scene_params'] ?? '',
            ];

            $res = $this->builder->send(
                $fields,
                ['sendValidateCode', 'signatureType', 'goodsQuantity', 'userAccount', 'appType', 'appName', 'dealSceneType', 'lbs', 'dealSceneParams'],
                'bindCardPayPreOrder'
            );

            return $this->bindCardPay(['out_trade_no' => $params['out_trade_no']]);

        } catch (Exception $e) {
            $this->log->error('绑卡支付预下单失败：' . $e->getMessage());
            $channelCode = $e instanceof MonthPayException ? $e->getChannelCode() : null;
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e, $channelCode);
        }
    }

    /**
     * 4.9 绑卡支付
     * @throws MonthPayException
     */
    public function bindCardPay(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_CONFIRM_BIND_PAY,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_orderId' => $params['out_trade_no'],
                'P4_timestamp' => date('YmdHis'),
                'P5_validateCode' => isset($params['code']) ? $params['code'] : '',
                'signatureType' => 'SM3WITHSM2',
            ];

            $encryptFields = [];

            // 有验证码时才需要 SM4 加密，encryptionKey 仅在有加密字段时才生成
            if (isset($params['code'])) {
                $encryptFields = ['P5_validateCode'];
            }

            return $this->builder->buildAndSend(
                $fields,
                $encryptFields,
                ['signatureType'],
                'bindCardPay'
            );

        } catch (Exception $e) {
            $this->log->error('绑卡支付失败：' . $e->getMessage());
            $channelCode = $e instanceof MonthPayException ? $e->getChannelCode() : null;
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e, $channelCode);
        }
    }

    /**
     * 4.15 一键绑卡
     */
    public function bankSign(array $params)
    {
        Validator::validateRequiredFields($params, [
            't_paper_num',
            'out_trade_no',
            't_name',
            'bank_no',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_QUICK_BIND_CARD,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_userId' => $params['t_paper_num'],
                'P4_orderId' => $params['out_trade_no'],
                'P5_timestamp' => date('YmdHis'),
                'P6_payerName' => $params['t_name'],
                'P7_idCardType' => $this->config['id_card_type'] ?? 'IDCARD',
                'P8_idCardNo' => $params['t_paper_num'],
                'P9_phone' => $params['t_tel'] ?? '',
                'P10_bankId' => $params['bank_no'],
                'P11_onlineCardType' => $params['card_type'] == self::DEBIT_CARD ? 'DEBIT' : 'CREDIT',
                'P12_serverCallbackUrl' => $this->config['callback'],
                'P13_bankRdrctToMchUrl' => $this->config['return_url'] ?? '',
                'signatureType' => 'SM3WITHSM2',
            ];

            return $this->builder->buildAndSend(
                $fields,
                ['P8_idCardNo', 'P9_phone'],
                ['signatureType'],
                'bankSign'
            );

        } catch (Exception $e) {
            $this->log->error('一键绑卡失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 4.13 用户绑定银行卡信息查询（仅限于交易卡）
     */
    public function silentSign(array $params)
    {
        Validator::validateRequiredFields($params, [
            't_paper_num',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_BANK_CARD_BIND_LIST,
                'P2_customerNumber' => $this->config['mch_id'],
                'P3_userId' => $params['t_paper_num'],
                'P4_bindId' => $params['bind_id'] ?? '',
                'P5_timestamp' => date('YmdHis'),
                'signatureType' => 'SM3WITHSM2',
            ];

            return $this->builder->send(
                $fields,
                ['signatureType'],
                'silentSign'
            );

        } catch (Exception $e) {
            $this->log->error('用户绑定银行卡信息查询失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 3.1 快捷退款
     */
    public function commonRefund(array $params)
    {
        Validator::validateRequiredFields($params, [
            'out_trade_no',
            'refund_no',
            'refund_amount',
        ]);

        try {
            $fields = [
                'P1_bizType' => self::BIZ_TYPE_QUICK_PAY_REFUND,
                'P2_orderId' => $params['out_trade_no'],
                'P3_customerNumber' => $this->config['mch_id'],
                'P4_refundOrderId' => $params['refund_no'],
                'P5_amount' => $params['refund_amount'],
                'signatureType' => 'SM3WITHSM2',
            ];

            if (!empty($this->config['pay_callback'])) {
                $fields['P6_callbackUrl'] = $this->config['pay_callback'];
            }

            return $this->builder->send(
                $fields,
                ['signatureType'],
                'commonRefund'
            );

        } catch (Exception $e) {
            $this->log->error('快捷退款失败：' . $e->getMessage());
            throw new MonthPayException($e->getMessage(), $e->getCode(), $e);
        }
    }

}
