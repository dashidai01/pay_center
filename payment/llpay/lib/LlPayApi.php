<?php

namespace app\payment\llpay\lib;


use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\enum\UserAgreementEnum;
use app\common\exception\ApiException;
use app\common\tool\AliOss;
use app\common\tool\SnowFlake;
use app\common\vo\TradePayVo;
use app\model\BrandAccount;
use app\model\Order;
use app\model\OrderReceipt;
use app\model\User;
use app\model\UserAgreement;
use app\validate\DispatchValidate;
use Exception;
use GuzzleHttp\Client;
use think\facade\Cache;
use think\facade\Env;
use think\facade\Log;

class LlPayApi
{
    // 连连收款用户
    public $payee_id;

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pay(string $running_no): array {
        try {
            $url = 'https://payserverapi.lianlianpay.com/v1/paycreatebill';

            $json   = $this->getOpitons($running_no);
            $result = $this->request($url, $json);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    public function getOpitons(string $running_no): array {
        $tradePayDto = ContextPay::getTradePayDto();
        $account     = ContextPay::getAccount();
        // $mixed 为空需要自己开发收银台且必传卡类型及银行编码 为0 代表快捷支付 为2 拉取连连收银台
        $mixed = ContextPay::getMixed();

        $time_stamp = date('YmdHis');
        $risk_item  = [
            "frms_client_chnl" => 13,
            "frms_ip_addr"     => "222.212.186.171",
            "user_auth_flag"   => 1,
        ];
        $risk_item  = json_encode($risk_item);
        $money      = (string)(($tradePayDto->money) / 100);
        $params     = [
            "api_version"      => "1.0",
            "sign_type"        => "RSA",
            "time_stamp"       => $time_stamp,
            "oid_partner"      => $account->appid,
            "user_id"          => $tradePayDto->user_no,
            "busi_partner"     => '101001',
            "no_order"         => $running_no,
            "dt_order"         => date('YmdHis', time()),
            "money_order"      => $money,
            "url_return"       => $tradePayDto->return_url,
            "risk_item"        => $risk_item,
            "notify_url"       => Env::get('PAYMENT.LLPAY_NOTIFY'),
            "flag_pay_product" => 2,
            "flag_chnl"        => 2,         // 1 App-iOS 2 Web
        ];
        if (!strlen($mixed)) {
            $params['bank_code']        = $tradePayDto->bank_code;
            $params['card_type']        = $tradePayDto->card_type;
            $params['flag_pay_product'] = AccountEnum::ACCOUNT_LL_PAY_FLAG_ONLINE; // 0 快捷 2 网银收款
        }
        if (strlen($mixed) && $mixed == AccountEnum::ACCOUNT_LL_PAY_FLAG_FAST) {
            $params['flag_pay_product'] = AccountEnum::ACCOUNT_LL_PAY_FLAG_FAST; // 0 快捷 2 网银收款
        }
        $str = buildSortParams($params);
        Log::record('str: ' . $str);
        $sign           = addSign($str, $account->business_private_rsa);
        $params['sign'] = $sign;
        Log::record('sign: ' . $sign);

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
            Log::record('llpay pay params: ' . json_encode($json));

            $response = $client->post($url, $options);
            $body     = $response->getBody()->getContents();
            Log::record('llpay pay result: ' . $body);
            $result = json_decode($body, true);
            if (isset($result['error_show_mode']) && $result['error_show_mode']) {
                throw new ApiException("调用统一创单接口失败：" . $result['ret_msg']);
            }
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @param $running_no
     * @return Order
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createOrder($running_no) {
        try {
            $url    = Env::get('PAYMENT.LL_URL') . '/v1/txn/tradecreate';
            $json   = $this->orderOption($running_no);
            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }

            return $this->save($result);

        } catch (ApiException $e) {
            throw new ApiException('创建订单失败:' . $e->getMessage());
        }
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function union() {
        try {
            $running_no = SnowFlake::createOnlyId();
            $mixed      = ContextPay::getMixed();
            if ($mixed == OrderEnum::PAY_TYPE_CASHIER) {
                $json = $this->llCashierOption($running_no);
                $url = Env::get('PAYMENT.LL_AGREEMENT_URL') . '/v1/cashier/paycreate';
            } else {
                $order = $this->createOrder($running_no);
                $json  = $this->getUnionOption($order);
                $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/payment-gw';
            }


            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));
            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                if($mixed != OrderEnum::PAY_TYPE_CASHIER) {
                    // 发起支付失败 更新原因及状态
                    Order::update(['fail_reason' => $result['ret_msg'], 'status' => OrderEnum::STATUS_EXCEPTION], ['running_no' => $running_no]);
                }
                throw new ApiException($result['ret_msg']);
            }
            if ($mixed == OrderEnum::PAY_TYPE_CASHIER) {
                $this->save($result);
            }
            return $this->vo($result);
        } catch (ApiException $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 组装创建订单信息
     * @param $running_no
     * @return array
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderOption($running_no) {

        $time_stamp  = date('YmdHis');
        $account     = ContextPay::getAccount();
        $unionPayDto = ContextPay::getUnionPayDto();
        $money       = (string)(($unionPayDto->money) / 100);
        $brand       = ContextPay::getBrand();
        /**
         * 业务参数
         */
        $params = [
            "timestamp"   => $time_stamp,
            "oid_partner" => $account->appid,
            "txn_type"    => 'GENERAL_CONSUME', // GENERAL_CONSUME
            "user_type"   => 'ANONYMOUS', // 匿名用户
            "user_id"     => $unionPayDto->user_no, // ?
            "notify_url"  => Env::get('PAYMENT.DOMAIN') . '/notify/pay/unionpay', // 回调地址
            "return_url"  => $unionPayDto->return_url, // 支付返回地址
            "pay_expire"  => '30', //默认30分钟
        ];
        /**
         * 订单信息
         */
        $params['orderInfo'] = [
            "txn_seqno"    => $running_no,
            "txn_time"     => $time_stamp,
            "total_amount" => $money
        ];
        /** @var BrandAccount $brand_account */
        $brand_account = BrandAccount::where(['account_id' => $account->id, 'brand_id' => $brand->id])->find();

        ContextPay::setBrandAccount($brand_account);
        $payee_id = $brand_account->payee_id ?? '';

        if (empty($payee_id)) {
            $payee_id = $unionPayDto->payee_id;
        }
        ContextPay::setPayeeId($payee_id);
        /**
         * 收款方信息
         */
        $params['payeeInfo']["payee_amount"] = $money;
        $params['payeeInfo']["payee_memo"]   = $unionPayDto->explanation;
        // 支付到用户
        if ($unionPayDto->payee_type == OrderEnum::PAY_LL_PERSON) {
            $params['payeeInfo']['payee_type'] = 'USER';
            $params['payeeInfo']['payee_id']   = $payee_id;
        } else {
            // 支付到商户
            $params['payeeInfo']['payee_type'] = 'MERCHANT';
            $params['payeeInfo']['payee_id']   = $account->appid;
        }
        return $params;
    }

    /**
     * @param Order $order
     * @return array
     * @throws ApiException
     */
    public function getUnionOption(Order $order) {

        $time_stamp  = date('YmdHis');
        $account     = ContextPay::getAccount();
        $unionPayDto = ContextPay::getUnionPayDto();

        $risk_item = [
            "frms_client_chnl"        => 13,
            "frms_ip_addr"            => "8.129.168.199",
            "user_auth_flag"          => 1,
            "frms_ware_category"      => 2024,
            "user_info_mercht_userno" => $unionPayDto->user_no,
            "goods_name"              => $unionPayDto->goods_name
        ];
        $risk_item = json_encode($risk_item);
        $money     = (string)(($order->money) / 100);
        $params    = [
            "timestamp"    => $time_stamp,
            "oid_partner"  => $account->appid,
            "txn_seqno"    => $order->running_no,
            "total_amount" => $money,
            "risk_item"    => $risk_item,
            "bankcode"     => $order->bank_code ?? '',
            "appid"        => $unionPayDto->appid,
            "openid"       => $unionPayDto->openid,
            "client_ip"    => "8.129.168.199"
            //"extend_params" => json_encode(["accp_sub_mch_id" => '472205917'])
        ];
        /** @var BrandAccount $brandAccount */
        $brandAccount = ContextPay::getBrandAccount();

        if ($brandAccount && $brandAccount->wx_user_account && $brandAccount->ali_user_account) {
            $params['extend_params'] = json_encode(["ali_sub_mch_id" => $brandAccount->ali_user_account, 'wx_sub_mch_id' => $brandAccount->wx_user_account]);
        }
        $params['payerInfo']  = [
            "payer_type" => "USER",
            "payer_id"   => $order->user_no
        ];
        $params['payMethods'] = [
            [
                "method" => OrderEnum::getPayType(ContextPay::getMixed()),
                "amount" => $money
            ]
        ];
        return $params;

    }

    public function llCashierOption($running_no) {
        $time_stamp  = date('YmdHis');
        $account     = ContextPay::getAccount();
        $unionPayDto = ContextPay::getUnionPayDto();
        $brand       = ContextPay::getBrand();

        $risk_item = [
            "frms_client_chnl"        => 13,
            "frms_ip_addr"            => "8.129.168.199",
            "user_auth_flag"          => 1,
            "frms_ware_category"      => 2024,
            "user_info_mercht_userno" => $unionPayDto->user_no,
            "goods_name"              => $unionPayDto->goods_name
        ];
        $risk_item = json_encode($risk_item);
        $money     = (string)(($unionPayDto->money) / 100);
        $flag_chnl = $unionPayDto->client == '4' ? "PC" : "H5";
        $params    = [
            "timestamp"   => $time_stamp,
            "oid_partner" => $account->appid,
            "txn_type"    => "GENERAL_CONSUME",
            "user_type"   => 'ANONYMOUS', // 匿名用户
            "user_id"     => $unionPayDto->user_no, // ?
            "notify_url"  => Env::get('PAYMENT.DOMAIN') . '/notify/pay/unionpay', // 回调地址
            "return_url"  => $unionPayDto->return_url, // 支付返回地址
            "risk_item"   => $risk_item,
            "flag_chnl"   => $flag_chnl
        ];

        $params['orderInfo'] = [
            "txn_seqno"    => $running_no,
            "txn_time"     => $time_stamp,
            "total_amount" => $money,
            "goods_name"   => $unionPayDto->goods_name,
        ];
        /** @var BrandAccount $brand_account */
        $brand_account = BrandAccount::where(['account_id' => $account->id, 'brand_id' => $brand->id])->find();
        if ($brand_account && $brand_account->wx_user_account && $brand_account->ali_user_account) {
            $params['extend_params'] = json_encode(["ali_sub_mch_id" => $brand_account->ali_user_account, 'wx_sub_mch_id' => $brand_account->wx_user_account]);
        }
        ContextPay::setBrandAccount($brand_account);
        $payee_id = $brand_account->payee_id ?? '';

        if (empty($payee_id)) {
            $payee_id = $unionPayDto->payee_id;
        }
        ContextPay::setPayeeId($payee_id);
        /**
         * 收款方信息
         */
        $params['payeeInfo']["payee_amount"] = $money;
        $params['payeeInfo']["payee_memo"]   = $unionPayDto->explanation;
        // 支付到用户
        if ($unionPayDto->payee_type == OrderEnum::PAY_LL_PERSON) {
            $params['payeeInfo']['payee_type'] = 'USER';
            $params['payeeInfo']['payee_id']   = $payee_id;
        } else {
            // 支付到商户
            $params['payeeInfo']['payee_type'] = 'MERCHANT';
            $params['payeeInfo']['payee_id']   = $account->appid;
        }
        // 付款方信息payerInfo
        $params['payerInfo'] = [
            "payer_type" => "USER",
            "payer_id"   => $unionPayDto->user_no
        ];
        return $params;

    }

    /**
     * @param $result
     * @return Order
     */
    public function save($result): Order {

        $unionPayDto = ContextPay::getUnionPayDto();
        $account     = ContextPay::getAccount();
        $brand       = ContextPay::getBrand();

        $order                     = new Order();
        $order->appid              = $brand->appid;
        $order->order_no           = $unionPayDto->order_no;
        $order->goods_no           = $unionPayDto->goods_no;
        $order->goods_name         = $unionPayDto->goods_name;
        $order->user_no            = $unionPayDto->user_no;
        $order->notify_url         = $unionPayDto->notify_url ?? '';
        $order->client             = $unionPayDto->client ?? '';
        $order->money              = $unionPayDto->money;
        $order->account_id         = $account->id;
        $order->channel_id         = $account->channel_id;
        $order->channel_name       = $account->channel_name;
        $order->brand_id           = $brand->brand_id;
        $order->brand_name         = $brand->name;
        $order->source             = OrderEnum::SOURCE_ENTRY;
        $order->running_no         = $result['txn_seqno'];
        $order->order_type         = $unionPayDto->order_type ?? 1; //默认订单支付
        $order->third_appid        = $unionPayDto->appid ?? ''; //默认订单支付
        $order->return_url         = $unionPayDto->return_url ?? ''; //支付成功跳转地址
        $order->pay_type           = ContextPay::getMixed() ?? ''; //支付类型
        $order->payee_type         = $unionPayDto->payee_type ?? ''; //收款类型
        $order->payee_id           = ContextPay::getPayeeId() ?? ''; //收款用户ID
        $order->remark             = $unionPayDto->explanation ?? ''; //订单描述
        $order->channel_running_no = $result['accp_txno'] ?? ''; //连连ACCP系统号
        $order->founder_name       = $unionPayDto->founder_name ?? ""; //操作人
        $order->founder_no         = $unionPayDto->founder_no ?? ""; //操作人工号


        $order->save();
        return $order;
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

    /**
     * @param array $params
     * @return TradePayVo
     */
    public function vo(array $params) {
        $brand      = ContextPay::getBrand();
        $tradePayVo = new TradePayVo();

        $tradePayVo->appid       = $brand->appid;
        $tradePayVo->paytype     = ContextPay::getMixed();
        $tradePayVo->nonce_str   = getRandomStr(32);
        $tradePayVo->running_no  = $params['txn_seqno'];
        $tradePayVo->code_data   = $params['payload'] ?? '';
        $tradePayVo->gateway_url = $params['gateway_url'];

        return $tradePayVo;
    }

    /**
     * @return Order
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonSearch() {
        $account = ContextPay::getAccount();
        $param   = ContextPay::getRaw();
        validate(DispatchValidate::class)->scene('queryInfo')->check($param);
        try {
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/acctmgr/query-acctinfo';
            $json = [
                'timestamp'   => date('YmdHis'),
                'oid_partner' => $account->appid,
                'user_type'   => $param['user_type']
            ];
            // 不为商户信息 则必填用户ID
            if ($param['user_type'] != 'INNERMERCHANT') {
                $json['user_id'] = $param['user_id'];
            }
            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            return $result;

        } catch (Exception $e) {
            throw new ApiException('查询信息失败:' . $e->getMessage());
        }
    }

    /**
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery() {
        $account = ContextPay::getAccount();
        /** @var Order $order */
        $order = ContextPay::getOrder();
        try {
            if ($order->source == OrderEnum::SOURCE_OUT) {
                //退款查询
                if (!$order->relation_running_no) {
                    $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/query-withdrawal';
                } else {
                    $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/query-refund';
                }
            } else {
                $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/query-payment';
            }
            $json   = [
                'timestamp'   => date('YmdHis'),
                'oid_partner' => $account->appid,
                'accp_txno'   => $order->channel_running_no,
            ];
            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            return $result;

        } catch (Exception $e) {
            throw new ApiException('查询信息失败:' . $e->getMessage());
        }
    }

    /**
     * @param $type
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function applyReceipt($type = 'client') {
        $account = ContextPay::getAccount();
        /** @var Order $order */
        $order = ContextPay::getOrder();
        try {
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/offlinetxn/receipt-produce';
            $json = [
                'timestamp'       => date('YmdHis'),
                'oid_partner'     => $account->appid,
                'txn_seqno'       => SnowFlake::createOnlyId(),
                'txn_time'        => date('YmdHis', $order->pay_at),
                'trade_accp_txno' => $order->channel_running_no,
                'total_amount'    => (string)($order->money / 100)
            ];

            if ($order->source == OrderEnum::SOURCE_OUT && $order->relation_running_no) {
                $json['trade_bill_type'] = 'REFUND';
            } else if ($order->source == OrderEnum::SOURCE_OUT) {
                $json['trade_bill_type'] = 'CASHOUT';
            } else if ($order->source == OrderEnum::SOURCE_ENTRY) {
                $json['trade_bill_type'] = 'PAYBILL';
            } else {
                throw new ApiException('订单状态异常!');
            }

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            $order->file_id = $result['receipt_accp_txno'];
            $order->save();
            // 外部接口调用 则缓存到redis
            if ($type == 'client') {
                $redis = Cache::store('redis')->handler();
                // 存储临时的token
                $redis->set($result['receipt_accp_txno'], $result['token'], array('ex' => 60 * 60));
            }

            if ($type == 'system') {
                $order_receipt = OrderReceipt::where('running_no', $order->running_no)->find();
                if (empty($order_receipt)) {
                    $order_receipt             = new OrderReceipt();
                    $order_receipt->running_no = $order->running_no;
                    $order_receipt->created_at = time();
                }
                $order_receipt->temp_id    = $result['receipt_accp_txno'] ?? '';
                $order_receipt->updated_at = time();
                $order_receipt->save();
                $data['temp_id'] = $result['token'] ?? '';
            }

            $data['file_id'] = $result['receipt_accp_txno'] ?? '';
            return $data;

        } catch (Exception $e) {
            throw new ApiException('申请电子回单失败:' . $e->getMessage());
        }
    }

    /**
     * 查看电子回单
     * @param $param
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchReceipt($param = []) {
        $account = ContextPay::getAccount();
        /** @var Order $order */
        $order = ContextPay::getOrder();
        if (!empty($param)) {
            $file_id = $param['file_id'];
            $token   = $param['temp_id'];
        } else {
            $redis   = Cache::store('redis')->handler();
            $file_id = $order->file_id;
            $token   = $redis->get($file_id);
        }
        if (empty($token)) {
            throw new ApiException('请重新生成电子回单!');
        }
        try {
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/offlinetxn/receipt-download';
            $json = [
                'timestamp'         => date('YmdHis'),
                'oid_partner'       => $account->appid,
                'token'             => $token,
                'receipt_accp_txno' => $file_id
            ];

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            $data['download_url'] = $result['receipt_sum_file'] ?? '';
            return $data;

        } catch (Exception $e) {
            throw new ApiException('暂未查询到电子回单，请稍后再试！');
        }
    }

    /**
     * 申请协议
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function applyAgreement() {
        $account = ContextPay::getAccount();
        $param   = ContextPay::getRaw();
        try {
            $time_stamp = date('YmdHis');
            $url        = Env::get('PAYMENT.LL_AGREEMENT_URL') . '/v1/txn/pap-agree-apply';
            $running_no = SnowFlake::createOnlyId();
            $user_id    = $param['user_id'];
            $json       = [
                "timestamp"   => $time_stamp,
                "oid_partner" => $account->appid,
                "txn_seqno"   => $running_no,
                "txn_time"    => $time_stamp,
                "user_id"     => $user_id,
                "flag_chnl"   => 'H5',
                "return_url"  => env('PAYMENT.PAY_BILL_DOMAIN') . '/view/user/agreement',
                "notify_url"  => env('PAYMENT.DOMAIN') . '/notify/ll/apply/agreement',
                "papSignInfo" => ["agreement_type" => "WITH_HOLD"]
            ];

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('申请协议,url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('申请协议,url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            /** @var User $user */
            $user                              = User::where('corporate_sn', $user_id)->field('id,corporate_sn')->find();
            $userAgreement                     = new UserAgreement();
            $userAgreement->user_id            = $user->id;
            $userAgreement->trade_number       = $running_no;
            $userAgreement->channel_running_no = $result['accp_txno'];
            $userAgreement->sign_url           = $result['gateway_url'];
            $userAgreement->type               = UserAgreementEnum::TYPE_CLIENT;
            $userAgreement->agreement_type     = UserAgreementEnum::AGREEMENT_TYPE_FREE;
            $userAgreement->created_at         = time();
            $userAgreement->updated_at         = time();
            $userAgreement->save();

            $data = [
                'running_no'         => $running_no,
                'channel_running_no' => $result['accp_txno'],
                'sign_url'           => $result['gateway_url']
            ];

            return $data;

        } catch (Exception $e) {
            throw new ApiException('申请协议失败:' . $e->getMessage());
        }
    }

    /**
     * 查询协议
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function searchAgreement() {
        $account = ContextPay::getAccount();

        $param = ContextPay::getRaw();
        /** @var UserAgreement $userAgreementOrigin */
        $userAgreementOrigin = UserAgreement::where(
            [
                ['trade_number', '=', $param['running_no']]
            ]
        )->find();
        if (empty($userAgreementOrigin)) {
            throw new ApiException('协议不存在,或不可编辑!');
        }
        /** @var User $user */
        $user = User::where('id', $userAgreementOrigin->user_id)->find();
        if (empty($user)) {
            throw new ApiException('用户不存在!');
        }

        try {
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/txn/pap-agree-query';
            $json = [
                'timestamp'    => date('YmdHis'),
                'oid_partner'  => $account->appid,
                'user_id'      => $user->corporate_sn,
                'pap_agree_no' => $userAgreementOrigin->agreement_number
            ];

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('查询协议,url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('查询协议,url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }

            return $result;

        } catch (Exception $e) {
            throw new ApiException('查询协议失败:' . $e->getMessage());
        }
    }

    /**
     * 关闭协议
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function closeAgreement() {
        $account = ContextPay::getAccount();
        $params  = ContextPay::getRaw();
        /** @var UserAgreement $userAgreementOrigin */
        $userAgreementOrigin = UserAgreement::where(
            [
                ['trade_number', '=', $params['running_no']],
                ['status', '=', UserAgreementEnum::STATUS_ON],
                ['is_sign', '=', UserAgreementEnum::IS_SIGN_ON]
            ]
        )->find();
        if (empty($userAgreementOrigin)) {
            throw new ApiException('协议不存在,或不可编辑!');
        }
        /** @var User $user */
        $user = User::where('id', $userAgreementOrigin->user_id)->find();
        if (empty($user)) {
            throw new ApiException('用户不存在!');
        }

        try {
            $time_stamp     = date('YmdHis');
            $url            = Env::get('PAYMENT.LL_URL') . '/v1/txn/pap-agree-invalid';
            $new_running_no = SnowFlake::createOnlyId();
            $data           = [
                "timestamp"    => $time_stamp,
                "oid_partner"  => $account->appid,
                "txn_seqno"    => $new_running_no,
                "txn_time"     => $time_stamp,
                "user_id"      => $user->corporate_sn,
                "notify_url"   => env('PAYMENT.DOMAIN') . '/notify/ll/close/agreement',
                "pap_agree_no" => $userAgreementOrigin->agreement_number
            ];

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($data),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $data
            ];
            Log::record('关闭协议,url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('关闭协议,url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            // 关闭协议 流水号
            $userAgreementOrigin->new_trade_number = $new_running_no;
            $userAgreementOrigin->save();

            $returnData = [
                'running_no' => $new_running_no
            ];
            return $returnData;

        } catch (Exception $e) {
            throw new ApiException('关闭协议:' . $e->getMessage());
        }
    }

    /**
     * 修改协议
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function updateAgreement() {
        $account = ContextPay::getAccount();
        $param   = ContextPay::getRaw();
        /** @var UserAgreement $userAgreementOrigin */
        $userAgreementOrigin = UserAgreement::where(
            [
                ['trade_number', '=', $param['running_no']],
                ['status', '=', UserAgreementEnum::STATUS_ON],
                ['is_sign', '=', UserAgreementEnum::IS_SIGN_ON]
            ]
        )->find();
        if (empty($userAgreementOrigin)) {
            throw new ApiException('协议不存在,或不可编辑!');
        }
        /** @var User $user */
        $user = User::where('id', $userAgreementOrigin->user_id)->find();
        if (empty($user)) {
            throw new ApiException('用户不存在!');
        }

        try {
            $time_stamp = date('YmdHis');
            $url        = Env::get('PAYMENT.LL_AGREEMENT_URL') . '/v1/txn/pap-agree-modify';
            $running_no = SnowFlake::createOnlyId();
            $json       = [
                "timestamp"    => $time_stamp,
                "oid_partner"  => $account->appid,
                "txn_seqno"    => $running_no,
                "txn_time"     => $time_stamp,
                "pap_agree_no" => $userAgreementOrigin->agreement_number,
                "user_id"      => $user->corporate_sn,
                "flag_chnl"    => 'H5',
                "return_url"   => env('PAYMENT.PAY_BILL_DOMAIN') . '/view/user/agreement',
                "notify_url"   => env('PAYMENT.DOMAIN') . '/notify/ll/apply/agreement'
            ];

            $option = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json
            ];
            Log::record('修改协议,url:' . $url . '请求参数:' . json_encode($option, JSON_UNESCAPED_UNICODE));

            $client = new Client();
            $result = $client->post($url, $option);
            $result = $result->getBody()->getContents();
            $result = json_decode($result, true);
            Log::record('修改协议,url:' . $url . '参数:' . json_encode($option, JSON_UNESCAPED_UNICODE) . '返回结果:' . json_encode($result));
            if ($result['ret_code'] != 0000) {
                throw new ApiException($result['ret_msg']);
            }
            /** @var User $user */
            $user                              = User::where('corporate_sn', $param['user_id'])->field('id,corporate_sn')->find();
            $userAgreement                     = new UserAgreement();
            $userAgreement->user_id            = $user->id;
            $userAgreement->trade_number       = $running_no;
            $userAgreement->channel_running_no = $result['accp_txno'];
            $userAgreement->sign_url           = $result['gateway_url'];
            $userAgreement->status             = UserAgreementEnum::STATUS_OFF;
            $userAgreement->type               = UserAgreementEnum::TYPE_CLIENT;
            $userAgreement->agreement_type     = UserAgreementEnum::AGREEMENT_TYPE_FREE;
            $userAgreement->created_at         = time();
            $userAgreement->updated_at         = time();
            $userAgreement->save();

            $userAgreementOrigin->new_trade_number = $running_no;
            $userAgreementOrigin->save();

            $data = [
                'running_no'         => $running_no,
                'channel_running_no' => $result['accp_txno'],
                'sign_url'           => $result['gateway_url']
            ];
            return $data;

        } catch (Exception $e) {
            throw new ApiException('修改协议失败:' . $e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function commonReceipt() {
        $order = ContextPay::getOrder();
        /** @var OrderReceipt $orderReceipt */
        $orderReceipt = OrderReceipt::where('running_no', $order->running_no)->find();
        if ($orderReceipt && $orderReceipt->file_path) {
            $aliOss = new AliOss();
            $path   = $aliOss->getUrl($orderReceipt->file_path);
        } else {
            $data   = $this->applyReceipt('system');
            $result = $this->searchReceipt($data);
            $path   = $this->getLlReceipt($result);
        }
        return ['download_url' => $path];
    }

    /**
     * @param $data
     * @return mixed
     * @throws ApiException
     */
    public function getLlReceipt($data) {
        $order = ContextPay::getOrder();
        try {

            $results = $data['download_url'];

            $needData = base64_decode($results);

            $dir_path = runtime_path() . '/download/zip';
            if (!is_dir($dir_path)) {
                @mkdir($dir_path, 0777, true);
            }

            $file_path = $dir_path . '/' . date('YmdHis') . '.zip';
            file_put_contents($file_path, $needData);

            $zip      = zip_open($file_path);
            $alioss   = new AliOss();
            $pic_path = '';
            $savePath = '';
            if (is_resource($zip)) {
                while ($entry = zip_read($zip)) {

                    if (zip_entry_open($zip, $entry)) {

                        $contents = zip_entry_read($entry, zip_entry_filesize($entry));

                        $pic_path = $dir_path . '/' . date('YmdHis') . '.png';
                        if ($fp = fopen($pic_path, 'w')) {
                            if (fwrite($fp, $contents)) {
                                fclose($fp);
                            }
                        }
                    }

                    $savePath = 'pay_center/png/' . date('YmdHis') . '.png';
                    $alioss->upload($savePath, $pic_path);
                }
                @unlink($file_path);
                @unlink($pic_path);
            }
            // 关闭zip文件
            zip_close($zip);

            $oss_path = $alioss->getUrl($savePath);
            OrderReceipt::update(['file_path' => $savePath], ['running_no' => $order->running_no]);

            return $oss_path;
        } catch (ApiException $e) {
            Log::info('获取电子回单失败，失败原因' . $e->getMessage() . $e->getLine() . $e->getFile());
            throw new ApiException('获取电子回单失败!');
        }
    }
}