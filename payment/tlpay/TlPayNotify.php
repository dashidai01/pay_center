<?php

namespace app\payment\tlpay;


use app\common\enum\OrderEnum;
use app\common\enum\UserAgreementEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\Security;
use app\common\vo\NotifyPayVo;
use app\common\vo\NotifyRefundVo;
use app\controller\UserCenter;
use app\model\Account;
use app\model\Brand;
use app\model\Order;
use app\model\User;
use app\model\UserAgreement;
use app\payment\NotifyInterface;
use Exception;
use think\facade\Log;

/**
 * Class LlPayNotify
 * @package app\payment\wxpay
 */
class TlPayNotify implements NotifyInterface
{
    /**
     * @param array $data
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function payNotify(array $data): void {
        try {
            // 验证参数
            $body = json_decode($data['bizContent'], true);
            if (empty($body)) {
                throw new ApiException('数据解析异常');
            }

            $where = [
                'running_no' => $body['bizOrderNo'],
                'status'     => OrderEnum::STATUS_UNPAY,
            ];

            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("订单不存在或状态异常");
            }

            // 修改订单状态
            if ($body['status'] == 'OK') {
                $data   = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time()
                ];
                $result = Order::where('running_no', $body['bizOrderNo'])->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
                /**
                 * 充值到用户
                 */
                if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                    $user_center = new UserCenter();
                    $user_center->Recharge($order);
                }
            }

            // 加入消息队列
            $notifyPayVo                     = new NotifyPayVo();
            $notifyPayVo->running_no         = $order->running_no;
            $notifyPayVo->appid              = $order->appid;
            $notifyPayVo->notify_time        = date("Y-m-d H:i:s");
            $notifyPayVo->notify_url         = $order->notify_url;
            $notifyPayVo->nonce_str          = getRandomStr(32);
            $notifyPayVo->money              = $order->money;
            $notifyPayVo->trade_no           = $order->order_no;
            $notifyPayVo->channel_running_no = $body['orderNo'];

            $data = $notifyPayVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret = Brand::where('appid', $order->appid)->value('secret');

            $security = new Security();

            $notifyPayVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyPayVo->toMap()
            ];

            $message  = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);
        } catch (Exception $e) {
            Log::record($e->getMessage() . $e->getFile() . $e->getLine());
            throw new ApiException($e->getMessage());
        }

    }


    /**
     * @param array $param
     * @throws ApiException
     */
    public function refundNotify(array $param): void {
        try {
            // 验证参数
            $params = json_decode($param['bizContent'], true);
            $where  = [
                'running_no' => $params['bizOrderNo']
            ];
            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("订单不存在或状态异常");
            }

            /** @var Account $account */
            $account = Account::where('id', $order->account_id)->find();
            if (!$account) {
                throw new ApiException("商户账号异常");
            }

            // 修改订单状态
            if ($params['status'] == 'OK') {
                $data   = [
                    'status'             => OrderEnum::STATUS_PAIED,
                    'pay_at'             => time(),
                    'channel_running_no' => $params['orderNo'] ?? ''
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
                /**
                 * 退款到用户
                 */
                if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                    $user_center = new UserCenter();
                    $user_center->Recharge($order, 'refund', '', $params['orderNo'] ?? '');
                }
            } else {
                $data   = [
                    'status'             => OrderEnum::STATUS_EXCEPTION,
                    'pay_at'             => time(),
                    'channel_running_no' => $params['orderNo'] ?? ''
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
            }

            // 加入消息队列
            $notifyPayVo                     = new NotifyRefundVo();
            $notifyPayVo->running_no         = $order->running_no;
            $notifyPayVo->appid              = $order->appid;
            $notifyPayVo->notify_time        = date("Y-m-d H:i:s");
            $notifyPayVo->notify_url         = $order->notify_url;
            $notifyPayVo->nonce_str          = getRandomStr(32);
            $notifyPayVo->refund_money       = $order->money;
            $notifyPayVo->trade_no           = $order->order_no;
            $notifyPayVo->channel_running_no = $params['orderNo'];
            $notifyPayVo->result_code        = $params['status'] == 'OK' ? 'SUCCESS' : 'FAIL';
          

            $data = $notifyPayVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret            = Brand::where('appid', $order['appid'])->value('secret');
            $security          = new Security();
            $notifyPayVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyPayVo->toMap()
            ];
            $message  = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);
        } catch (Exception $e) {
            Log::record($e->getMessage() . $e->getFile() . $e->getLine());
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param array $param
     * @return mixed|void
     * @throws ApiException
     */
    public function transferNotify(array $param) {
        try {
            // 验证参数
            $params = json_decode($param['bizContent'], true);
            $where  = [
                'running_no' => $params['bizOrderNo']
            ];
            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("订单不存在或状态异常");
            }

            /** @var Account $account */
            $account = Account::where('id', $order->account_id)->find();
            if (!$account) {
                throw new ApiException("商户账号异常");
            }

            // 修改订单状态
            if ($params['status'] == 'OK') {
                $data   = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time(),
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
            } else {
                $data   = [
                    'status' => OrderEnum::STATUS_EXCEPTION,
                    'pay_at' => time(),
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
            }

            // 加入消息队列
            $notifyPayVo                     = new NotifyPayVo();
            $notifyPayVo->running_no         = $order->running_no;
            $notifyPayVo->appid              = $order->appid;
            $notifyPayVo->notify_time        = date("Y-m-d H:i:s");
            $notifyPayVo->notify_url         = $order->notify_url;
            $notifyPayVo->nonce_str          = getRandomStr(32);
            $notifyPayVo->money              = $order->money;
            $notifyPayVo->trade_no           = $order->order_no;
            $notifyPayVo->channel_running_no = $params['orderNo'];
            $notifyPayVo->result_code        = $params['status'] == 'OK' ? 'SUCCESS' : 'FAIL';
            $notifyPayVo->fail_reason        = $params['failure_reason'] ?? '';

            $data = $notifyPayVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret            = Brand::where('appid', $order['appid'])->value('secret');
            $security          = new Security();
            $notifyPayVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyPayVo->toMap()
            ];
            $message  = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);
        } catch (Exception $e) {
            Log::record($e->getMessage() . $e->getFile() . $e->getLine());
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unionPayNotify(array $params): void {
        try {
            $running_no = $params['orderInfo']['txn_seqno'];
            $where      = [
                'running_no' => $running_no,
                'status'     => OrderEnum::STATUS_UNPAY,
            ];
            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("params: " . json_encode($where) . "订单不存在或状态异常");
            }

            /** @var Account $account */
            $account = Account::where('id', $order->account_id)->find();
            if (!$account) {
                throw new ApiException("商户账号异常");
            }

            // 修改订单状态
            if ($params['txn_status'] == 'TRADE_SUCCESS') {
                $where  = [
                    'running_no' => $running_no,
                ];
                $data   = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time()
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
                    $user_center->Recharge($order);
                }
            }

            // 加入消息队列
            $notifyPayVo                     = new NotifyPayVo();
            $notifyPayVo->running_no         = $order->running_no;
            $notifyPayVo->appid              = $order->appid;
            $notifyPayVo->notify_time        = date("Y-m-d H:i:s");
            $notifyPayVo->notify_url         = $order->notify_url;
            $notifyPayVo->nonce_str          = getRandomStr(32);
            $notifyPayVo->money              = $order->money;
            $notifyPayVo->trade_no           = $order->order_no;
            $notifyPayVo->channel_running_no = $params['accp_txno'];

            $data = $notifyPayVo->toMap();
            unset($data['sign']);
            unset($data['notify_url']);
            $secret            = Brand::where('appid', $order['appid'])->value('secret');
            $security          = new Security();
            $notifyPayVo->sign = $security->makeSign($data, $secret);

            $notifyVo = [
                'num'   => 0,
                'value' => $notifyPayVo->toMap()
            ];
            $message  = json_encode($notifyVo);
            $notifyMq = new NotifyMq();
            $notifyMq->payNotify($message);
        } catch (Exception $e) {
            Log::record($e->getMessage() . $e->getFile() . $e->getLine());
            throw new ApiException($e->getMessage());
        }

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
    public function unionRefundNotify(array $params): void {
        // 验证参数

        $where = [
            'appid' => $params['oid_partner'],
        ];
        /** @var Account $account */
        $account = Account::where($where)->find();
        if (!$account) {
            throw new ApiException("商户账号异常");
        }

        $where = [
            'running_no' => $params['refund_seqno'],
            'status'     => OrderEnum::STATUS_UNPAY,
        ];

        /** @var Order $order */
        $order = Order::where($where)->find();
        if (!$order) {
            throw new ApiException("订单不存在或状态异常");
        }

        // 修改订单状态
        if ($params['txn_status'] == 'TRADE_SUCCESS') {
            $data   = [
                'status' => OrderEnum::STATUS_PAIED,
                'pay_at' => time()
            ];
            $result = Order::where('running_no', $params['refund_seqno'])->update($data);
            if (!$result) {
                throw new ApiException("订单状态修改失败!");
            }
            /**
             * 退款到用户
             */
            if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                $user_center = new UserCenter();
                $user_center->Recharge($order, 'refund');
            }
        }

        // 加入消息队列
        $notifyRefundVo                     = new NotifyRefundVo();
        $notifyRefundVo->running_no         = $order->running_no;
        $notifyRefundVo->appid              = $order->appid;
        $notifyRefundVo->notify_time        = date("Y-m-d H:i:s");
        $notifyRefundVo->notify_url         = $order->notify_url;
        $notifyRefundVo->nonce_str          = getRandomStr(32);
        $notifyRefundVo->refund_money       = $order->money;
        $notifyRefundVo->trade_no           = $order->order_no;
        $notifyRefundVo->channel_running_no = $params['accp_txno'];

        $data = $notifyRefundVo->toMap();
        unset($data['sign']);
        unset($data['notify_url']);
        $secret               = Brand::where('appid', $order->appid)->value('secret');
        $security             = new Security();
        $notifyRefundVo->sign = $security->makeSign($data, $secret);

        $notifyVo = [
            'num'   => 0,
            'value' => $notifyRefundVo->toMap()
        ];
        $message  = json_encode($notifyVo);
        $notifyMq = new NotifyMq();
        $notifyMq->payNotify($message);
    }

    /**
     * @param $param
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function agreement($param) {
        $params = json_decode($param['bizContent'], true);

        $running_no = $param['notifyId'];
        $start_date = time();
        $end_date   = strtotime('2100-01-01');

        /** @var User $user */
        $user = User::where('corporate_sn', $params['bizUserId'])->find();

        /** @var UserAgreement $userAgreement */
        $userAgreement = UserAgreement::where('user_id', $user->id)->find();
        if (!empty($userAgreement)) {
            $userAgreement->agreement_number   = $params['acctProtocolNo'];
            $userAgreement->channel_running_no = $running_no;
            $userAgreement->status             = UserAgreementEnum::STATUS_ON;
            $userAgreement->is_sign            = UserAgreementEnum::IS_SIGN_ON;
            $userAgreement->start_date         = $start_date;
            $userAgreement->end_date           = $end_date;
            $userAgreement->updated_at         = time();
            $userAgreement->save();
        }
        return 'success';
    }
}
