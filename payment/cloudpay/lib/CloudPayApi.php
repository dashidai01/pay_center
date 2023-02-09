<?php

namespace app\payment\cloudpay\lib;

use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\AliOss;
use app\common\tool\Security;
use app\common\tool\SnowFlake;
use app\common\vo\NotifyPayVo;
use app\model\Brand;
use app\model\Order;
use app\model\OrderDetail;
use app\model\OrderReceipt;
use app\validate\DispatchValidate;
use GuzzleHttp\Client;
use think\Exception;
use think\facade\Log;

class CloudPayApi extends CloudPayBase {

    /**
     * 批量流水号
     * @var
     */
    public $batch_running_no;
    /**
     * 订单
     * @var
     */
    public $batch_order;

    /**
     * 查询签约结果
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function queryCloudSignResult() {
        $param = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $dealer_id = $account->appid;
        $broker_id = $account->business_no;
        $data = [
            'dealer_id' => $dealer_id,
            'broker_id' => $broker_id,
            'real_name' => $param['real_name'],
            'id_card'   => $param['id_card']
        ];
        return $this->execute('api/payment/v1/sign/user/status', $data, 'get');
    }

    /**
     * 创建签约信息
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function createCloudSign() {
        $param = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $brand = ContextPay::getBrand();
        $dealer_id = $account->appid;
        $broker_id = $account->business_no;
        $var = $param['data'] ?? '';
        $data = [
            'dealer_id'  => $dealer_id,
            'broker_id'  => $broker_id,
            'real_name'  => $param['real_name'],
            'id_card'    => $param['id_card'],
            'phone'      => $param['phone'],
            'notify_url' => env('PAYMENT.DOMAIN').'/notify/cloud/sign/result?appid='.$brand->appid.'&var='.$var,
        ];
        return $this->execute('api/payment/v1/sign/user', $data);
    }

    /**
     * 云账户转账
     * @param $running_no
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch($running_no) {
        $params = ContextPay::getRaw();
        validate(DispatchValidate::class)->scene('cloudTransfer')->check($params);
        $dispatchDto = ContextPay::getDispatchDto();
        $account = ContextPay::getAccount();
        $dealer_id = $account->appid;
        $broker_id = $account->business_no;
        $money = (string)($dispatchDto->money / 100);
        $money = sprintf('%.2f', $money);
        $data = [
            'order_id'   => $running_no,
            'dealer_id'  => $dealer_id,
            'broker_id'  => $broker_id,
            'real_name'  => $dispatchDto->name,
            'card_no'    => $dispatchDto->identity,
            'id_card'    => $dispatchDto->id_card_number,
            'pay'        => $money,
            'pay_remark' => $dispatchDto->remark,
            'notify_url' => env('PAYMENT.DOMAIN').'/notify/cloud/dispatch',
        ];

        if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_BANK) {
            $url = 'api/payment/v1/order-bankpay';
        } else if (ContextPay::getMixed() == AccountEnum::TRNASFER_TYPE_ALIPAY) {
            $url = 'api/payment/v1/order-alipay';
        } else {
            throw new ApiException('暂不支持的转账类型!');
        }
        return $this->execute($url, $data);
    }

    /**
     * 查询订单状态
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery() {
        $order = ContextPay::getOrder();
        if ($order->transfer_type == 'alipay') {
            $channel = '支付宝';
        } else if ($order->transfer_type == 'bank') {
            $channel = '银行卡';
        } else {
            throw new ApiException('暂不支持的转账类型!');
        }
        $data = [
            'order_id' => $order->running_no,
            'channel'  => $channel
        ];
        return $this->execute('api/payment/v1/query-order', $data, 'get');
    }

    /**
     * 查看电子回单
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchReceipt() {
        $order = ContextPay::getOrder();
        $data = [
            'order_id' => $order->running_no,
        ];
        return $this->execute('api/payment/v1/receipt/file', $data, 'get');
    }

    /**
     * 查询云账户余额
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function queryCloudBalance() {
        $account = ContextPay::getAccount();
        $dealer_id = $account->appid;
        $data = [
            'dealer_id' => $dealer_id,
        ];
        return $this->execute('api/payment/v1/query-accounts', $data, 'get');
    }

    public function batchDispatch() {
        $params = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $dealer_id = $account->appid;
        $broker_id = $account->business_no;
        validate(DispatchValidate::class)->scene('batchCloudTransfer')->check($params);

        $list = $params['list'] ?? [];
        $order_list = [];
        foreach ($list as $key => $value) {
            validate(DispatchValidate::class)->scene('batchCloudList')->check($value);
            $where = [
                'order_no' => $value['order_sn'],
                'status'   => [OrderEnum::STATUS_PAIED,OrderEnum::STATUS_DEALING]
            ];
            $order = Order::where($where)->find();
            if ($order) {
                throw new ApiException('订单编号:'.$value['order_sn'].'重复!');
            }
            $money = (string)($value['money'] / 100);
            $money = sprintf('%.2f', $money);
            $order_list[$key]['order_id'] = SnowFlake::createOnlyId();
            $order_list[$key]['real_name'] = (string)$value['name'];
            $order_list[$key]['id_card'] = (string)$value['id_card_number'];
            $order_list[$key]['pay'] = $money;
            $order_list[$key]['pay_remark'] = (string)$value['remark'] ?? '';
            $order_list[$key]['notify_url'] = env('PAYMENT.DOMAIN').'/notify/cloud/dispatch';
            $order_list[$key]['card_no'] = (string)$value['identity'] ?? '';
            $order_list[$key]['phone'] = (string)$value['identity'] ?? '';
        }
        $total_money = array_sum(array_column($order_list, 'pay'));
        $total_count = count($order_list);
        $this->batch_running_no = SnowFlake::createOnlyId();

        if ($params['transfer_type'] == 'alipay') {
            $channel = '支付宝';
        } else if ($params['transfer_type'] == 'bank') {
            $channel = '银行卡';
        } else {
            throw new ApiException('暂不支持的转账类型!');
        }
        $this->batch_order = array_merge($order_list);
        $data = [
            'batch_id'    => $this->batch_running_no,
            'broker_id'   => $broker_id,
            'dealer_id'   => $dealer_id,
            'total_pay'   => (string)$total_money,
            'mode'        => 'direct',
            'total_count' => (string)$total_count,
            'order_list'  => $order_list,
            'channel'     => $channel
        ];
        $result = $this->execute('api/payment/v1/order-batch', $data);
        $this->save($result);
        return $result;
    }

    /**
     * @param $result
     * @return mixed
     * @throws Exception
     */
    public function save($result) {
        $params = ContextPay::getRaw();
        $list = $this->batch_order;

        $account = ContextPay::getAccount();
        $brand = ContextPay::getBrand();

        foreach ($list as $key => $value) {
            $order = new Order();
            $orderDetail = new OrderDetail();
            $order->appid = $brand->appid;
            $order->order_no = $params['list'][$key]['order_sn'] ?? '';
            $order->user_no = $value['user_no'] ?? '';
            $order->notify_url = $params['notify_url'] ?? '';
            $order->money = $value['pay'] * 100;
            $order->account_id = $account->id;
            $order->channel_id = $account->channel_id;
            $order->channel_name = $account->channel_name;
            $order->brand_id = $brand->brand_id;
            $order->brand_name = $brand->name;
            $order->source = OrderEnum::SOURCE_OUT;
            $order->running_no = $value['order_id'];
            $order->status = OrderEnum::STATUS_DEALING;  // 默认支付中
            $order->remark = $value['pay_remark'] ?? '';
            // 三方流水号
            foreach ($result['result_list'] as $k => $v) {
                if ($v['order_id'] == $value['order_id']) {
                    $order->channel_running_no = $v['ref'];
                }
            }
            $order->transfer_type = ContextPay::getMixed() ?? '';
            $order->founder_name = $params['founder_name'] ?? '';
            $order->founder_no = $params['founder_no'] ?? '';
            $order->batch_running_no = $this->batch_running_no;
            $order->code = $result['code'] ?? '';
            $order->save();

            $orderDetail->order_id = $order->id;
            $orderDetail->real_name = $params['list'][$key]['name'] ?? '';            // 真实姓名
            $orderDetail->receive_number = $params['list'][$key]['identity'] ?? '';        // 收款标识
            $orderDetail->receive_bank = $params['list'][$key]['inst_name'] ?? '';       // 收款标识
            $orderDetail->phone = $params['list'][$key]['phone'] ?? '';           // 电话号码
            $orderDetail->id_card_number = $params['list'][$key]['id_card_number'] ?? '';  // 身份证号码
            $orderDetail->save();
        }
        return true;
    }

    /**
     * @param $param
     * @throws ApiException
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function dispatchNotify($param) {
        try {
            $data = $this->cloudDecrypt($param);
            Log::record('单笔转账解密数据: '.$data);
            $data = json_decode($data, true);
            $callbackData = $data['data'];
            $running_no = $callbackData['order_id'] ?? '';
            $where = [
                'running_no' => $running_no
            ];
            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("订单不存在或状态异常");
            }

            // 修改订单状态
            /**
             * -1.已无效  最终态，不会回调 API 接口支付不会出现此状态
             * 0.已受理  中间态，不会回调
             * 1.已支付  订单提交到支付网关成功（中间状态，会回调）
             * 2.失败 最终态，会回调）
             * 4.订单挂起  中间态，会回调
             * 5.支付中  中间态，不会回调
             * 8.待支付  中间态，不会回调
             * 9.失败 支付被退回 最终态，会回调
             * 15.取消支付 待支付（暂停处理）订单数据被平台企业主动取消 最终态，会回调
             */
            if ($callbackData['status'] == 1) {
                $data = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time(),
                ];
            } else {
                $data = [
                    'status'      => OrderEnum::STATUS_EXCEPTION,
                    'pay_at'      => time(),
                    'fail_reason' => $callbackData['status_detail_message'] ?? '',
                ];
            }
            $result = Order::where($where)->update($data);
            if (!$result) {
                throw new ApiException("订单状态修改失败!");
            }

            // 加入消息队列
            $notifyPayVo = new NotifyPayVo();
            $notifyPayVo->running_no = $order->running_no;
            $notifyPayVo->appid = $order->appid;
            $notifyPayVo->notify_time = date("Y-m-d H:i:s");
            $notifyPayVo->notify_url = $order->notify_url;
            $notifyPayVo->nonce_str = getRandomStr(32);
            $notifyPayVo->money = $order->money;
            $notifyPayVo->trade_no = $order->order_no;
            $notifyPayVo->channel_running_no = $callbackData['ref'];
            $notifyPayVo->result_code = $callbackData['status'] == '1' ? 'SUCCESS' : 'FAIL';
            $notifyPayVo->fail_reason = $callbackData['status_detail_message'] ?? '';

            $data = $notifyPayVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret = Brand::where('appid', $order['appid'])->value('secret');
            $security = new Security();
            $notifyPayVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyPayVo->toMap()
            ];
            $message = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);

        } catch (\Exception $e) {
            Log::record($e->getMessage().$e->getFile().$e->getLine());
            throw new ApiException($e->getMessage());
        }
    }

    /**
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
            $result = $this->searchReceipt();
            $path   = $this->getCloudReceipt($result);
        }
        return ['download_url' => $path];
    }
    /**
     * @param $result
     * @return string
     * @throws ApiException
     */
    public function getCloudReceipt($result) {
        $order       = ContextPay::getOrder();
        $file        = $result['url'] ?? '';
        $ext = pathinfo( parse_url( $file, PHP_URL_PATH ), PATHINFO_EXTENSION );
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
        $file_path = $dir_path . '/' . SnowFlake::createOnlyId() . '.'.$ext;
        file_put_contents($file_path, $pdfData);

        $savePath = 'pay_center/pdf/' . SnowFlake::createOnlyId() . '.'.$ext;

        $aliOss   = new AliOss();
        $flag     = $aliOss->upload($savePath, $file_path);
        if ($flag) {
            @unlink($file_path);
        }
        $oss_path = $aliOss->getUrl($savePath);
        $order_receipt             = new OrderReceipt();
        $order_receipt->running_no = $order->running_no;
        $order_receipt->created_at = time();
        $order_receipt->file_path = $savePath;
        $order_receipt->save();
        return $oss_path;
    }

    /**
     * @param $param
     * @param Brand $brand
     * @param $var
     * @return string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function signNotify($param,Brand $brand,$var=''){
        try {
            $data = $this->cloudDecrypt($param);
            Log::record('签约结果通知: '.$data);
            $data = json_decode($data, true);

            $postData = [
                'id_card'   => $data['id_card'],
                'phone'     => $data['phone'],
                'real_name' => $data['real_name'],
                'brand_id'  => $brand->brand_id,
                'data'      => $var
            ];
            $option = [
              'json' =>  $postData
            ];
            $client = new Client();
            $result = $client->post($brand->cloud_notify_url,$option);
            $content = $result->getBody()->getContents();
            Log::record('签约通知用户中心:'.$content);
            return 'success';
        }catch (\Exception $e){
            Log::record('签约通知用户中心失败:'.$e->getMessage());
            throw new ApiException($e->getMessage());
        }
    }
}