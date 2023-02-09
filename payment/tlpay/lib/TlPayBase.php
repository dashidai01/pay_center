<?php

namespace app\payment\tlpay\lib;


use app\common\context\ContextPay;
use app\common\exception\ApiException;
use app\common\tool\AliOss;
use GuzzleHttp\Client;
use think\facade\Log;

class TlPayBase
{
    public function common() {

        $account = ContextPay::getAccount();
        $data    = [
            'appId'     => $account->appid,
            'method'    => '',
            'charset'   => 'utf-8',
            'format'    => 'JSON',
            'signType'  => 'SHA256WithRSA',
            'timestamp' => date('Y-m-d H:i:s'),
            'version'   => '1.0'
        ];

        return $data;
    }

    /**
     * @param $method
     * @param $param
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function execute($method, $param) {

        $commonData               = $this->common();
        $commonData['method']     = $method;
        $commonData['bizContent'] = json_encode($param, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

        $option         = array_merge($commonData);
        $sign           = $this->sign($option);
        $option['sign'] = $sign;
        $options        = [
            'form_params' => $option
        ];

        $data = $this->send($options);

        if (isset($data['subCode']) && $data['subCode'] != 'OK') {
            throw new ApiException('通联接口请求错误' . ($data['subMsg'] ?? ''));
        }

        return $data['data'] ?? [];
    }

    /**
     * [encryptAES AES-SHA1PRNG加密算法]
     * @param $string
     * @return string
     */
    public function encryptAES($string) {
        $account = ContextPay::getAccount();
        //AES加密通过SHA1PRNG算法
        $key  = substr(openssl_digest(openssl_digest($account->aes_secret, 'sha1', true), 'sha1', true), 0, 16);
        $data = openssl_encrypt($string, 'AES-128-ECB', $key, OPENSSL_RAW_DATA);
        $data = strtoupper(bin2hex($data));
        return $data;
    }

    /**
     * @param $option
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function send($option) {
        try {

            $url = env('PAYMENT.tl_url');
            Log::info('url:' . $url . ',请求通联参数' . json_encode($option,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $client = new Client();
            $body   = $client->post($url, $option);
            $result = $body->getBody()->getContents();
            Log::info('请求通联返回参数' . json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            $data = json_decode($result, true);

            if (!$data || $data['code'] != 10000) {
                throw new ApiException('通联接口调用失败' . ($data['msg'] ?? ''));
            }
            return $data;

        } catch (ApiException $e) {
            Log::info('请求通联异常' . $e->getMessage());
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param $param
     * @return string
     * @throws \app\common\exception\ApiException
     */
    public function sign($param) {

        $account = ContextPay::getAccount();
        unset($param['signType']);
        $param = array_filter($param);//剔除值为空的参数
        ksort($param);

        $sb = '';
        foreach ($param as $entry_key => $entry_value) {
            $sb .= $entry_key . '=' . $entry_value . '&';
        }
        $sb = trim($sb, "&");
        //MD5摘要计算,Base64
        $sb              = base64_encode(hash('md5', $sb, true));
        $alioss          = new AliOss();
        $privateKey_path = $alioss->getUrl($account->merchantCertPath);
        $privateKey      = $this->loadPrivateKeyByPfx($privateKey_path, $account->business_secret);

        if (openssl_sign(utf8_encode($sb), $sign, $privateKey, OPENSSL_ALGO_SHA256)) {//SHA256withRSA密钥加签
            // openssl_free_key($privateKey);
            $sign = base64_encode($sign);

            return $sign;
        } else {
            echo "sign error";
            exit();
        }
    }

    /**
     * 从证书文件中装入私钥 Pfx 文件格式
     */
    private function loadPrivateKeyByPfx($path, $pwd) {
        $priKey = file_get_contents($path);
        if (openssl_pkcs12_read($priKey, $certs, $pwd)) {
            $privateKey = $certs['pkey'];
            //print_r($privateKey);
            return $privateKey;

        }
        die("私钥文件格式错误");
    }

}