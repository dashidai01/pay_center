<?php

namespace app\payment\llpay\lib;

use app\common\context\ContextPay;
use App\Common\Enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\tool\SnowFlake;
use app\common\vo\TradeRefundVo;
use app\model\Order;
use Exception;
use GuzzleHttp\Client;
use think\facade\Env;
use think\facade\Log;

/**
 * Class LlRefundApi
 * @package App\Payment\Llpay
 */
class LlRefundApi
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refund(string $running_no): array {
        try {
            $url = 'https://traderapi.lianlianpay.com/refund.htm';

            $json   = $this->getOpitons($running_no);
            $result = $this->request($url, $json);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * 退款 整合连连银通及聚合退款
     * @param $running_no
     * @return TradeRefundVo|array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function allRefund($running_no) {

        $order = ContextPay::getOrder();
        if (empty($order->pay_type)) {
            // 非聚合退款
            return $this->refund($running_no);
        } else {
            // 聚合退款
            return $this->unionRefund($running_no);
        }

    }

    public function getOpitons(string $running_no): array {
        $tradeRefundDto = ContextPay::getTradeRefundDto();
        $orderModel     = ContextPay::getOrder();
        $accountModel   = ContextPay::getAccount();

        $money_refund = (string)(($tradeRefundDto->refund_money) / 100);
        $timestamp    = date('YmdHis');
        $params       = [
            "oid_partner"  => $accountModel->appid,
            "money_refund" => $money_refund,
            "no_refund"    => $running_no,
            "dt_refund"    => $timestamp,
            "oid_paybill"  => $orderModel->channel_running_no,
            "notify_url"   => Env::get('PAYMENT.LLPAY_NOTIFY'),
            "sign_type"    => "RSA",
        ];

        $str            = buildSortParams($params);
        $params['sign'] = addSign($str, $accountModel->business_private_rsa);

        return $params;
    }


    /**
     * @param string $url
     * @param array $json
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request(string $url, array $json): array {
        try {
            $client = new Client();

            $options = [
                'headers' => [
                    'Content-type' => 'application/json;charset=utf-8'
                ],
                'json'    => $json,
                'timeout' => 20,
            ];

            Log::record('llpay refund params: ' . json_encode($json));

            $response = $client->post($url, $options);
            $body     = $response->getBody()->getContents();
            Log::record('llpay refund result: ' . $body);
            $result = json_decode($body, true);
            if (!isset($result['ret_code']) || ($result['ret_code'] != '0000')) {
                throw new ApiException("调用统一创单接口失败：" . $result['ret_msg']);
            }
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function getUnionOptions(string $running_no): array {
        $tradeRefundDto = ContextPay::getTradeRefundDto();
        $orderModel     = ContextPay::getOrder();
        $accountModel   = ContextPay::getAccount();

        $money_refund = (string)(($tradeRefundDto->refund_money) / 100);
        $timestamp    = date('YmdHis');
        $params       = [
            "timestamp"     => $timestamp,
            "oid_partner"   => $accountModel->appid,
            "user_id"       => $orderModel->user_no,
            "notify_url"    => Env::get('PAYMENT.DOMAIN') . '/notify/pay/unionRefund',
            "refund_reason" => $tradeRefundDto->explanation,
        ];
        /** 原商户订单信息 */
        $params['originalOrderInfo'] = [
            "txn_seqno"    => $tradeRefundDto->pay_running_no,
            "total_amount" => (string)($orderModel->money / 100)
        ];
        /** 退款订单信息 */
        $params['refundOrderInfo'] = [
            "refund_seqno"  => $running_no,
            "refund_time"   => $timestamp,
            "refund_amount" => $money_refund
        ];
        /** 原收款方退款信息 */
        if ($orderModel->payee_type == OrderEnum::PAY_LL_PERSON) {
            // 个人
            $params['pyeeRefundInfos'] = [
                "payee_id"            => $orderModel->payee_id,
                "payee_type"          => "USER",
                "payee_accttype"      => "USEROWN",
                "payee_refund_amount" => $money_refund
            ];
        } else {
            // 商户
            $params['pyeeRefundInfos'] = [
                "payee_id"            => $accountModel->appid,
                "payee_type"          => "MERCHANT",
                "payee_accttype"      => "MCHOWN",
                "payee_refund_amount" => $money_refund
            ];
        }

        $method = $orderModel->ll_method_type ? $orderModel->ll_method_type :  OrderEnum::getPayType($orderModel->pay_type);
        /** 原付款方式 */
        $params['refundMethods'] = [
            [
                "method" => $method,
                "amount" => $money_refund
            ]
        ];

        return $params;
    }

    /**
     * @param  string
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unionRefund($running_no) {
        try {
            // 聚合支付退款
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/txn/more-payee-refund';
            $json = $this->getUnionOptions($running_no);

            $result = $this->requestUnion($url, $json);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @param string $url
     * @param array $json
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function requestUnion(string $url, array $json): array {
        try {
            $client = new Client();

            $options = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];

            Log::write('连连聚合支付退款参数: ' . json_encode($json));

            $response = $client->post($url, $options);
            $body     = $response->getBody()->getContents();
            Log::write('连连聚合支付退款返回值: ' . $body);
            $result = json_decode($body, true);
            if (!isset($result['ret_code']) || ($result['ret_code'] != '0000')) {
                throw new ApiException("调用退款接口失败：" . $result['ret_msg']);
            }
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    public function sign($data) {
        $account = ContextPay::getAccount();
        $res     = openssl_get_privatekey($account->business_private_rsa);

        //调用openssl内置签名方法，生成签名$sign
        openssl_sign(md5(json_encode($data)), $sign, $res, OPENSSL_ALGO_MD5);

        //释放资源
        openssl_free_key($res);

        //base64编码
        $sign = base64_encode($sign);

        return $sign;
    }
}
