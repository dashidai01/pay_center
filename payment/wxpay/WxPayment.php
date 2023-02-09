<?php

namespace app\payment\wxpay;


use app\common\context\ContextPay;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\payment\PaymentInterface;
use app\payment\wxpay\lib\WxPayApi;
use app\validate\TradePayValidate;
use Exception;
use think\facade\Log;

class WxPayment extends WxPayBase implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array|mixed
     * @throws ApiException
     */
    public function pay(string $running_no): array {
        $param = ContextPay::getRaw();
        validate(TradePayValidate::class)->scene('wxPay')->check($param);
        try {
            $wxPayApi = new WxPayApi($this->config);
            /**
             * @var array $result 例如：
             *      [
             *           "appid" => "wx4d8cdghj546fdefcf"
             *           "code_url"  => "weixin://wxpay/bizpayurl?pr=lne7nbX"
             *           "mch_id"    => "1893325693"
             *           "nonce_str" => "AFTP1FO3BI92I50q"
             *           "prepay_id" => "wx10104408260479d0be286e08f258e00000"
             *           "result_code"   => "SUCCESS"
             *           "return_code"   => "SUCCESS"
             *           "return_msg"    => "OK"
             *           "sign"  => "2E6DC4293CF0735D3454E70741CDEFF9E9744959C1763BF3FC7721B6F8339CD2"
             *           "trade_type" => "NATIVE"
             *       ]
             */
            $result = $wxPayApi->nativePay($running_no);
            Log::record(json_encode($result));
            if (!isset($result['result_code']) || $result['result_code'] != 'SUCCESS') {
                throw new ApiException("调用微信支付失败，请核验参数后，重试");
            }
            // 微信H5 支付
            $tradePayDto = ContextPay::getTradePayDto();

            // H5支付
            if (ContextPay::getMixed() == OrderEnum::CLIENT_H5) {
                $return_url = urlencode($tradePayDto->return_url);
                $url        = $result['mweb_url'] . '&redirect_url=' . $return_url;
                return [
                    'code_data' => $url,
                ];
            }
            // 小程序
            if (ContextPay::getMixed() == OrderEnum::CLIENT_PROCESS) {
                return [
                    'code_data' => $result['code_data'],
                ];
            }

            return [
                'code_data' => $result['code_url'],
            ];
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }


    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function refund(string $running_no): array {
        try {
            $wxPayApi = new WxPayApi($this->config);
            $result   = $wxPayApi->refund($running_no);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    public function dispatch(string $running_no): array {
        // TODO: Implement dispatch() method.
    }

    /**
     * 申请电子回单
     * @return array
     */
    public function applyReceipt(): array {
        // TODO
    }

    /**
     * 查看电子回单
     * @return array
     */
    public function searchReceipt(): array {
        // TODO
    }

    /**
     * 查询账单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery(): array {
        try {
            $wxPayApi = new WxPayApi($this->config);
            $result   = $wxPayApi->commonQuery();

            Log::record(json_encode($result));
            if (!isset($result['result_code']) || $result['result_code'] != 'SUCCESS') {
                throw new ApiException("查询微信账单失败");
            }

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
    /**
     * @return array
     * @throws ApiException
     */
    public function commonReceipt(): array {
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }
}