<?php
namespace app\payment\wxpay;


use app\common\context\ContextPay;
use app\payment\wxpay\lib\WxPayConfig;

class WxPayBase
{
    /**
     * @var WxPayConfig
     */
    protected $config;

    public function __construct()
    {
        $account = ContextPay::getAccount();
        $config = new WxPayConfig();
        $config->appid = $account->appid;
        $config->merchantId = $account->business_no;
        $config->pay_secret = $account->business_secret;
        $config->sslCertPath = $account->business_public_rsa;
        $config->sslKeyPath = $account->business_private_rsa;

        $this->config = $config;
    }
}