<?php

namespace app\payment\wxpay;


use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\Security;
use app\common\vo\NotifyPayVo;
use app\common\vo\NotifyRefundVo;
use app\controller\UserCenter;
use app\model\Account;
use app\model\Brand;
use app\model\Order;
use app\payment\NotifyInterface;
use app\payment\wxpay\lib\WxPayConfig;
use app\payment\wxpay\lib\WxPayData;

/**
 * Class WxPayNotify
 * @package app\payment\wxpay
 */
class WxPayNotify implements NotifyInterface
{
    /**
     * @var WxPayConfig
     */
    protected $config;

    /**
     * @param Account $account
     */
    public function setConfig(Account $account): void
    {
        $config = new WxPayConfig();
        $config->appid = $account->appid;
        $config->merchantId = $account->business_no;
        $config->pay_secret = $account->business_secret;
        $config->sslCertPath = $account->business_public_rsa;
        $config->sslKeyPath = $account->business_private_rsa;

        $this->config = $config;
    }

    /**
     * @param array $params
     * @throws ApiException
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function payNotify(array $params): void
    {
        // 验证参数
        if (!isset($params['sign'])) {
            throw new ApiException("参数异常");
        }

        $where = [
            'running_no' => $params['out_trade_no'],
            'status' => OrderEnum::STATUS_UNPAY,
        ];
        /** @var Order $order */
        $order = Order::where($where)->find();
        if (!$order) {
            throw new ApiException("params: ". json_encode($where). "订单不存在或状态异常");
        }

        /** @var Account $account */
        $account = Account::where('id', $order->account_id)->find();
        if (!$account) {
            throw new ApiException("商户账号异常");
        }
        $this->setConfig($account);

        // 验签
        $wxPayData = new WxPayData($this->config);
        /** @var WxPayData $wxPayData */
        $result = $wxPayData->checkSign($params);

        if (!$result) {
            throw new ApiException("验签错误");
        }

        // 修改订单状态
        if ($params['result_code'] == 'SUCCESS') {
            $where = [
                'running_no' => $params['out_trade_no'],
            ];
            $data = [
                'status' => OrderEnum::STATUS_PAIED,
                'pay_at' => time(),
                'channel_running_no' => $params['transaction_id'],
            ];
            $result = Order::where($where)->update($data);
            if (!$result) {
                throw new ApiException("订单状态修改失败!");
            }
            /**
             * 充值到用户
             */
            if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                $user_center = new UserCenter();
                $user_center->Recharge($order, 'recharge', '', $params['transaction_id']);
            }
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
        $notifyPayVo->channel_running_no = $params['transaction_id'];

        $data = $notifyPayVo->toMap();
        unset($data['sign']);
        unset($data['notify_url']);
        $secret = Brand::where('appid', $order->appid)->value('secret');
        $security = new Security();
        $notifyPayVo->sign = $security->makeSign($data, $secret);

        $notifyVo = [
            'num' => 0,
            'value' => $notifyPayVo->toMap()
        ];
        $message = json_encode($notifyVo);
        $notifyMq = new NotifyMq();
        $notifyMq->payNotify($message);
    }


    /**
     * @param array $params
     * @throws ApiException
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     * @throws \AMQPExchangeException
     * @throws \AMQPQueueException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundNotify(array $params): void
    {
        // 验证参数
        if(!isset($params['return_code']) || ($params['return_code'] != 'SUCCESS')) {
            throw new ApiException("回调参数异常");
        }

        if (!isset($params['req_info'])) {
            throw new ApiException("参数异常");
        }

        $where = [
            'appid' => $params['appid'],
            'business_no' => $params['mch_id'],
        ];
        /** @var Account $account */
        $account = Account::where($where)->find();
        if (!$account) {
            throw new ApiException("商户账号异常");
        }

        $this->setConfig($account);
        $wxPayData = new WxPayData($this->config);
        $response = $wxPayData->decript($params['req_info']);
        if(!$response) {
            throw new ApiException("加密数据串异常，解密失败");
        }

        $where = [
            'running_no' => $response['out_refund_no'],
            'status' => OrderEnum::STATUS_UNPAY,
        ];

        /** @var Order $order */
        $order = Order::where($where)->find();
        if (!$order) {
            throw new ApiException("params: ". json_encode($where). "订单不存在或状态异常");
        }

        // 修改订单状态
        if ($response['refund_status'] == 'SUCCESS') {
            $data = [
                'status' => OrderEnum::STATUS_PAIED,
                'pay_at' => time(),
                'channel_running_no' => $response['transaction_id'],
            ];
            $result = Order::where('running_no', $response['out_refund_no'])->update($data);
            if (!$result) {
                throw new ApiException("订单状态修改失败!");
            }
            /**
             * 退款到用户
             */
            if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                $user_center = new UserCenter();
                $user_center->Recharge($order, 'refund', '', $response['transaction_id']);
            }
        }

        // 加入消息队列
        $notifyRefundVo = new NotifyRefundVo();
        $notifyRefundVo->running_no = $order->running_no;
        $notifyRefundVo->appid = $order->appid;
        $notifyRefundVo->notify_time = date("Y-m-d H:i:s");
        $notifyRefundVo->notify_url = $order->notify_url;
        $notifyRefundVo->nonce_str = getRandomStr(32);
        $notifyRefundVo->refund_money = $order->money;
        $notifyRefundVo->trade_no = $order->order_no;
        $notifyRefundVo->channel_running_no = $response['transaction_id'];

        $data = $notifyRefundVo->toMap();
        unset($data['sign']);
        unset($data['notify_url']);
        $secret = Brand::where('appid', $order->appid)->value('secret');
        $security = new Security();
        $notifyRefundVo->sign = $security->makeSign($data, $secret);

        $notifyVo = [
            'num' => 0,
            'value' => $notifyRefundVo->toMap()
        ];
        $message = json_encode($notifyVo);
        $notifyMq = new NotifyMq();
        $notifyMq->payNotify($message);
    }

    public function transferNotify(array $params) {
        // TODO: Implement transferNotify() method.
    }

}
