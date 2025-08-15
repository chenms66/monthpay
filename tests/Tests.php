<?php

use BaiGe\MonthPay\Support\Utils;
use PHPUnit\Framework\TestCase;
class Tests extends TestCase{
    public function test()
    {
        /**************************************度小满*******************************************/
        $config = [
            'authorization_url' => 'https://qatest.dxmpay.com/cashdesk/service/easypayapi/verify',//请求授权，发送短信
            'sp_no'=>'',
            'key'=>'',
            'sign_url' => 'https://qatest.dxmpay.com/cashdesk/service/easypayapi/sign',//签约
            'pay_url' => 'https://qatest.dxmpay.com/cashdesk/service/easypayapi/pay',//支付
            'cancel_url' => 'https://qatest.dxmpay.com/cashdesk/service/easypayapi/cancelcontract',//绑定解除
            'query_sign_url' => 'https://qatest.dxmpay.com/cashdesk/service/easypayapi/querybeforepay',//签约查询
            'refund_url' => 'https://qatest.dxmpay.com/api/0/refund',//申请退款
            'callback'=>'https://liuliangtf.baigebaodev.com/api/Callback/sign_notify_url',//支付结果地址
            'pay_callback'=>'https://liuliangtf.baigebaodev.com/api/Callback/sign_notify_url',//退款结果地址
            'silent_signing_url'=>'https://qatest.dxmpay.com/cashdesk/service/easypayapi/silentsign',//静默签约
        ];
        $logPath = __DIR__ . '/logs';
        $MonthPay = new \BaiGe\MonthPay\MonthPay('dxm',$config,$logPath);
        $params = [
            'card_type'=> '',
            'card_no'=>'',
            't_name' => '',//投保人
            't_paper_type'=>'',
            't_paper_num'=>'',
            't_tel'=>'',
            'code'=>'',
        ];
//        $res = $MonthPay->gateway()->authEndpoint($params);
//        $res = $MonthPay->gateway()->signingEndpoint($params);
//        $params = ['out_trade_no'=>''.time(), 'agreement_no'=>'', 'goods_name'=>'测试扣款', 'expect_money'=>'0.01'];
//        $res = $MonthPay->gateway()->deductMoney($params);
//        $params = ['agreement_no'=>''];
//        $res = $MonthPay->gateway()->relieveAppoint($params);

//        $params = ['agreement_no'=>''];
//        $res = $MonthPay->gateway()->querySignUrl($params);

//        $res = $MonthPay->gateway()->silentSign($params);

//        $params = ['out_trade_no'=>'', 'refund_no'=>time(), 'refund_amount'=>'0.01'];
//        $res = $MonthPay->gateway()->commonRefund($params);
//        var_dump($res);

        /**************************************微信签约*******************************************/
        /**************************************银行卡*******************************************/
//        $config = [
//            'merchant_code'=>'',//商户号
//            'client_id'=>'',//应用ID
//            'client_secret'=>'',//应用秘钥
//            'token_url' => '',//token
//            'url' => '',//绑卡申请
//            'pay_url' => '',//银行卡支付
//            'cancel_url' => '',//银行卡绑定解除
//            'refund_url' => '',//申请退款
//            'channel_code'=>'',
//            'client_notify_url'=>'',
//            'front_notify_url'=>'',
//        ];
//        $logPath = __DIR__ . '/logs';
//        $MonthPay = new \BaiGe\MonthPay\MonthPay('bank',$config,$logPath);
//        $params = [
//            'card_type'=> '0',//0=借记卡 1=信用卡
//            'num_id' => rand(10000000, 999999999) . 'T1T' . rand(1, 99999),//回调订单号
//            't_name' => '',//投保人
//            'txn_type'=>'MPOS',
//            't_paper_num'=>'',
//            't_tel'=>'',
//            'bank_name'=>'民生银行',
//        ];
//        $res = $MonthPay->h5Sign($params);
//        var_dump($res);

   /**************************************微信签约*******************************************/
//        $config = [
//            'notify_url' => '',//签约回调,解约回调
//            'callback' => '',//微信支付回调
//            'appid' => '',  // 公众账号ID
//            'cert_key' => '',//pem文件
//            'key' => '',
//            'serial_no' => '',
//            'mch_id' => '',// 商户号
//        ];
//        $logPath = __DIR__ . '/logs';
//        $MonthPay = new \BaiGe\MonthPay\MonthPay('wechat',$config,$logPath);
//        $num_id = rand(10000000, 999999999);
//        $params = [
//            'out_contract_code' => rand(10000000, 999999999) . 'T1T' . rand(1, 99999),//回调订单号
//            'b_name' => '测试',//被保人
//            's_time' => '2025-04-11',//起保时间
//            'e_time' => '2026-04-10',//终止时间
//            'out_user_code' => $num_id . 'T' . rand(1, 999),//在多账号签约场景下使用
//            'renewal_status' => true,
//            'withhold_log' => [
//                [
//                    'policy_period_id' => 1,//期数
//                    'estimated_deduct_date' => '2025-04-10',//开始时间
//                    'estimated_deduct_amount' => [
//                        'total' => 1,//分
//                    ]
//                ],
//                [
//                    'policy_period_id' => 2,
//                    'estimated_deduct_date' => '2025-05-11',
//                    'estimated_deduct_amount' => [
//                        'total' => 1,//分
//                    ]
//                ]
//            ],
//            'plan_id' => '',//模板id
//            //签约就扣款必须
////            'expect_money'=>'0.01',//元
////            'out_trade_no'=>rand(1000000000,99999999999),//【商户订单号】 若商户希望在进行签约后立即进行首期自动续费，必须传入商户系统内部订单号。只能是数字、大小写字母_-*且在同一个商户号下唯一
//        ];
//        $res = $MonthPay->h5Sign($params);
//        var_dump($res);

   /**************************************微信签约*******************************************/
//        $config = [
//            'notify_url' => '',//签约回调,解约回调
//            'callback' => '',//微信支付回调
//            'appid' => '',  // 公众账号ID
//            'cert_key' => '',//pem文件
//            'key' => '',
//            'serial_no' => '',
//            'mch_id' => '',// 商户号
//        ];
//        $logPath = __DIR__ . '/logs';
//        $MonthPay = new \BaiGe\MonthPay\MonthPay('wechat',$config,$logPath);
//        $num_id = rand(10000000, 999999999);
//        $params = [
//            'out_contract_code' => rand(10000000, 999999999) . 'T1T' . rand(1, 99999),//回调订单号
//            'b_name' => '测试',//被保人
//            's_time' => '2025-05-24',//起保时间
//            'e_time' => '2026-05-23',//终止时间
//            'out_user_code' => $num_id . 'T' . rand(1, 999),//在多账号签约场景下使用
//            'renewal_status' => true,
//            'withhold_log' => [
//                [
//                    'policy_period_id' => 1,//期数
//                    'estimated_deduct_date' => '2025-05-24',//开始时间
//                    'estimated_deduct_amount' => [
//                        'total' => 1,//分
//                    ]
//                ],
//                [
//                    'policy_period_id' => 2,
//                    'estimated_deduct_date' => '2025-06-24',
//                    'estimated_deduct_amount' => [
//                        'total' => 1,//分
//                    ]
//                ]
//            ],
//            'plan_id' => '',//模板id
//            'openid'=>'',
//            //签约就扣款必须
////            'expect_money'=>'0.01',//元
////            'out_trade_no'=>rand(1000000000,99999999999),//【商户订单号】 若商户希望在进行签约后立即进行首期自动续费，必须传入商户系统内部订单号。只能是数字、大小写字母_-*且在同一个商户号下唯一
//        ];
//        var_dump($MonthPay->gateway()->wxMini($params));
    }
}