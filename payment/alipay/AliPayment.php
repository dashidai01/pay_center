<?php

namespace app\payment\alipay;


use Alipay\EasySDK\Kernel\Factory;
use Alipay\EasySDK\Kernel\Util\ResponseChecker;
use Alipay\EasySDK\Payment\Common\Models\AlipayTradeRefundResponse;
use Alipay\EasySDK\Payment\Page\Models\AlipayTradePagePayResponse;
use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\AliOss;
use app\common\tool\Security;
use app\common\tool\SnowFlake;
use app\common\vo\NotifyRefundVo;
use app\controller\UserCenter;
use app\model\Brand;
use app\model\Order;
use app\model\OrderReceipt;
use app\payment\PaymentInterface;
use app\validate\DispatchValidate;
use Exception;
use think\facade\Cache;
use think\facade\Log;

class AliPayment extends AliPayBase implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function pay(string $running_no): array {
        try {
            $tradePayDto = ContextPay::getTradePayDto();
            $money       = (int)$tradePayDto->money;
            $money       = $money / 100;
            $params      = [
                'goods_name'  => $tradePayDto->goods_name,
                'running_no'  => $running_no,
                'order_money' => (string)$money,
                'return_url'  => $tradePayDto->return_url,
            ];

            /**
             * 发起API调用（以支付能力下的统一收单交易创建接口为例）
             * @var AlipayTradePagePayResponse $result
             */
            if (ContextPay::getMixed() == OrderEnum::CLIENT_WEB) {

                $result = Factory::payment()->page()->pay(...array_values($params));
            } else if (ContextPay::getMixed() == OrderEnum::CLIENT_H5) {

                array_splice($params, 3, 0, ['quit_url' => $tradePayDto->quit_url]);
                $result = Factory::payment()->wap()->pay(...array_values($params));
            } else {
                throw new  ApiException('客户端来源不支持!');
            }

            $responseChecker = new ResponseChecker();
            //3. 处理响应或异常
            if (!$responseChecker->success($result)) {
                throw new ApiException("支付连接超时！");
            }

            // 支付宝form表单
            $form = $result->body;

            return [
                'code_data' => $form
            ];

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }


    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function refund(string $running_no): array {
        try {
            $tradeRefundDto = ContextPay::getTradeRefundDto();
            $money          = (int)$tradeRefundDto->refund_money;
            $money          = $money / 100;

            $params = [
                'outTradeNo'   => $tradeRefundDto->pay_running_no,
                'refundAmount' => (string)$money,
            ];

            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $options = ['out_request_no' => $running_no];

            try {
                $result = Factory::payment()->common()->batchOptional($options)->refund(...array_values($params));
            } catch (Exception $e) {
                Log::record("调用支付宝退款接口失败：" . $e->getMessage());
                throw new ApiException($e->getMessage());
            }

            $responseChecker = new ResponseChecker();
            //3. 处理响应或异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }

            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            if (!isset($ret['fund_change']) || $ret['fund_change'] != 'Y') {
                throw new ApiException("请勿重复提交退款申请");
            }

            /**
             * 添加消息队列
             * 支付宝，交易关闭时，不会触发异步通知
             * 全款退，部分退款最后一笔，都不会触发异步通知
             */
            // 加入消息队列
            $orderDto                           = ContextPay::getOrder();
            $notifyRefundVo                     = new NotifyRefundVo();
            $notifyRefundVo->running_no         = $running_no;
            $notifyRefundVo->appid              = $orderDto->appid;
            $notifyRefundVo->notify_time        = date("Y-m-d H:i:s");
            $notifyRefundVo->notify_url         = $tradeRefundDto->notify_url;
            $notifyRefundVo->nonce_str          = getRandomStr(32);
            $notifyRefundVo->refund_money       = $tradeRefundDto->refund_money;
            $notifyRefundVo->trade_no           = $orderDto->order_no;
            $notifyRefundVo->channel_running_no = $ret['trade_no'];

            $data = $notifyRefundVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret               = Brand::where('appid', $orderDto->appid)->value('secret');
            $security             = new Security();
            $notifyRefundVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyRefundVo->toMap()
            ];
            $message  = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);
            /**
             * 充值到用户
             */
            if ($orderDto->order_type == OrderEnum::ORDER_TYPE_ADD) {
                $user_center = new UserCenter();
                $user_center->Recharge($orderDto, 'refund', $running_no);
            }

            return $ret;
        } catch (Exception $e) {
            Log::record($e->getMessage() . ' file: ' . $e->getFile() . 'line: ' . $e->getLine());
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 参数二次验证
     * @param $params
     * @throws ApiException
     */
    public function validateParam($params) {
        if (!isset($params['payee_info']['identity_type'])) {
            throw new ApiException('参与方标识不能为空!');
        }
        if (!in_array($params['payee_info']['identity_type'], [AccountEnum::IDENTITY_TYPE_ACCOUNT, AccountEnum::IDENTITY_TYPE_BANK])) {
            throw new ApiException('参与方标识非法');
        }
        if (ContextPay::getMixed() == 'bank') {
            if ($params['payee_info']['identity_type'] == AccountEnum::IDENTITY_TYPE_ACCOUNT) {
                throw new ApiException('参与方标识错误');
            }
        }
        if (ContextPay::getMixed() == 'alipay') {
            if ($params['payee_info']['identity_type'] == AccountEnum::IDENTITY_TYPE_BANK) {
                throw new ApiException('参与方标识错误');
            }
        }
    }

    /**
     * @param $running_no
     * @return array
     */
    public function getOption($running_no) {

        $dispatch = ContextPay::getDispatchDto();
        $dispatch = json_decode(json_encode($dispatch), true);

        $money  = $dispatch['money'] / 100;
        $params = [
            'out_biz_no'   => $running_no,
            'trans_amount' => (string)$money,
            'biz_scene'    => 'DIRECT_TRANSFER',
            'order_title'  => $dispatch['order_title'],
            'remark'       => $dispatch['remark'],
        ];
        /**
         * 收款方信息
         */
        $params['payee_info'] = [
            "identity" => $dispatch["identity"],
            "name"     => $dispatch["name"]
        ];
        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_BANK) {
            if (isset($dispatch['bank_code']) && $dispatch['bank_code']) {
                // 存在银行联号的情况
                $params['bank_code'] = $dispatch['bank_code'];
            } else {
                validate(DispatchValidate::class)->scene('alipay')->check($dispatch);
                $params['payee_info']['bankcard_ext_info']['account_type']     = $dispatch['account_type'];
                $params['payee_info']['bankcard_ext_info']['inst_name']        = $dispatch['inst_name'] ?? '';
                $params['payee_info']['bankcard_ext_info']['inst_province']    = $dispatch['inst_province'] ?? '';
                $params['payee_info']['bankcard_ext_info']['inst_province']    = $dispatch['inst_province'] ?? '';
                $params['payee_info']['bankcard_ext_info']['inst_city']        = $dispatch['inst_city'] ?? '';
                $params['payee_info']['bankcard_ext_info']['inst_branch_name'] = $dispatch['inst_branch_name'] ?? '';
            }
            $params['payee_info']['identity_type'] = AccountEnum::IDENTITY_TYPE_BANK;
            $params['product_code']                = AccountEnum::ALIPAY_PRODUCT_CODE_BANK;
            $params['business_params']             = '{"withdraw_timeliness":"T0"}';
        } else {
            // 转账到支付宝的情况
            $params['product_code']                = AccountEnum::ALIPAY_PRODUCT_CODE_ACCOUNT;
            $params['payee_info']['identity_type'] = AccountEnum::IDENTITY_TYPE_ACCOUNT;
        }

        return $params;
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function dispatch(string $running_no): array {
        try {
            $params = $this->getOption($running_no);

            $result = Factory::util()->generic()->execute("alipay.fund.trans.uni.transfer", [], $params);
            Log::record('转账结果:' . json_encode($result));

            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);

            return $http_body['alipay_fund_trans_uni_transfer_response'];
        } catch (Exception $e) {
            Log::record('转账失败' . $e->getMessage() . ' file: ' . $e->getFile() . 'line: ' . $e->getLine());
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 申请电子回单
     * @return array
     * @param  string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function applyReceipt($type = 'client'): array {
        $param = ContextPay::getRaw();
        /** @var  Order $order */
        $order        = Order::where('running_no', $param['running_no'])->find();
        $pay_order_id = $order->channel_running_no;
        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $requestData = [
                "type" => "FUND_DETAIL",
                "key"  => $pay_order_id
            ];
            $textParams  = [];
            $result      = Factory::util()->generic()->execute("alipay.data.bill.ereceipt.apply", $textParams, $requestData);

            Log::record('申请电子回单:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);
            $data      = $http_body['alipay_data_bill_ereceipt_apply_response'];
            if ($type == 'system') {
                $order_receipt = OrderReceipt::where('running_no', $order->running_no)->find();
                if (empty($order_receipt)) {
                    $order_receipt             = new OrderReceipt();
                    $order_receipt->running_no = $order->running_no;
                    $order_receipt->created_at = time();
                }
                $order_receipt->temp_id    = $data['file_id'] ?? '';
                $order_receipt->updated_at = time();
                $order_receipt->save();

            }
            if (isset($data['file_id']) && $data['file_id']) {
                $order->file_id = $data['file_id'];
                $order->save();
            }
            return $http_body['alipay_data_bill_ereceipt_apply_response'];

        } catch (ApiException $e) {
            return json("调用失败，" . $e->getMessage());
        }
    }

    /**
     * 查看电子回单
     * @param array $data
     * @return array
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function searchReceipt($data = []): array {

        $param = ContextPay::getRaw();
        if (!empty($data)) {
            $file_id = $data['file_id'];
        } else {
            /** @var Order $order */
            $order = Order::where('running_no', $param['running_no'])->find();
            if (!$order) {
                throw new ApiException('订单不存在!');
            }
            if (!$order->file_id) {
                throw new ApiException('文件ID不存在,请先申请电子回单');
            }
            $file_id = $order->file_id;
        }
        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $requestData = [
                "file_id" => $file_id
            ];
            $textParams  = [];
            $result      = Factory::util()->generic()->execute("alipay.data.bill.ereceipt.query", $textParams, $requestData);

            Log::record('查看电子回单:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);

            return $http_body['alipay_data_bill_ereceipt_query_response'];

        } catch (ApiException $e) {
            return json("调用失败，" . $e->getMessage());
        }
    }

    /**
     *  * 查询账单
     * @return array
     * @throws ApiException
     */
    public function commonQuery(): array {
        $param = ContextPay::getRaw();
        /** @var  Order $order */
        $order = ContextPay::getOrder();
        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            if ($order->source == OrderEnum::SOURCE_ENTRY) { // 支付
                $requestData = [
                    "out_trade_no" => $param['running_no'],
                ];
                $method      = 'alipay.trade.query';
                $return_flag = 'alipay_trade_query_response';
            } else if ($order->relation_running_no) { // 退款
                $requestData = [
                    "out_request_no" => $order->running_no,
                    "trade_no"       => $order->channel_running_no
                ];
                $method      = 'alipay.trade.fastpay.refund.query';
                $return_flag = 'alipay_trade_fastpay_refund_query_response';
            } else { // 转账
                $requestData = [
                    "pay_fund_order_id" => $order->channel_running_no
                ];
                $method      = 'alipay.fund.trans.common.query';
                $return_flag = 'alipay_fund_trans_common_query_response';
            }

            $textParams = [];
            $result     = Factory::util()->generic()->execute($method, $textParams, $requestData);
            Log::record('查询账单:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);
            return $http_body[$return_flag];

        } catch (ApiException $e) {
            throw new ApiException($e->getMessage());
        }
    }

    public function authRealName() {
        $param = ContextPay::getRaw();

        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $requestData = [
                "user_name" => $param['user_name'],
                "cert_type" => 'IDENTITY_CARD',
                "cert_no"   => $param['cert_no'],
                "logon_id"  => $param['login_id'],
                "mobile"    => $param['phone']
            ];

            $textParams = [];
            $result     = Factory::util()->generic()->execute("alipay.user.certdoc.certverify.preconsult", $textParams, $requestData);

            Log::record('查询账单:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);

            return $http_body['alipay_user_certdoc_certverify_preconsult_response'];

        } catch (ApiException $e) {
            return json("调用失败，" . $e->getMessage());
        }
    }

    /**
     * @return \think\response\Json
     * @throws ApiException
     */
    public function checkRealName() {

        $param     = ContextPay::getRaw();
        $redis     = Cache::store('redis')->handler();
        $verify_id = $param['verify_id'] ?? '';
        if (!$verify_id) {
            throw new ApiException('verify_id不能为空!');
        }
        $auth_token = $redis->get($verify_id);
        if (empty($auth_token)) {
            throw new ApiException('认证信息已失效,请重新认证!');
        }
        $auth_token = json_decode($auth_token, true);
        Log::info('token信息:' . json_encode($auth_token));
        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $requestData = [
                "verify_id" => $verify_id,
            ];
            $textParams  = [
                "auth_token" => $auth_token['access_token']
            ];

            $result = Factory::util()->generic()->execute("alipay.user.certdoc.certverify.consult", $textParams, $requestData);

            Log::info('实名认证返回信息:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

            if (!isset($ret['code']) || $ret['code'] != 10000) {
                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
                throw new ApiException($msg);
            }

            $http_body = json_decode($ret['http_body'], true);

            return $http_body['alipay_user_certdoc_certverify_consult_response'];

        } catch (ApiException $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param $authCode
     * @return \think\response\Json
     * @throws Exception
     */
    public function getToken($authCode) {
        try {
            //2. 发起API调用（以支付能力下的统一收单交易创建接口为例）
            $textParams = [
                "grant_type" => 'authorization_code',
                "code"       => $authCode
            ];

            $requestData = [];
            $result      = Factory::util()->generic()->execute("alipay.system.oauth.token", $textParams, $requestData);

            Log::record('获取access_token:' . json_encode($result));
            $responseChecker = new ResponseChecker();
            // 响应异常
            if (!$responseChecker->success($result)) {
                /**
                 * @var AlipayTradeRefundResponse $result
                 */
                $map = $result->toMap();
                $map = json_encode($map, JSON_UNESCAPED_UNICODE);
                Log::record($map);
                throw new ApiException($result->subMsg, (int)$result->code);
            }
            /**
             * @var AlipayTradeRefundResponse $result
             */
            $ret = $result->toMap();

//            if (!isset($ret['code'])) {
//                $msg = $ret['sub_msg'] ?? '请求支付宝异常';
//                throw new ApiException($msg);
//            }

            $http_body = json_decode($ret['http_body'], true);

            return $http_body['alipay_system_oauth_token_response'];

        } catch (ApiException $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function commonReceipt(): array {
        $order = ContextPay::getOrder();
        /** @var OrderReceipt $orderReceipt */
        $orderReceipt = OrderReceipt::where('running_no', $order->running_no)->find();
        if ($orderReceipt && $orderReceipt->file_path) {
            $aliOss = new AliOss();
            $path   = $aliOss->getUrl($orderReceipt->file_path);
        } else {
            $data   = $this->applyReceipt('system');
            $result = $this->searchReceipt($data);
            $path   = $this->getAliReceipt($result);
        }
        return ['download_url' => $path];
    }

    /**
     * @param $result
     * @return string
     * @throws ApiException
     */
    public function getAliReceipt($result) {
        $order       = ContextPay::getOrder();
        $file        = $result['download_url'] ?? '';
        if(empty($file)) {
            throw new ApiException('获取电子回单失败,请稍后重试!');
        }
        $stream_opts = [
            "ssl" => [
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ]
        ];

        $pdfData     = file_get_contents($file, false, stream_context_create($stream_opts));

        $dir_path = runtime_path() . '/download/pdf';
        if (!is_dir($dir_path)) {
            @mkdir($dir_path, 0777, true);
        }
        $file_path = $dir_path . '/' . SnowFlake::createOnlyId() . '.pdf';
        file_put_contents($file_path, $pdfData);

        $savePath = 'pay_center/pdf/' . SnowFlake::createOnlyId() . '.pdf';

        $aliOss   = new AliOss();
        $flag     = $aliOss->upload($savePath, $file_path);
        if ($flag) {
            @unlink($file_path);
        }
        $oss_path = $aliOss->getUrl($savePath);
        OrderReceipt::update(['file_path' => $savePath], ['running_no' => $order->running_no]);
        return $oss_path;
    }

    /**
     * @param $pdf
     * @param $path
     * @param int $page
     * @return array|bool
     * @throws \ImagickException
     */
    public function pdf2png($pdf, $path, $page = -1)
    {
        if (!extension_loaded('imagick')) {
            return false;
        }
        if (!file_exists($pdf)) {
            return false;
        }
        if (!is_readable($pdf)) {
            return false;
        }

        $Return = [];
        $im = new \Imagick();

        $im->setResolution(150, 150);
        $im->setCompressionQuality(100);
        try{
            if ($page == -1) {
                $im->readImage($pdf);
            } else {
                $im->readImage($pdf . "[" . $page . "]");
            }
        }catch (Exception $e){
            var_dump(iconv("gbk", 'utf-8',$e->getMessage()));
        }

        if ($page == -1) {
            $im->readImage($pdf);
        } else {
            $im->readImage($pdf . "[" . $page . "]");
        }
        foreach ($im as $Key => $Var) {
            $Var->setImageFormat('png');
            $filename = $path . md5($Key . time()) . '.png';
            if ($Var->writeImage($filename) == true) {
                $Return[] = $filename;
            }
        }
        //返回转化图片数组，由于pdf可能多页，此处返回二维数组。
        return $Return;
    }
}