<?php

namespace app\payment\wxpay\lib;


use app\common\context\ContextPay;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\tool\AliOss;
use Exception;
use GuzzleHttp\Client;
use think\facade\Env;
use think\facade\Log;


/**
 * Class WxPayApi
 * @package app\payment\wxpay\lib
 */
class WxPayApi
{
    /**
     * @var WxPayConfig
     */
    private $config;

    /**
     * @var WxPayData
     */
    private $wxPayData;

    public function __construct(WxPayConfig $config) {
        $this->config    = $config;
        $this->wxPayData = new WxPayData($config);
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function nativePay(string $running_no): array {
        $config     = $this->config;
        $url        = "https://api.mch.weixin.qq.com/pay/unifiedorder";
        $notify_url = Env::get('PAYMENT.WXPAY_NOTIFY');

        $tradePayDto = ContextPay::getTradePayDto();

        $total_fee = (int)$tradePayDto->money;

        $nonce_str = getRandomStr(32, false);
        $params    = [
            'appid'            => $config->appid,
            'mch_id'           => $config->merchantId,
            'nonce_str'        => $nonce_str,
            'sign_type'        => $config->signType,
            'body'             => $tradePayDto->goods_name,
            'out_trade_no'     => $running_no,
            'total_fee'        => $total_fee,
            'spbill_create_ip' => gethostbyname($_SERVER['SERVER_NAME']),
            'notify_url'       => $notify_url,
            'trade_type'       => 'NATIVE',
        ];

        if (ContextPay::getMixed() == OrderEnum::CLIENT_H5) {
            $params['trade_type'] = 'MWEB';
        }
        if (ContextPay::getMixed() == OrderEnum::CLIENT_PROCESS) {
            $params['trade_type'] = 'JSAPI';
            $params['appid']      = $tradePayDto->appid;
            $params['openid']     = $tradePayDto->openid;
        }

        //检测必填参数
        $wxPayData      = $this->wxPayData;
        $sign           = $wxPayData->makeSign($params);
        $params['sign'] = $sign;
        $xml            = $this->toXml($params);
        $client         = new Client();

        // 配置证书
        $cert = $this->getCert($config);

        $options     = [
            'body'    => $xml,
            'cert'    => $cert['cert'],
            'ssl_key' => $cert['ssl_key'],
            'timeout' => 6,
        ];
        $response    = $client->post($url, $options);
        $content_xml = $response->getBody()->getContents();

        // 清理证书
        $this->clearCert($cert);

        $result = $this->dealResponse($content_xml);
        // 微信小程序支付
        if (ContextPay::getMixed() == OrderEnum::CLIENT_PROCESS) {
            $timeStamp         = time();
            $data['appid']     = $tradePayDto->appid;
            $data['nonceStr']  = $nonce_str;
            $data['package']   = 'prepay_id=' . $result['prepay_id'];
            $data['signType']  = $config->signType;
            $data['timeStamp'] = $timeStamp;


            $paySign                  = $wxPayData->makeSign($data);
            $return_data['appid']     = $tradePayDto->appid;
            $return_data['timeStamp'] = $timeStamp;
            $return_data['nonceStr']  = $nonce_str;
            $return_data['package']   = 'prepay_id=' . $result['prepay_id'];
            $return_data['signType']  = $config->signType;
            $return_data['paySign']   = $paySign;

            $result['code_data'] = json_encode($return_data);
        }
        return $result;
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function refund(string $running_no): array {
        $url           = "https://api.mch.weixin.qq.com/secapi/pay/refund";
        $refund_notify = Env::get('PAYMENT.WXPAY_NOTIFY');
        try {
            $tradeRefundDto = ContextPay::getTradeRefundDto();
            $order          = ContextPay::getOrder();

            $refund_fee = (int)$tradeRefundDto->refund_money;
            $config     = $this->config;
            $params     = [
                'appid'          => $config->appid,
                'mch_id'         => $config->merchantId,
                'nonce_str'      => getRandomStr(32, false),
                'sign_type'      => $config->signType,
                'transaction_id' => $order->channel_running_no,
                'out_refund_no'  => $running_no,
                'total_fee'      => $order->money,
                'refund_fee'     => $refund_fee,
                'notify_url'     => $refund_notify,
            ];

            //检测必填参数
            $sign           = $this->wxPayData->makeSign($params);
            $params['sign'] = $sign;
            $xml            = $this->toXml($params);
            $client         = new Client();

            // 配置证书
            $cert = $this->getCert($config);

            $options = [
                'body'    => $xml,
                'cert'    => $cert['cert'],
                'ssl_key' => $cert['ssl_key'],
                'timeout' => 6,
            ];

            $response    = $client->post($url, $options);
            $content_xml = $response->getBody()->getContents();

            // 清理证书
            $this->clearCert($cert);

            $result = $this->dealResponse($content_xml);
            if (!$result) {
                throw new ApiException('数据异常');
            }

            $result_code = $result['result_code'] ?? '';
            if ($result_code != 'SUCCESS') {
                $err_code_des = $result['err_code_des'] ?? '微信退款数据异常';
                throw new ApiException($err_code_des);
            }

            return $result;

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param WxPayConfig $config
     * @return array
     * @throws \app\common\exception\ApiException
     */
    public function getCert(WxPayConfig $config) {
        $aliOss      = new AliOss();
        $sslCertPath = $aliOss->getFile($config->sslCertPath);
        $sslKeyPath  = $aliOss->getFile($config->sslKeyPath);

        return [
            'ssl_key' => $sslKeyPath,
            'cert'    => $sslCertPath,
        ];
    }

    /**
     * @param array $cert
     */
    public function clearCert(array $cert): void {
        try {
            unlink($cert['cert']);
            unlink($cert['ssl_key']);
        } catch (Exception $e) {
            Log::record("cert clear is fail, " . $e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return string
     * @throws ApiException
     */
    public function toXml(array $params) {
        if (!is_array($params) || count($params) <= 0) {
            throw new ApiException("数组数据异常！");
        }

        $xml = "<xml>";
        foreach ($params as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<" . $key . ">" . $val . "</" . $key . ">";
            } else {
                $xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
            }
        }
        $xml .= "</xml>";
        return $xml;
    }

    /**
     * @param string $xml
     * @return array
     * @throws ApiException
     */
    public function decodeXml(string $xml): array {
        if (!$xml) {
            throw new ApiException("xml数据异常！");
        }
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $arr = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $arr;
    }

    /**
     * @param $xml
     * @return bool
     * @throws ApiException
     */
    public function dealResponse($xml): array {
        $response = $this->decodeXml($xml);

        //失败则直接返回失败
        if ($response['return_code'] != 'SUCCESS') {
            foreach ($response as $key => $value) {
                #除了return_code和return_msg之外其他的参数存在，则报错
                if ($key != "return_code" && $key != "return_msg") {
                    throw new ApiException("输入数据存在异常！");
                }
            }
            if ($response['return_code'] == 'FAIL') {
                throw new ApiException($response['return_msg'] ?? '微信支付失败!');
            }
            return $response;
        }

        $wxPayData = $this->wxPayData;
        $bool      = $wxPayData->checkSign($response);
        if (!$bool) {
            throw new ApiException("签名错误");
        }
        return $response;

    }

    /**
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery(): array {

        $url = "https://api.mch.weixin.qq.com/pay/orderquery";
        try {
            $order = ContextPay::getOrder();
            if ($order->source == OrderEnum::SOURCE_OUT) {
                $url = "https://api.mch.weixin.qq.com/pay/refundquery";
            }
            $config = $this->config;
            $params = [
                'appid'          => $config->appid,
                'mch_id'         => $config->merchantId,
                'nonce_str'      => getRandomStr(32, false),
                'sign_type'      => $config->signType,
                'transaction_id' => $order->channel_running_no,

            ];

            //检测必填参数
            $sign           = $this->wxPayData->makeSign($params);
            $params['sign'] = $sign;
            $xml            = $this->toXml($params);
            $client         = new Client();

            // 配置证书
            $cert = $this->getCert($config);

            $options = [
                'body'    => $xml,
                'cert'    => $cert['cert'],
                'ssl_key' => $cert['ssl_key'],
                'timeout' => 6,
            ];

            $response    = $client->post($url, $options);
            $content_xml = $response->getBody()->getContents();

            // 清理证书
            $this->clearCert($cert);

            $result = $this->dealResponse($content_xml);
            if (!$result) {
                throw new ApiException('数据异常');
            }
            $result_code = $result['result_code'] ?? '';
            if ($result_code != 'SUCCESS') {
                $err_code_des = $result['err_code_des'] ?? '微信查询数据异常';
                throw new ApiException($err_code_des);
            }

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

}