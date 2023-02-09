<?php

namespace app\payment\wxpay\lib;


use app\common\exception\ApiException;

/**
 * Class WxPayData
 * @package app\payment\wxpay\lib
 */
class WxPayData
{
    /**
     * @var WxPayConfig
     */
    private $config;

    public function __construct(WxPayConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param $params
     * @return bool
     * @throws ApiException
     */
    public function checkSign($params)
    {
        if(!array_key_exists('sign', $params)) {
            throw new ApiException("签名错误");
        }

        $sign = $this->makeSign($params, false);
        if($sign == $params['sign']){
            //签名正确
            return true;
        }
        return false;
    }

    /**
     * @param string $xml
     * @return array
     * @throws ApiException
     */
    public function decodeXml(string $xml): array
    {
        if(!$xml){
            throw new ApiException("xml数据异常！");
        }
        //将XML转为array
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $arr = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $arr;
    }

    /**
     * @param string $str
     * @return array
     * @throws ApiException
     */
    public function decript(string $str):array
    {
        $config = $this->config;
        $key = $config->pay_secret;

        $key = md5($key);
        $decrypt = base64_decode($str, true);
        $result = openssl_decrypt($decrypt , 'aes-256-ecb', $key, OPENSSL_RAW_DATA);
        $result = $this->decodeXml($result);
        return $result;
    }

    /**
     * 生成签名
     * @param $params
     * @param bool $needSignType
     * @return string
     * @throws ApiException
     */
    public function makeSign($params, $needSignType = true)
    {
        $config = $this->config;

        //签名步骤一：按字典序排序参数
        ksort($params);

        $string = $this->toUrlParams($params);
        //签名步骤二：在string后加入KEY
        $string = $string . "&key=".$config->pay_secret;
        //签名步骤三：MD5加密或者HMAC-SHA256
        if($config->signType == "MD5"){
            $string = md5($string);
        } else if($config->signType == "HMAC-SHA256") {
            $string = hash_hmac("sha256",$string ,$config->pay_secret);
        } else {
            throw new ApiException("签名类型不支持！");
        }

        //签名步骤四：所有字符转为大写
        $result = strtoupper($string);
        return $result;
    }

    /**
     * 格式化参数格式化成url参数
     * @param array $params
     * @return string
     */
    public function toUrlParams(array $params)
    {
        $buff = "";
        foreach ($params as $k => $v)
        {
            if($k != "sign" && $v != "" && !is_array($v)){
                $buff .= $k . "=" . $v . "&";
            }
        }

        $buff = trim($buff, "&");
        return $buff;
    }
}