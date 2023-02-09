<?php

namespace app\payment\llpay;


use app\common\enum\AccountEnum;
use app\common\enum\FlowBillEnum;
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
use app\model\FlowAccount;
use app\model\FlowBill;
use app\model\Order;
use app\model\UserAgreement;
use app\payment\NotifyInterface;
use app\payment\wxpay\lib\WxPayConfig;
use Exception;
use think\facade\Log;

/**
 * Class LlPayNotify
 * @package app\payment\wxpay
 */
class LlPayNotify implements NotifyInterface
{
    /**
     * @var WxPayConfig
     */
    protected $config;

    /**
     * @param Account $account
     */
    public function setConfig(Account $account): void {
        $config              = new WxPayConfig();
        $config->appid       = $account->appid;
        $config->merchantId  = $account->business_no;
        $config->pay_secret  = $account->business_secret;
        $config->sslCertPath = $account->business_public_rsa;
        $config->sslKeyPath  = $account->business_private_rsa;

        $this->config = $config;
    }

    /**
     * @param array $params
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function payNotify(array $params): void {
        try {
            $where = [
                'running_no' => $params['no_order'],
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
            $this->setConfig($account);

            // 修改订单状态
            if ($params['result_pay'] == 'SUCCESS') {
                $where  = [
                    'running_no' => $params['no_order'],
                ];
                $data   = [
                    'status'             => OrderEnum::STATUS_PAIED,
                    'pay_at'             => time(),
                    'channel_running_no' => $params['oid_paybill'],
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
                    $user_center->Recharge($order, 'recharge', '', $params['oid_paybill']);
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
            $notifyPayVo->channel_running_no = $params['oid_paybill'];

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
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundNotify(array $params): void {
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
            'running_no' => $params['no_refund'],
            'status'     => OrderEnum::STATUS_UNPAY,
        ];

        /** @var Order $order */
        $order = Order::where($where)->find();
        if (!$order) {
            throw new ApiException("params: " . json_encode($where) . "订单不存在或状态异常");
        }

        // 修改订单状态
        if ($params['sta_refund'] == '2') {
            $data   = [
                'status'             => OrderEnum::STATUS_PAIED,
                'pay_at'             => time(),
                'channel_running_no' => $params['oid_refundno'],
            ];
            $result = Order::where('running_no', $params['no_refund'])->update($data);
            if (!$result) {
                throw new ApiException("订单状态修改失败!");
            }
            /**
             * 退款到用户
             */
            if ($order->order_type == OrderEnum::ORDER_TYPE_ADD) {
                $user_center = new UserCenter();
                $user_center->Recharge($order, 'refund', '', $params['oid_refundno']);
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
        $notifyRefundVo->channel_running_no = $params['oid_refundno'];

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
     * @param array $params
     * @return mixed|void
     * @throws ApiException
     */
    public function transferNotify(array $params) {
        try {
            $running_no = $params['orderInfo']['txn_seqno'];
            $where      = [
                'running_no' => $running_no
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
            if ($params['txn_status'] == 'TRADE_SUCCESS') {
                $where  = [
                    'running_no' => $running_no,
                ];
                $data   = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time(),
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
            } else if ($params['txn_status'] == 'TRADE_CANCEL') {
                $data   = [
                    'status' => OrderEnum::STATUS_EXCEPTION,
                    'pay_at' => time(),
                ];
                $result = Order::where($where)->update($data);
                if (!$result) {
                    throw new ApiException("订单状态修改失败!");
                }
            } else if ($params['txn_status'] == 'TRADE_FAILURE') {
                $data   = [
                    'status'      => OrderEnum::STATUS_EXCEPTION,
                    'pay_at'      => time(),
                    'fail_reason' => ($params['failure_reason'] ?? '') . ($params['chnl_reason'] ?? ''),
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
            $notifyPayVo->channel_running_no = $params['accp_txno'];
            $notifyPayVo->result_code        = $params['txn_status'] == 'TRADE_SUCCESS' ? 'SUCCESS' : 'FAIL';
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
                $method = $params["payerInfo"][0]["method"] ?? "";
                $data   = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'll_method_type'=> $method,
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
     * 签约成功回调
     * @param $data
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function applyAgreement($data) {

        $running_no = $data['txn_seqno'];
        $start_date = strtotime($data['papSignInfo']['sign_start_time']);
        $end_date   = strtotime($data['papSignInfo']['sign_invalid_time']);
        /** @var UserAgreement $userAgreement */
        $userAgreement = UserAgreement::where('trade_number', $running_no)->find();

        if (!empty($userAgreement)) {
            $userAgreement->agreement_number = $data['papSignInfo']['pap_agree_no'];
            $userAgreement->status           = UserAgreementEnum::STATUS_ON;
            $userAgreement->is_sign          = UserAgreementEnum::IS_SIGN_ON;
            $userAgreement->start_date       = $start_date;
            $userAgreement->end_date         = $end_date;
            $userAgreement->updated_at       = time();
            $userAgreement->save();
        }

        /** @var UserAgreement $originAgreement */
        $originAgreement = UserAgreement::where('new_trade_number', $running_no)->find();
        if (!empty($originAgreement)) {
            $originAgreement->status = UserAgreementEnum::STATUS_OFF;
            $originAgreement->save();
        }
        return 'success';
    }

    /**
     * 关闭协议
     * @param $data
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function closeAgreement($data) {
        $running_no = $data['txn_seqno'];
        /** @var UserAgreement $userAgreement */
        $userAgreement = UserAgreement::where('new_trade_number', $running_no)->find();
        if ($userAgreement) {
            $userAgreement->is_sign    = UserAgreementEnum::IS_SIGN_OFF;
            $userAgreement->status     = UserAgreementEnum::STATUS_OFF;
            $userAgreement->updated_at = time();
            $userAgreement->save();
        }

        return 'success';
    }

    /**
     * @param $param
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function llAutoCharge($param) {
        /** @var FlowAccount $flowAccount */
        $flowAccount = FlowAccount::where(['account_no' => ($param['virtualno'] ?? ''), 'status' => AccountEnum::STATUS_NORMAL])->find();
        if ($flowAccount) {
            $flowBill = FlowBill::where('running_no', $param['accp_txno'])->find();
            if (!empty($flowBill)) {
                Log::record('流水号:' . $param['accp_txno'] . '已存在!');
                return false;
            }
            $flowBillData = [
                'running_no'         => $param['accp_txno'],
                'flow_account_id'    => $flowAccount->id,
                'money'              => (int)($param['amount'] * 100),
                'payment_account'    => $param['payer_acctno'],
                'payment_bank'       => '',
                'brand_id'           => $flowAccount->brand_id,
                'brand_name'         => $flowAccount->brand_name,
                'status'             => FlowBillEnum::STATUS_NORMAL,
                'operator'           => '官方',
                'source'             => FlowBillEnum::SOURCE_ALIPAY,
                'founder_no'         => '官方',
                'trade_time'         => strtotime($param['chnl_time']),
                'created_at'         => time(),
                'updated_at'         => time(),
                'type'               => FlowBillEnum::TYPE_LL,
                'payment_name'       => $param['payer_acctname'],
                'remark'             => trim($param['postscript']),
                'collection_account' => $param['virtualno']
            ];
            $flowBill     = new FlowBill();
            $flowBill->save($flowBillData);
        }
        return true;
    }
}
