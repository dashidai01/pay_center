<?php

namespace app\payment\cloudpay\lib;

use app\common\context\ContextPay;
use app\common\exception\ApiException;
use app\common\tool\SnowFlake;
use app\model\Account;
use GuzzleHttp\Client;
use think\facade\Log;

class CloudPayBase {

    /**
     * @param $data
     * @return false|string
     */
    public function cloudDecrypt($data) {
        $account = ContextPay::getAccount();
        $key = $account->aes_secret;
        $iv = substr($key, 0, 8);
        $ret = openssl_decrypt($data['data'], 'DES-EDE3-CBC', $key, 0, $iv);
        if (false === $ret) {
            return openssl_error_string();
        }
        return $ret;
    }

    /**
     * @param $data
     * @return false|string
     */
    public function cloudEncrypt($data) {
        $account = ContextPay::getAccount();
        $key = $account->aes_secret;
        $iv = substr($key, 0, 8);
        $ret = openssl_encrypt($data, 'DES-EDE3-CBC', $key, 0, $iv);
        if (false === $ret) {
            return openssl_error_string();
        }
        return $ret;
    }

    /**
     * @param $content
     * @return string
     */
    public function cloudSign($content): string {
        $account = ContextPay::getAccount();
        $privateKey = $account->business_private_rsa;
        $key = openssl_get_privatekey($privateKey);
        openssl_sign($content, $signature, $key, "SHA256");
        openssl_free_key($key);
        return base64_encode($signature);
    }

    /**
     * @param $url
     * @param $data
     * @param string $method
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function execute($url, $data, string $method = 'post') {
        $url = 'https://api-service.yunzhanghu.com/'.$url;
        $account = ContextPay::getAccount();
        $paramStr = json_encode($data);
        $businessData = $this->cloudEncrypt($paramStr);
        $mess = SnowFlake::createOnlyId();
        $timestamp = time();
        $appkey = $account->business_secret;
        $str = "data={$businessData}&mess={$mess}&timestamp={$timestamp}&key={$appkey}";
        $signData = $this->cloudSign($str);

        $params['data'] = $businessData;
        $params['mess'] = $mess;
        $params['timestamp'] = $timestamp;
        $params['sign'] = $signData;
        $params['sign_type'] = 'rsa';

        $option = [
            "form_params" => $params,
            "headers"     => [
                "dealer-id"  => $account->appid,
                "request-id" => SnowFlake::createOnlyId()
            ]
        ];
        if ($method == 'post') {
            $option['form_params'] = $params;
        } else if ($method == 'get') {
            $option['query'] = $params;
        } else {
            throw new ApiException('请求方法不支持!');
        }
        try {
            $client = new Client();
            Log::info('url:'.$url.',请求云账户参数'.json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $result = $client->request($method, $url, $option);
            $body = $result->getBody()->getContents();
            Log::info('请求云账户返回参数'.json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $response = json_decode($body, true);
            if ($response['code'] != 0000) {
                throw new ApiException($response['message']);
            }
            return $response['data'];
        } catch (ApiException $e) {
            Log::info('请求云账户异常'.$e->getMessage());
            throw new ApiException($e->getMessage());
        }
    }
}