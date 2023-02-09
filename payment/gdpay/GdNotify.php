<?php

namespace app\payment\gdpay;


use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\Security;
use app\common\vo\NotifyTransferVo;
use app\model\Account;
use app\model\Brand;
use app\model\Order;
use app\payment\NotifyInterface;
use think\facade\Log;

class GdNotify implements NotifyInterface
{
    /**
     * @param Account $account
     * @param $data
     * @return string
     */
    public function decrypt(Account $account, $data) {

        $appSecret = $account->business_private_rsa;
        $aesIV     = $account->appid;
        return openssl_decrypt($data, 'aes-256-cbc', $appSecret, 0, $aesIV);
    }

    /**
     * @param array $params
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function payNotify(array $params) {
    }

    public function refundNotify(array $params) {

    }

    /**
     * @param array $params
     * @return mixed|void
     * @throws ApiException
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function transferNotify(array $params) {

        $requestId = $params['request_id'] ?? '';
        if (!$requestId) {
            throw new ApiException('request_id不存在!');
        }
        /** @var Order $order */
        $order = Order::where('channel_running_no', $requestId)->find();
        if (!$order) {
            throw new ApiException('订单不存在!');
        }
        /** @var Account $account */
        $account = Account::where('id', $order->account_id)->find();
        if (!$account) {
            throw new ApiException('账户不存在!');
        }
        $content = $params['data'];
        if (!$content) {
            throw new ApiException('信息不存在!');
        }
        $data = $this->decrypt($account, $content);
        Log::record('高灯转账回调解密参数' . $data);
        if (!$data) {
            throw new ApiException("数据解密失败");
        }
        $data = json_decode($data, true);
        if(isset($data['refund_service_amount'])) {
            $order->status = OrderEnum::STATUS_PAIED;
            $order->pay_order_id = $data['settlement_code'] ?? '';
        } else {
            $data = $data[0];
            if ($data['status'] == '1004' || $data['status'] == '200') {
                // 1004： 打款失败，需要操作退款
                // 200： 审核失败， 状态为100和300的订单，通过结算后台删除后状态为200.
                $order->status = OrderEnum::STATUS_EXCEPTION;
            } else if ($data['status'] == '1000' || $data['status'] == '5000') {
                // 1000：打款成功
                // 5000：已完税
                $order->status = OrderEnum::STATUS_PAIED;
            } else if ($data['status'] == '750') {
                // 750：退至余额
                $order->status = OrderEnum::STATUS_CLOSE;
            } else {
                // 中间状态
                $order->status = OrderEnum::STATUS_DEALING;
            }
            if (isset($data['fail_reason']) && $data['fail_reason']) {

                $order->fail_reason = $data['fail_reason'];
            }
            $order->pay_order_id = $data['settlement_code'] ?? '';
            $order->code = $data['status'];
        }


        if (!$order->save()) {
            throw new ApiException('订单状态修改失败!');
        }
        // 加入消息队列
        $notifyTransferVo                     = new NotifyTransferVo();
        $notifyTransferVo->running_no         = $order->running_no;
        $notifyTransferVo->appid              = $order->appid;
        $notifyTransferVo->notify_time        = date("Y-m-d H:i:s");
        $notifyTransferVo->notify_url         = $order->notify_url;
        $notifyTransferVo->nonce_str          = getRandomStr(32);
        $notifyTransferVo->money              = $order->money;
        $notifyTransferVo->trade_no           = $order->order_no;
        $notifyTransferVo->channel_running_no = $order->channel_running_no;
        $notifyTransferVo->result_code        = $data['status'] ?? 'SUCCESS';
        $notifyTransferVo->fail_reason        = $data['fail_reason'] ?? '';

        $data = $notifyTransferVo->toMap();
        unset($data['sign']);
        unset($data['notify_url']);
        $secret   = Brand::where('appid', $order->appid)->value('secret');
        $security = new Security();
        Log::record('加签' . json_encode($data, JSON_UNESCAPED_UNICODE) . 'secret:' . $secret);
        $notifyTransferVo->sign = $security->makeSign($data, $secret);

        $notifyVo = [
            'num'   => 0,
            'value' => $notifyTransferVo->toMap()
        ];
        $message  = json_encode($notifyVo);
        $notifyMq = new NotifyMq();
        $notifyMq->payNotify($message);

    }
}