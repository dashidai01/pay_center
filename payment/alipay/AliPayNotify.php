<?php
namespace app\payment\alipay;


use Alipay\EasySDK\Kernel\Config;
use Alipay\EasySDK\Kernel\Factory;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\NotifyMq;
use app\common\tool\AliOss;
use app\common\tool\Security;
use app\common\vo\NotifyPayVo;
use app\common\vo\NotifyRefundVo;
use app\common\vo\NotifyTransferVo;
use app\controller\UserCenter;
use app\model\Account;
use app\model\Brand;
use app\model\Order;
use app\payment\NotifyInterface;
use Exception;
use think\facade\Env;
use think\facade\Log;

class AliPayNotify implements NotifyInterface
{
    /**
     * @param Account $account
     * @throws ApiException
     */
    public function setConfig(Account $account): void
    {
        $options = new Config();
        $options->protocol = Env::get('PAYMENT.ALIPAY_PROTOCOL');
        $options->gatewayHost = Env::get('PAYMENT.ALIPAY_GATEWAY_HOST');
        $options->signType =Env::get('PAYMENT.ALIPAY_SIGNTYPE');

        $options->appId = $account->appid;
        // 公钥模式
        if ($account->alipay_type == AccountEnum::ALIPAY_TYPE_1) {
            // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
            $options->merchantPrivateKey = $account->business_private_rsa;
            //注：如果采用非证书模式，则无需赋值上面的三个证书路径，改为赋值如下的支付宝公钥字符串即可
            $options->alipayPublicKey = $account->channel_public_rsa;
        } else if ($account->alipay_type == AccountEnum::ALIPAY_TYPE_2) { // 公钥证书模式
            $aliOss = new AliOss();
            // 为避免私钥随源码泄露，推荐从文件中读取私钥字符串而不是写入源码中
            $options->merchantPrivateKey = $account->business_private_rsa;
            //支付宝公钥证书文件路径，例如：/foo/alipayCertPublicKey_RSA2.crt
            $options->alipayCertPath = $aliOss->getUrl($account->alipayCertPath);
            //支付宝根证书文件路径，例如：/foo/alipayRootCert.crt
            $options->alipayRootCertPath = $aliOss->getUrl($account->alipayRootCertPath);
            //公钥证书文件路径，例如：/foo/appCertPublicKey_2019051064521003.crt
            $options->merchantCertPath = $aliOss->getUrl($account->merchantCertPath);
        }
        //可设置异步通知接收服务地址（可选）
        $options->notifyUrl = Env::get('PAYMENT.ALIPAY_NOTIFY');

        //可设置AES密钥，调用AES加解密相关接口时需要（可选）
        $options->encryptKey =$account->aes_secret;

        Factory::setOptions($options);
    }

    /**
     * @param array $params
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function payNotify(array $params)
    {
        try {
            // 验证参数
            if (!isset($params['sign'])) {
                throw new ApiException("参数异常");
            }
            $where = [
                'running_no' => (string) $params['out_trade_no'],
                'status' => (int) OrderEnum::STATUS_UNPAY,
            ];

            /** @var Order $order */
            $order = Order::where($where)->find();
            if (!$order) {
                throw new ApiException("params: ". json_encode($where). "订单不存在或状态异常");
            }

            /** @var Account $account */
            $account = Account::where('id', $order['account_id'])->find();
            if (!$account) {
                throw new ApiException("商户账号异常");
            }
            $this->setConfig($account);

            $result = Factory::payment()->common()->verifyNotify($params);
            if (!$result) {
                throw new ApiException("验签错误");
            }

            // 修改订单状态
            if ($params['trade_status'] == 'TRADE_SUCCESS') {
                $where = [
                    'running_no' => $params['out_trade_no'],
                ];
                $data = [
                    'status' => OrderEnum::STATUS_PAIED,
                    'pay_at' => time(),
                    'channel_running_no' => $params['trade_no'],
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
                    $user_center->Recharge($order, 'recharge', '', $params['trade_no']);
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
            $notifyPayVo->channel_running_no = $params['trade_no'];

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

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param array $params
     * @return mixed|void
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws Exception
     */
    public function refundNotify(array $params)
    {
        // 验证参数
        if (!isset($params['sign'])) {
            throw new ApiException("参数异常");
        }

        $where = [
            'running_no' => (string) $params['out_biz_no'],
            'status' => OrderEnum::STATUS_UNPAY,
        ];

        /** @var Order $order */
        $order = Order::where($where)->find();
        if (!$order) {
            throw new ApiException("订单不存在或状态异常");
        }

        /** @var Account $account */
        $account = Account::where('id', $order['account_id'])->find();
        if (!$account) {
            throw new ApiException("商户账号异常");
        }
        $this->setConfig($account);
        $result = Factory::payment()->common()->verifyNotify($params);
        if (!$result) {
            throw new ApiException("验签错误");
        }

        // 修改订单状态
        if ($params['trade_status'] == 'TRADE_SUCCESS') {
            $where = [
                'running_no' => $params['out_biz_no'],
            ];
            $data = [
                'status' => OrderEnum::STATUS_PAIED,
                'pay_at' => time(),
                'channel_running_no' => $params['trade_no'],
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
                $user_center->Recharge($order, 'refund', '', $params['trade_no']);
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
        $notifyRefundVo->channel_running_no = $params['trade_no'];

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
        // 验证参数
        if (!isset($params['sign'])) {
            throw new ApiException("参数异常");
        }

        $appId = $params['app_id'] ?? '';
        if(!$appId) {
            throw new ApiException('appid不存在!');
        }
        /** @var Account $account */
        $account = Account::where('appid',$appId)->find();
        if(!$account) {
            throw new ApiException('账户不存在!');
        }
        $content = json_decode($params['biz_content'],true);
        if(!$content) {
            throw new ApiException('信息不存在!');
        }
        $running_no = $content['out_biz_no'];
        $where = [
            "running_no" => $running_no
        ];
        /** @var Order $order */
        $order = Order::where($where)->find();
        if(!$order) {
            throw new ApiException('订单不存在!');
        }

        $this->setConfig($account);
        $result = Factory::payment()->common()->verifyNotify($params);

        if (!$result) {
            throw new ApiException("验签错误");
        }

        if($content['status'] == 'DEALING') {
            $order->status = OrderEnum::STATUS_DEALING ;
            if(!$order->save()) {
                throw new ApiException('订单状态修改失败!');
            }
        } else if($content['status'] == 'SUCCESS'){
            $order->status = OrderEnum::STATUS_PAIED;
            if(!$order->save()) {
                throw new ApiException('订单状态修改失败!');
            }
        } else if($content['status'] == 'FAIL'){
            $order->status = OrderEnum::STATUS_EXCEPTION;
            $order->fail_reason = $content['fail_reason'] ?? '';
            if(!$order->save()) {
                throw new ApiException('订单状态修改失败!');
            }
        }
        // 加入消息队列
        $notifyTransferVo = new NotifyTransferVo();
        $notifyTransferVo->running_no = $order->running_no;
        $notifyTransferVo->appid = $order->appid;
        $notifyTransferVo->notify_time = date("Y-m-d H:i:s");
        $notifyTransferVo->notify_url = $order->notify_url;
        $notifyTransferVo->nonce_str = getRandomStr(32);
        $notifyTransferVo->money = $order->money;
        $notifyTransferVo->trade_no = $order->order_no;
        $notifyTransferVo->channel_running_no = $order->channel_running_no;
        $notifyTransferVo->result_code = $content['status'];
        $notifyTransferVo->fail_reason = $content['fail_reason'] ?? '';

        $data = $notifyTransferVo->toMap();
        unset($data['sign']);
        unset($data['notify_url']);
        $secret = Brand::where('appid', $order->appid)->value('secret');
        $security = new Security();
        Log::record('加签'.json_encode($data,JSON_UNESCAPED_UNICODE).'secret:'.$secret);
        $notifyTransferVo->sign = $security->makeSign($data, $secret);

        $notifyVo = [
            'num' => 0,
            'value' => $notifyTransferVo->toMap()
        ];
        $message = json_encode($notifyVo);
        $notifyMq = new NotifyMq();
        $notifyMq->payNotify($message);

    }
}