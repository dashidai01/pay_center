<?php

namespace app\payment\wxpay\lib;

/**
 * Class WxPayConfig
 * @package app\payment\wxpay\lib
 */
class WxPayConfig
{
    /**
     * @var string 公众号id
     */
    public $appid;

    /**
     * @var string 商户号
     */
    public $merchantId;

    /**
     * @var string 回调地址
     */
    public $notifyUrl;

    /**
     * @var string 加签类型
     */
    public $signType = 'HMAC-SHA256';

    /**
     * @var string 商户支付密钥
     */
    public $pay_secret;

    /**
     * @var string 公众号密钥
     */
    public $app_secret;

    /**
     * @var string 商户证书路径
     */
    public $sslCertPath;

    /**
     * @var string 商户证书key路径
     */
    public $sslKeyPath;

}
