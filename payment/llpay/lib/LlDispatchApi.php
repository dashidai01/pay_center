<?php

namespace app\payment\llpay\lib;


use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\model\BrandAccount;
use app\model\Order;
use app\model\User;
use app\validate\DispatchValidate;
use Exception;
use GuzzleHttp\Client;
use think\facade\Env;
use think\facade\Log;

class LlDispatchApi
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function transfer(string $running_no): array {

        try {
            //$url = 'https://payserverapi.lianlianpay.com/v1/paycreatebill';
            $url  = Env::get('PAYMENT.LL_URL') . '/v1/txn/transfer';
            $json = $this->getOptions($running_no);

            $result = $this->request($url, $json);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }
    /**
     * 连连转账（含提现）
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch(string $running_no): array {

        if (ContextPay::getMixed() == 'withdrawal') {
            // 提现
            return $this->withdrawal($running_no);
        } else {
            return $this->transfer($running_no);
        }

    }

    /**
     * @param $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function withdrawal($running_no) {
        try {
            $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/withdrawal';

            $json   = $this->getWithdrawalParam($running_no);
            $result = $this->request($url, $json);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @param string $running_no
     * @return array
     */
    public function getWithdrawalParam(string $running_no): array {
        $time_stamp = date('YmdHis');
        $account    = ContextPay::getAccount();

        $dispatchDto = ContextPay::getDispatchDto();
        $dispatchDto = json_decode(json_encode($dispatchDto), true);
        validate(DispatchValidate::class)->scene('LlWithdrawal')->check($dispatchDto);

        $money     = (float)(($dispatchDto['money']) / 100);
        $money     = sprintf('%.2f', $money);
        $risk_item = [
            "frms_client_chnl" => 13,
            "frms_ip_addr"     => "222.212.186.171",
            "user_auth_flag"   => 0,
        ];
        $params    = [
            "timestamp"     => $time_stamp,
            "oid_partner"   => $account->appid,
            "notify_url"    => Env::get('PAYMENT.DOMAIN') . '/api/dispatch/llnotify',
            "funds_flag"    => "N",
            "linked_acctno" => $dispatchDto['identity'],
            "risk_item"     => $risk_item,
            "pay_time_type" => "TRANS_THIS_TIME"
        ];
        if ($dispatchDto['payee_type'] == OrderEnum::PAY_LL_PERSON) {
            $params['check_flag'] = 'Y';
        } else {
            $params['check_flag'] = 'N';
        }
        /**
         * 商户订单信息
         */
        $params['orderInfo'] = [
            "txn_seqno"    => $running_no,
            "txn_time"     => $time_stamp,
            "total_amount" => $money,
            "order_info"   => $dispatchDto->remark ?? ''
        ];
        if ($money > 50000) {
            $params['orderInfo']['postscript'] = '';
        }
        /**
         * 付款方信息
         */
        if ($dispatchDto['payee_type'] == OrderEnum::PAY_LL_PERSON) {
            $params['payerInfo']['payer_type']     = 'USER';
            $params['payerInfo']['payer_accttype'] = 'USEROWN';
            $params['payerInfo']['payer_id']       = $dispatchDto['payee_id'];
            $params['payerInfo']['password']       = $dispatchDto['password'];
            $params['payerInfo']['random_key']     = $dispatchDto['random_key'];
        } else {
            $params['payerInfo']['payer_type']     = 'MERCHANT';
            $params['payerInfo']['payer_accttype'] = 'MCHOWN';
            $params['payerInfo']['payer_id']       = $account->appid;
        }
        return $params;
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOptions(string $running_no): array {
        $dispatchDto = ContextPay::getDispatchDto();
        $dispatchDto = json_decode(json_encode($dispatchDto), true);
        $account     = ContextPay::getAccount();
        $brand       = ContextPay::getBrand();
        validate(DispatchValidate::class)->scene('llTransfer')->check($dispatchDto);
        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_ALIPAY) {
            throw new ApiException('当前渠道不支持转账到支付宝!');
        }
        /** @var BrandAccount $brand_account */
        $brand_account = BrandAccount::where(['account_id' => $account->id, 'brand_id' => $brand->id])->find();
        $payer_id      = $brand_account->payer_id;

        if (empty($payer_id)) {
            $payer_id = $dispatchDto['payee_id'];
        }
        ContextPay::setPayeeId($payer_id);
        /** @var User $user */
        $user             = User::where('corporate_sn', $payer_id)->with(['agreement'])->field('id,corporate_sn')->find();
        $agreement_number = $user->agreement_number ?? '';
        // 检测是否签约免密代扣协议 若未签约 则需提供密码及密码因子
        if (empty($agreement_number)) {
            if (empty($dispatchDto['password']) || empty($dispatchDto['random_key'])) {
                throw new ApiException('密码因子和密码不能为空!');
            }
        }
        $time_stamp = date('YmdHis');
        $risk_item  = [
            "frms_client_chnl" => 13,
            "frms_ip_addr"     => "222.212.186.171",
            "user_auth_flag"   => 0,
        ];
        if ($dispatchDto['risk_item'] ?? '') {
            if (!is_string($dispatchDto['risk_item'])) {
                throw new ApiException('风控参数参数错误!');
            }
            $risk_item_data = json_decode($dispatchDto['risk_item'], true);
            validate(DispatchValidate::class)->scene('riskItem')->check($risk_item_data);
            $risk_item = [
                'frms_ware_category'      => '2024',
                'user_info_mercht_userno' => $risk_item_data['user_id'],
                'user_info_dt_register'   => $risk_item_data['user_created_at'],
                'user_info_bind_phone'    => $risk_item_data['user_phone'],
                'goods_name'              => $risk_item_data['goods_name'],
            ];
        }
        $risk_item  = json_encode($risk_item);
        $money      = (float)(($dispatchDto['money']) / 100);
        $money      = sprintf('%.2f', $money);
        $params     = [
            "timestamp"     => $time_stamp,
            "oid_partner"   => $account->appid,
            "notify_url"    => Env::get('PAYMENT.DOMAIN') . '/api/dispatch/llnotify',
            "funds_flag"    => "N",
            "risk_item"     => $risk_item,
            "check_flag"    => "N",
            "pay_time_type" => "TRANS_THIS_TIME"
        ];
        /**
         * 商户订单信息
         */
        $params['orderInfo'] = [
            "txn_seqno"    => $running_no,
            "txn_time"     => $time_stamp,
            "total_amount" => $money,
            "txn_purpose"  => "其他"
        ];
        // 大于5万必传 postscript
        if ($money >= 50000) {
            $params['orderInfo']["postscript"] = '账户资金提现';
        }
        // 优先按照业务备注读取 若不存在且大于5万 默认备注:账户资金提现
        if ($dispatchDto['remark'] ?? '') {
            $params['orderInfo']["postscript"] = $dispatchDto['remark'];
        }
        /**
         * 付款方信息
         */
        if ($dispatchDto['payee_type'] == OrderEnum::PAY_LL_PERSON) {
            $params['payerInfo']['payer_type']     = 'USER';
            $params['payerInfo']['payer_accttype'] = 'USEROWN';
            $params['payerInfo']['payer_id']       = $user->corporate_sn ?? '';
            if ($agreement_number) {
                // 免密代扣
                $params['payerInfo']['pap_agree_no'] = $this->pub_rsa($agreement_number);
            } else {
                // 密码及密码因子
                $params['payerInfo']['password']   = $dispatchDto['password'];
                $params['payerInfo']['random_key'] = $dispatchDto['random_key'];
            }
        } else {
            $params['payerInfo']['payer_type']     = 'MERCHANT';
            $params['payerInfo']['payer_accttype'] = 'MCHOWN';
            $params['payerInfo']['payer_id']       = $account->appid;
        }
        /**
         * 收款方信息
         */
        $params['payeeInfo'] = [
            "bank_acctno"   => $dispatchDto['identity'],
            "bank_acctname" => $dispatchDto['name'],
        ];

        /**
         * 对公必传参数
         */
        if ($dispatchDto['account_type'] == AccountEnum::ACCOUNT_TYPE_COMPANY) {
            $params['payeeInfo']['bank_code']  = $dispatchDto['bank_code'] ?? "";
            $params['payeeInfo']['cnaps_code'] = $dispatchDto['cnaps_code'] ?? "";
            $params['payeeInfo']['payee_type'] = "BANKACCT_PUB";
        } else {
            $params['payeeInfo']['payee_type'] = "BANKACCT_PRI";
        }
        return $params;

    }

    public function sign($data, $priKey) {
        //转换为openssl密钥，必须是没有经过pkcs8转换的私钥
        $res = openssl_get_privatekey($priKey);

        //调用openssl内置签名方法，生成签名$sign
        openssl_sign(md5(json_encode($data)), $sign, $res, OPENSSL_ALGO_MD5);

        //释放资源
        openssl_free_key($res);

        //base64编码
        $sign = base64_encode($sign);
        //file_put_contents("log.txt","签名原串:".$data."\n", FILE_APPEND);
        return $sign;
    }
    public function rsa($data, $priKey) {
        //转换为openssl密钥，必须是没有经过pkcs8转换的私钥
        return openssl_private_encrypt($data,$encrypted,$priKey) ? base64_encode($encrypted) : null;
    }
    public function pub_rsa($data) {
        $pub = \env('PAYMENT.LL_RSA');
        //转换为openssl密钥，必须是没有经过pkcs8转换的私钥
        //return openssl_private_encrypt($data,$encrypted,$priKey) ? base64_encode($encrypted) : null;
        return openssl_public_encrypt($data,$encrypted,$pub) ? base64_encode($encrypted) : null;
    }
    /**
     * @param string $url
     * @param array $json
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request(string $url, array $json): array {
        $account = ContextPay::getAccount();
        try {
            $client = new Client();

            $options = [
                'headers' => [
                    'Content-type'   => 'application/json;charset=utf-8',
                    'Signature-Data' => $this->sign($json, $account->business_private_rsa),
                    'Signature-Type' => 'RSA'
                ],
                'json'    => $json,
                'timeout' => 60,
            ];
            Log::record('lltransfer params: ' . json_encode($options));
            $response = $client->post($url, $options);
            $body     = $response->getBody()->getContents();
            Log::record('lltransfer result: ' . $body);
            $result = json_decode($body, true);
            // 0000 成功 8888 转账短信确认 8889 提现确认
            if (isset($result['ret_code']) && ($result['ret_code'] != 0000 && $result['ret_code'] != 8888 && $result['ret_code'] != 8889)) {
                throw new ApiException("调用连连接口失败：" . $result['ret_msg']);
            }
            return $result;
        } catch (Exception $e) {
            Log::record('lltransfer fail: ' . $e->getMessage());
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * 查询账单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery() {

        try {
            $param   = ContextPay::getRaw();
            $account = ContextPay::getAccount();
            $url     = Env::get('PAYMENT.LL_URL') . '/v1/txn/query-withdrawal';

            $json = [
                'txn_seqno'   => $param['running_no'],
                'oid_partner' => $account->appid,
                'timestamp'   => date('YmdHis')
            ];

            $result = $this->request($url, $json);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 获取随机因子
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getRandomKey() {

        try {
            $param   = ContextPay::getRaw();
            $account = ContextPay::getAccount();
            $url     = $url = Env::get('PAYMENT.LL_URL') . '/v1/acctmgr/get-random';

            $json = [
                "timestamp"   => date('YmdHis'),
                "oid_partner" => $account->appid,
                "user_id"     => $param['user_id'],
                "flag_chnl"   => $param['flag'] ?? "PC",
                "encrypt"     => $param['encrypt_type'] ?? "RSA",
            ];

            $result = $this->request($url, $json);
            $result = [
                'user_id'      => $result['user_id'],
                'random_key'   => $result['random_key'],
                'random_value' => $result['random_value'],
                'sm2_key_hex'  => $result['sm2_key_hex'] ?? '',
            ];
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function sendMsg() {
        try {
            $param   = ContextPay::getRaw();
            $account = ContextPay::getAccount();
            /** @var Order $order */
            $order = Order::where('running_no', $param['running_no'])->find();
            if (empty($order)) {
                throw new ApiException('订单不存在!');
            }
            $url   = Env::get('PAYMENT.LL_URL') . '/v1/txn/validation-sms';
            $money = (float)($order->money) / 100;
            $money = sprintf('%.2f', $money);
            $json  = [
                "timestamp"    => date('YmdHis'),
                "oid_partner"  => $account->appid,
                "txn_seqno"    => $order->running_no,
                "total_amount" => $money,
                "token"        => $param['token'],
                "verify_code"  => $param['verify_code'],
            ];
            if ($order->payee_type == OrderEnum::PAY_LL_PERSON) {
                $json['payer_type'] = 'USER';
                $json['payer_id']   = $order->payee_id;
            } else if ($order->payee_type == OrderEnum::PAY_LL_MECHART) {
                $json['payer_type'] = 'MERCHANT';
            }
            $result = $this->request($url, $json);
            /** 更新渠道流水号 */
            $order->channel_running_no = $result['accp_txno'] ?? '';
            $order->save();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getCnapsCode() {
        try {
            $param = ContextPay::getRaw();


            $account = ContextPay::getAccount();

            $url  = $url = Env::get('PAYMENT.LL_URL') . '/v1/acctmgr/query-cnapscode';
            $json = [
                "timestamp"    => date('YmdHis'),
                "oid_partner"  => $account->appid,
                "bank_code"    => $param['bank_code'],
                "brabank_name" => $param['brabank_name'],
                "city_code"    => $param['city_code']
            ];

            $result = $this->request($url, $json);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 确认提现
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function confirmWithdrawal() {

        try {
            $param = ContextPay::getRaw();
            /** @var Order $order */
            $order = Order::where('running_no', $param['running_no'])->find();
            if (empty($order)) {
                throw new ApiException('订单不存在!');
            }
            $account = ContextPay::getAccount();
            $url     = $url = Env::get('PAYMENT.LL_URL') . '/v1/txn/withdrawal-check';
            $money   = (float)($order->money) / 100;
            $money   = sprintf('%.2f', $money);

            $option              = [
                "timestamp"   => date('YmdHis'),
                "oid_partner" => $account->appid
            ];

            $option['orderInfo'] = [
                "txn_seqno"    => $param['running_no'],
                "total_amount" => $money
            ];

            $option['checkInfo']['check_result'] = $param['result'];
            $result = $this->request($url, $option);

            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
}