<?php
namespace app\payment\alipay;


use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\Factory;
use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\tool\AliOss;
use think\facade\Env;

class AliPayBase
{
    /**
     * AliPayBase constructor.
     * @throws \app\common\exception\ApiException
     */
    public function __construct()
    {
        $account = ContextPay::getAccount();
        $options = new Config();
        $options->protocol = Env::get('PAYMENT.ALIPAY_PROTOCOL');
        $options->gatewayHost = Env::get('PAYMENT.ALIPAY_GATEWAY_HOST');
        $options->signType =Env::get('PAYMENT.ALIPAY_SIGNTYPE');

        $options->appId = $account->appid;
        // 公钥模式
        if ($account->alipay_type == AccountEnum::ALIPAY_TYPE_1) {
            // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
            $options->merchantPrivateKey = $account->business_private_rsa;
            //注：如果采用非证书模式，则无需赋值上面的三个证书路径，改为赋值如下的支付宝公钥字符串即可
            $options->alipayPublicKey = $account->channel_public_rsa;
        } else if ($account->alipay_type == AccountEnum::ALIPAY_TYPE_2) { // 公钥证书模式
            $aliOss = new AliOss();
            // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
            $options->merchantPrivateKey = $account->business_private_rsa;
            //支付宝公钥证书文件路径，例如：/foo/alipayCertPublicKey_RSA2.crt
            $options->alipayCertPath = $aliOss->getUrl($account->alipayCertPath);
            //支付宝根证书文件路径，例如：/foo/alipayRootCert.crt
            $options->alipayRootCertPath = $aliOss->getUrl($account->alipayRootCertPath);
            //公钥证书文件路径，例如：/foo/appCertPublicKey_2019051064521003.crt
            $options->merchantCertPath = $aliOss->getUrl($account->merchantCertPath);
        }

        //可设置异步通知接收服务地址（可选）
        $options->notifyUrl = Env::get('PAYMENT.ALIPAY_NOTIFY');

        //可设置AES密钥，调用AES加解密相关接口时需要（可选）
        $options->encryptKey =$account->aes_secret;

        Factory::setOptions($options);
    }

}