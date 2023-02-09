<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2021/10/8
 * Time: 14:20
 */

namespace app\payment\gdpay\lib;


use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\tool\SnowFlake;
use app\model\Order;
use app\validate\DispatchValidate;
use GuzzleHttp\Client;
use think\facade\Env;
use think\facade\Log;

class GdDispatchApi
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch(string $running_no): array {

        $dispatch = ContextPay::getDispatchDto();
        try {

            $header = $this->getHeader();
            $data   = $this->getRequestData($running_no);
            $url    = Env::get('PAYMENT.GAODENG_URL') . 'api/balance/CreateForBatch';

            $client = new Client();
            $option = [
                'headers' => $header,
                'json'    => [
                    'data' => $this->signData([
                        'balances'            => $data,
                        'business_scene_code' => $dispatch->business_scene_code,    // 业务场景码'
                        'task_name'           => $dispatch->task_name,              // 任务名称'
                        'task_scope'          => $dispatch->task_scope,             // 任务周期'
                        'sales_product'       => $dispatch->sales_product,          // 推广产品名称'
                        'sales_mode'          => $dispatch->sales_mode,             // 推广途径'
                        'sales_sum'           => $dispatch->sales_sum,              // 推广数量'
                        'settle_rule'         => $dispatch->settle_rule,            // 服务费计算规则
                        'file_url'            => $dispatch->file_url                // 附件地址'
                    ])]
            ];

            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();
            Log::record("params: " . json_encode($data) . 'url:' . $url . 'result:' . $body);
            $data = json_decode($body, true);

            if (isset($data['code']) && $data['code'] != 0) {
                throw new ApiException($data['msg']);
            }

            return $data;
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @param $running_no
     * @return array
     * @throws ApiException
     */
    public function getRequestData($running_no) {
        $data                      = $this->checkParam();
        $data['order_random_code'] = $running_no;
        return [$data];
    }

    /**
     * @return array
     * @throws ApiException
     */
    public function checkParam() {
        $dispatch     = ContextPay::getDispatchDto();
        $dispatch     = json_decode(json_encode($dispatch), true);
        $request_data = [];
        validate(DispatchValidate::class)->scene('gdpay')->check($dispatch);

        $request_data['name']             = $dispatch['name'];
        $request_data['certificate_type'] = 1; //身份证 证件类型
        $request_data['certificate_num']  = $dispatch['id_card_number']; // 身份证
        $request_data['phone_num']        = $dispatch['phone']; // 电话
        $request_data['settle_amount']    = (string)($dispatch['money'] / 100); // 金额
        /**
         * payment_way
         * 银行卡的情况 1.银行卡 3.支付宝 9.微信
         */
        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_BANK) {
            $request_data['payment_way'] = 1;
            if (!isset($dispatch['inst_name']) || !$dispatch['inst_name']) {
                throw new  ApiException('银行名字不能为空!');
            }
            $request_data['bank_name']    = $dispatch['inst_name'];
            $request_data['bankcard_num'] = $dispatch['identity'];
        }
        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_ALIPAY) {
            $request_data['payment_way']     = 3;
            $request_data['payment_account'] = $dispatch['identity'];
        }
        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_WX) {
            $request_data['payment_way'] = 9;
            // TODO  转账到微信参数
            //$request_data['wx_openId'] = $payee_info['identity'];
            //$request_data['wx_appid']  = $payee_info['identity'];
        }
        return $request_data;
    }

    /**
     * @return array
     */
    public function getHeader() {

        $account = ContextPay::getAccount();
        $time    = time();

        $appkey    = $account->channel_public_rsa;
        $appsecret = $account->business_private_rsa;

        $str       = $appkey . $time . $appsecret;
        $signature = hash_hmac('sha256', $str, $appsecret);
        $header    = [
            'appkey'       => $appkey,
            'request_id'   => SnowFlake::createOnlyId(),
            'timestamp'    => $time,
            'sign_type'    => 'sha256',
            'signature'    => $signature,
            'version'      => '2.0',
            'callback_url' => base64_encode(Env::get('PAYMENT.GAODENG_NOTIFY'))
        ];
        return $header;
    }

    /**
     * 签名
     * @param $data
     * @return string
     */
    public function signData($data) {

        $account = ContextPay::getAccount();
        $str     = json_encode($data);

        $appSecret = $account->business_private_rsa;
        $aesIV     = $account->appid;

        $data = openssl_encrypt($str, 'aes-256-cbc', $appSecret, 0, $aesIV);

        return $data;
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getBusiness() {

        $account = ContextPay::getAccount();
        $url     = Env::get('PAYMENT.GAODENG_URL') . 'api/common/getbusinessscene';

        $option = [
            'headers' => $this->getHeader(),
            'json'    => ['data' => $this->signData(['merchant_id' => $account->channel_public_rsa])]
        ];

        $client = new Client();
        $result = $client->post($url, $option);

        $body = $result->getBody()->getContents();
        $data = json_decode($body, true);

        if (isset($data['code']) && $data['code'] != 0) {
            throw new ApiException($data['msg']);
        }
        return $data;
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getComplianceField() {

        $account = ContextPay::getAccount();
        $param = ContextPay::getRaw();
        $url     = Env::get('PAYMENT.GAODENG_URL') . 'api/common/GetComplianceField';

        $option = [
            'headers' => $this->getHeader(),
            'json'    => [
                'data' => $this->signData(
                    [
                        'merchant_id'         => $account->channel_public_rsa,
                        'business_scene_code' => $param['business_scene_code']
                    ]
                )
            ]
        ];

        $client = new Client();
        $result = $client->post($url, $option);

        $body = $result->getBody()->getContents();
        $data = json_decode($body, true);

        if (isset($data['code']) && $data['code'] != 0) {
            throw new ApiException($data['msg']);
        }
        return $data;
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery() {
        try {
            $param = ContextPay::getRaw();

            $url = Env::get('PAYMENT.GAODENG_URL') . 'api/balance/getbalance';

            $option = [
                'headers' => $this->getHeader(),
                'json'    => ['data' => $this->signData(['order_random_code' => $param['running_no']])]
            ];
            $client = new Client();
            $result = $client->post($url, $option);

            $body = $result->getBody()->getContents();
            $data = json_decode($body, true);

            if (isset($data['code']) && $data['code'] != 0) {
                throw new ApiException($data['msg']);
            }
            return $data['data'];
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function signResult() {
        try {

            $param   = ContextPay::getRaw();
            $account = ContextPay::getAccount();

            $url    = Env::get('PAYMENT.GAODENG_URL') . 'api/balance/identityauditresult';
            $data   = [
                'name'            => $param['name'],
                'certificate_num' => $param['idNumber'],
                'merchant_id'     => $account->channel_public_rsa
            ];
            $option = [
                'headers' => $this->getHeader(),
                'json'    => ['data' => $this->signData($data)]
            ];
            $client = new Client();
            $result = $client->post($url, $option);

            $body = $result->getBody()->getContents();
            $data = json_decode($body, true);

            if (isset($data['code']) && $data['code'] != 0) {
                throw new ApiException($data['msg']);
            }
            return $data['data'];
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array|mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refund() {
        $params = ContextPay::getRaw();
        /** @var Order $order */
        $order = Order::where('running_no', $params["running_no"])->find();
        if (empty($order)) {
            throw new ApiException('订单不存在!');
        }
        ContextPay::setOrder($order);
        $order_gd = $this->commonQuery();
        // 状态为600 且 hangup_flag 为true 挂单状态可退款
        // 1004打款失败  610待用户确认
        if ($order_gd['status'] != 1004 && $order_gd['status'] != 610 && !($order_gd['status'] == 600 && $order_gd['hangup_flag'])) {
            throw new ApiException('当前状态不可退款!');
        }
        try {

            $header = $this->getHeader();

            $url = Env::get('PAYMENT.GAODENG_URL') . 'api/balance/refundbalance';

            $params = ContextPay::getRaw();

            $data   = ["order_random_code" => [$params["running_no"]]];
            $client = new Client();
            $option = [
                'headers' => $header,
                'json'    => ['data' => $this->signData($data)]
            ];
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();
            $data   = json_decode($body, true);

            if (isset($data['code']) && $data['code'] != 0) {
                throw new ApiException($data['msg']);
            }

            $this->save($data);
            return $data;
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    public function save($result) {

        $params     = ContextPay::getRaw();
        $account    = ContextPay::getAccount();
        $brand      = ContextPay::getBrand();
        $orderModel = ContextPay::getOrder();

        $order             = new Order();
        $order->appid      = $brand->appid;
        $order->order_no   = $orderModel->order_no;
        $order->goods_name = $orderModel->goods_name ?? '';
        $order->user_no    = $orderModel->user_no ?? '';

        $order->client              = OrderEnum::CLIENT_WEB;
        $order->notify_url          = $orderModel->notify_url ?? '';
        $order->money               = $orderModel->money;
        $order->account_id          = $account->id;
        $order->channel_id          = $account->channel_id;
        $order->channel_name        = $account->channel_name;
        $order->brand_id            = $brand->brand_id;
        $order->brand_name          = $brand->name;
        $order->running_no          = $result['request_id'];
        $order->channel_running_no  = $result['request_id'];
        $order->relation_running_no = $orderModel->running_no;
        $order->status              = OrderEnum::STATUS_UNPAY; // 默认未支付
        $order->source              = OrderEnum::SOURCE_ENTRY;   // 入账
        $order->founder_name        = $params['founder_name'] ?? '';   // 操作人
        $order->founder_no          = $params['founder_no'] ?? '';     // 操作人工号

        $order->save();

        return $order;
    }

    /**
     * @return array|mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function delete() {
        $params = ContextPay::getRaw();
        /** @var Order $order */
        $order = Order::where('running_no', $params["running_no"])->find();
        if (empty($order)) {
            throw new ApiException('订单不存在!');
        }
        try {

            $header = $this->getHeader();

            $url = Env::get('PAYMENT.GAODENG_URL') . 'api/Balance/DeleteForBatch';

            $params = ContextPay::getRaw();

            $data = ["order_random_codes" => [$params["running_no"]]];

            $client = new Client();
            $option = [
                'headers' => $header,
                'json'    => ['data' => $this->signData($data)]
            ];
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();
            $data   = json_decode($body, true);
            if (isset($data['code']) && $data['code'] != 0) {
                throw new ApiException($data['msg']);
            }

            return $data;
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
}