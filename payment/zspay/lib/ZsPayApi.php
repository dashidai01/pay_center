<?php

namespace app\payment\zspay\lib;


use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\exception\ApiException;
use app\common\mq\QueryBillMq;
use app\common\tool\SnowFlake;
use app\model\Account;
use app\model\Order;
use app\model\OrderDetail;
use app\validate\DispatchValidate;
use Exception;
use FG\ASN1\ASNObject;
use GuzzleHttp\Client;
use Rtgm\sm\RtSm2;
use Rtgm\sm\RtSm4;
use think\facade\Env;
use think\facade\Log;

class ZsPayApi
{

    /**
     * 批量流水号
     * @var
     */
    public $batch_order;

    /**
     * 业务参考号
     * @var
     */
    public $yurref;
    /**
     * 批量流水号
     * @var
     */
    public $batch_running_no;

    public $url;
    /**
     * 退款流水号
     * @var
     */
    public $refund_running_no;
    public function __construct() {
        $this->batch_running_no = SnowFlake::createOnlyId();
        $this->refund_running_no = SnowFlake::createOnlyId();
        $account = ContextPay::getAccount();
        if($account) {
            $this->url = ($account->is_guomi == AccountEnum::ENCRYPTION_IS_GUOMI ? Env::get('PAYMENT.zs_number_url') : Env::get('PAYMENT.zs_url'));

        }
    }

    /**
     * @param $running_no
     * @return \Psr\Http\Message\ResponseInterface
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch($running_no) {
        try {
            $url = $this->url;
            $option = [
                'form_params' => $this->getTransferOption($running_no),
                'http_errors' => false
            ];


            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body);
            // 发送消息至查询账单队列
            $mq = new QueryBillMq();
            $mq->dispatchPayNotify1min(json_encode(['num' => 0, 'running_no' => $running_no]));
            return $data['bb1payopz1'][0];
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * 多维数据按key排序
     * @param $array
     */
    public function array_sort(&$array) {
        ksort($array);
        foreach (array_keys($array) as $k) {
            if (gettype($array[$k]) == "array") {
                $this->array_sort($array[$k]);
            } else {
                $array[$k] = trim($array[$k]);
            }
        }
    }

    /**
     * 转账参数
     * @param $running_no
     * @return array
     * @throws ApiException
     */
    public function getTransferOption($running_no) {

        $dispatchDto = ContextPay::getDispatchDto();
        $account     = ContextPay::getAccount();

        $param['head'] = [
            'funcode' => 'BB1PAYOP',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $money = (string)($dispatchDto->money / 100);
        $money = sprintf('%.2f', $money);
        validate(DispatchValidate::class)->scene('zsTransfer')->check(json_decode(json_encode($dispatchDto), true));

        // 他行对公户传超额行号
        if ($dispatchDto->account_type == AccountEnum::ACCOUNT_TYPE_COMPANY && $dispatchDto->is_zs_card == 'N') {
            if (!$dispatchDto->bank_code) {
                throw new ApiException('他行对公户必传超额行号!');
            }
        }
        // 他行且快速通道需提供开户行信息
        if ($dispatchDto->account_type == AccountEnum::ACCOUNT_TYPE_COMPANY && $this->getArrivalType() == 'Q') {
            if (!$dispatchDto->inst_province) {
                throw new ApiException('开户行所在省必填!');
            }
            if (!$dispatchDto->inst_city) {
                throw new ApiException('开户行所在市必填!');
            }
            if (!$dispatchDto->inst_branch_name) {
                throw new ApiException('开户行所属支行必填!');
            }
        }
        $param['body']   = [
            // 企业信息
            'bb1paybmx1' => [
                [
                    "busCod" => 'N02030',                                              // 业务类型
                    "busMod" => $dispatchDto->business_scene_code,                     // 业务模式
                ]
            ],
            // 支付明细
            'bb1payopx1' => [
                [
                    'ccyNbr' => '10',                             // 币种
                    'crtAcc' => $dispatchDto->identity,           // 收方账号
                    'crtNam' => $dispatchDto->name,               // 收方户名
                    'dbtAcc' => $dispatchDto->out_identity,       // 转出账号
                    'nusAge' => $dispatchDto->order_title,        // 用途
                    'trsAmt' => $money,                           // 金额
                    'yurRef' => $running_no,                      // 业务参考号
                    "bnkFlg" => $dispatchDto->is_zs_card,         // 收方类型
                    "stlChn" => $this->getArrivalType(),          // G普通、Q快速、R实时
                    "busNar" => $dispatchDto->remark,             // 业务摘要
                    "brdNbr" => $dispatchDto->bank_code ?? '',    // 银行编码
                    "crtBnk" => $dispatchDto->inst_name ?? '',    // 收方开户行名称
                    "crtAdr" => $dispatchDto->inst_province ?? '' . $dispatchDto->inst_city ?? '' . $dispatchDto->inst_branch_name ?? '',    // 收方开户行地址
                ]
            ]
        ];
        $data['request'] = $param;

        return $this->postData($data,'BB1PAYOP');
    }

    /**
     * 结算通道 Q快速 R实时
     * @return string
     * @param  array
     */
    public function getArrivalType($value = []) {
        if ($value) {
            $money = $value['money'] ?? 0;
        } else {
            $dispatchDto = ContextPay::getDispatchDto();
            $money       = $dispatchDto->money;
        }
        if ($money > 100000000) {
            return 'Q';
        }
        return 'R';
    }

    /**
     * @param $data
     * @return mixed
     */
    public function rsa($data) {

        $account = ContextPay::getAccount();
        $private = $account->business_private_rsa;
        $str     = json_encode($data, JSON_UNESCAPED_UNICODE);

        if (!strstr($private, '-----BEGIN RSA PRIVATE KEY-----')) {
            $private = "-----BEGIN RSA PRIVATE KEY-----\n" .
                $private .
                "\n-----END RSA PRIVATE KEY-----";
        }

        $encrtp = '';
        openssl_sign($str, $encrtp, $private, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($encrtp);

        $data = str_replace('__signature_sigdat__', $sign, $str);
        return $data;
    }

    /**
     * aes解密
     * @param $data
     * @return string
     */
    public function aes_decrypt($data) {
        $account = ContextPay::getAccount();
        return openssl_decrypt($data, 'aes-256-ecb', $account->aes_secret, 0);
    }

    /**
     * aes加密
     * @param $data
     * @return string
     */
    public function aes_encrypt($data) {
        $account = ContextPay::getAccount();
        return openssl_encrypt($data, 'aes-256-ecb', $account->aes_secret, 0);
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getZsBusiness() {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getBusinessOption(),
                'http_errors' => false
            ];


            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body);
            return $data['ntqmdlstz'];
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getBusinessOption() {

        $account = ContextPay::getAccount();

        $param['head'] = [
            'funcode' => 'DCLISMOD',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $param['body'] = [
            'buscod' => 'N02030'
        ];

        $data['request'] = $param;

        return $this->postData($data,'DCLISMOD');
    }

    /**
     * @param $data
     * @param string $account
     * @param string $code
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function postData($data, $code = '', $account = '') {
        $account = empty($account) ? ContextPay::getAccount() : $account;;


        if ($account->is_guomi == AccountEnum::ENCRYPTION_IS_GUOMI) {
            return $this->postDataGuoMi($data, $account, $code);
        }
        if ($account->is_guomi == AccountEnum::ENCRYPTION_IS_NOT_GUOMI) {
            return $this->postDataNormal($data, $code, $account);
        }
        throw new ApiException('不支持的加密方式!');
    }

    /**
     * 普通加密
     * @param $data
     * @param $account
     * @param $code
     * @return array
     */
    public function postDataNormal($data, $code, Account $account) {

        $data['signature']['sigtim'] = date('YmdHis');
        $data['signature']['sigdat'] = '__signature_sigdat__';

        $this->array_sort($data);
        Log::info('转账加密前数据:' . json_encode($data));
        $data = $this->rsa($data);
        $data = $this->aes_encrypt($data);

        $uid = $account->appid;

        $postData = ['UID' => $uid, 'DATA' => $data];

        return $postData;
    }

    /**
     * 国密
     * @param $data
     * @param $account
     * @param $code
     * @return array
     * @throws ApiException
     */
    public function postDataGuoMi($data, $account, $code) {

        $data['signature']['sigtim'] = date('YmdHis');
        $data['signature']['sigdat'] = '__signature_sigdat__';

        $this->array_sort($data);
        $sm2        = new RtSm2("base64");
        $userId     = sprintf('%-016s', $account->appid);
        $privateKey = base64_decode($account->business_private_rsa);
        $privateKey = unpack("H*", $privateKey)[1];

        $sign   = $sm2->doSign(json_encode($data, JSON_UNESCAPED_UNICODE), $privateKey, $userId);
        $sign   = base64_decode($sign);
        $point  = ASNObject::fromBinary($sign)->getChildren();
        $pointX = $this->formatHex($point[0]->getContent());
        $pointY = $this->formatHex($point[1]->getContent());
        $sign   = $pointX . $pointY;
        $sign   = base64_encode(hex2bin($sign));

        $data = str_replace('__signature_sigdat__', $sign, json_encode($data, JSON_UNESCAPED_UNICODE));

        Log::write('转账加密前数据:' . $data);

        $sm4      = new RtSm4($account->aes_secret);
        $data     = $sm4->encrypt($data, 'sm4-cbc', $iv = $userId, "base64");
        $uid      = $account->appid;
        $postData = ['UID' => $uid, 'FUNCODE' => $code, 'ALG' => 'SM', 'DATA' => $data];

        ksort($postData);
        return $postData;
    }

    public function formatHex($dec) {

        $hex = gmp_strval(gmp_init($dec, 10), 16);
        $len = strlen($hex);
        if ($len == 64) {
            return $hex;
        }
        if ($len < 64) {
            $hex = str_pad($hex, 64, "0", STR_PAD_LEFT);
        } else {
            $hex = substr($hex, $len - 64, 64);
        }

        return $hex;
    }


    /**
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery() {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->queryOption(),
                'http_errors' => false
            ];


            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();
            $data   = $this->verifyResponse($body);

            return $data['bb1payqrz1'][0] ?? [];

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function queryOption() {

        $account       = ContextPay::getAccount();
        $order         = ContextPay::getOrder();
        $param['head'] = [
            'funcode' => 'BB1PAYQR',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $param['body'] = [
            'bb1payqrx1' => [
                [
                    'busCod' => 'N02030',
                    'yurRef' => $order->running_no
                ]
            ]
        ];

        $data['request'] = $param;

        return $this->postData($data,'BB1PAYQR');
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getZsBankCode() {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getBankCodeOption(),
                'http_errors' => false
            ];


            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            Log::record('招商转账返回加密数据' . $body);
            $data = $this->aes_decrypt($body);

            if (!$data) {
                throw new ApiException($body);
            }

            Log::record('招商转账返回解密数据' . $body);
            $data = json_decode($data, true);

            if (!$data) {
                throw new ApiException('获取业务编码失败!');
            }
            $body = $data['response']['body'] ?? '';
            if (empty($body)) {
                throw new ApiException('获取业务编码失败!');
            }

            echo '<pre>';
            print_r($data);
            echo '<pre>';
            die;
            return $data['response']['body']['ntqmdlstz'];
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    public function getBankCodeOption() {

        $account = ContextPay::getAccount();

        $param['head'] = [
            'funcode' => 'NTACCBBK',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $param['body'] = [
            'fctval' => ''
        ];

        $data['request'] = $param;

        return $this->postData($data,'NTACCBBK');
    }

    /**
     * 申请电子回单
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function applyReceipt() {

        $params  = ContextPay::getRaw();
        $account = ContextPay::getAccount();

        validate(DispatchValidate::class)->scene('zsApplyReceipt')->check($params);
        $param['head'] = [
            'funcode' => 'ASYCALHD',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $param['body'] = [
            'printMode' => 'pdf',
            'eacnbr'    => $params['out_identity'],
            'begdat'    => $params['start_date'],
            'enddat'    => $params['end_date'],
            'rrcflg'    => '1'
        ];

        $data['request'] = $param;

        $postData = $this->postData($data,'ASYCALHD');
        $url      = $this->url;
        $option   = [
            'form_params' => $postData,
            'http_errors' => false
        ];


        $client = new Client();
        $result = $client->post($url, $option);
        $body   = $result->getBody()->getContents();

        $data = $this->verifyResponse($body);

        $task_id = $data['asycalhdz1']['rtndat'] ?? '';

        return ['file_id' => $task_id];
    }

    /**
     * 查看电子回单
     * @return mixed|\Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchReceipt() {
        $account       = ContextPay::getAccount();
        $params        = ContextPay::getRaw();
        $param['head'] = [
            'funcode' => 'DCTASKID',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];

        $param['body'] = [
            'taskid' => $params['file_id'],
            'qwenab' => 'true'
        ];

        $data['request'] = $param;

        $postData = $this->postData($data,'DCTASKID');
        $url      = $this->url;
        $option   = [
            'form_params' => $postData,
            'http_errors' => false
        ];


        $client = new Client();
        $result = $client->post($url, $option);
        $body   = $result->getBody()->getContents();

        $data = $this->verifyResponse($body);

        return ['download_url' => ($data['fileurl'] ?? '')];
    }

    /**
     * @param $result
     * @param string $account
     * @return array
     * @throws ApiException
     */
    public function verifyResponse($result, $account = '') {

        $account = empty($account) ? ContextPay::getAccount() : $account;
        if ($account->is_guomi == AccountEnum::ENCRYPTION_IS_GUOMI) {
            return $this->verifyGuoMiResponse($result, $account);
        }
        if ($account->is_guomi == AccountEnum::ENCRYPTION_IS_NOT_GUOMI) {
            return $this->verifyNormalResponse($result);
        }
        throw new ApiException('不支持的解密方式!');
    }

    /**
     * @param $result
     * @return array
     * @throws ApiException
     */
    public function verifyNormalResponse($result) {
        // Log::write('招商转账返回加密数据' . $result);
        $data = $this->aes_decrypt($result);

        if (!$data) {
            throw new ApiException('招商解密失败!');
        }

        Log::write('招商转账返回解密数据' . $data);
        $data = json_decode($data, true);

        // 判断请求是否成功
        $resultCode = $data['response']['head']['resultcode'] ?? '';

        if (!$resultCode || $resultCode != 'SUC0000') {
            throw new ApiException('招商转账请求失败!' . $data['response']['head']['resultmsg'] ?? '');
        }

        return $data['response']['body'] ?? [];
    }

    /**
     * @param $result
     * @param Account $account
     * @return array
     * @throws ApiException
     */
    public function verifyGuoMiResponse($result, $account) {
        $sm4  = new RtSm4($account->aes_secret);
        $data = $sm4->decrypt($result, 'sm4-cbc', $iv = sprintf('%-016s', $account->appid), "base64");
        if (!$data) {
            throw new ApiException('招商解密失败!');
        }

        Log::record('招商转账返回解密数据' . $data);
        $data = json_decode($data, true);

        // 判断请求是否成功
        $resultCode = $data['response']['head']['resultcode'] ?? '';

        if (!$resultCode || $resultCode != 'SUC0000') {
            throw new ApiException('招商转账请求失败!' . $data['response']['head']['resultmsg'] ?? '');
        }

        return $data['response']['body'] ?? [];
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function batchDispatchOption() {
        $params  = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        validate(DispatchValidate::class)->scene('batchTransfer')->check($params);

        $param['head'] = [
            'funcode' => 'BB1PAYBH',
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . SnowFlake::createOnlyId()
        ];
        $count         = count($params['list']);
        if (empty($params['list']) || $count < 2) {
            throw new ApiException('转账笔数不能低于2笔!');
        }
        $bb1payopx1 = [];

        foreach ($params['list'] as $key => &$value) {
            $money               = (string)($value['money'] / 100);
            $money               = sprintf('%.2f', $money);
            $value['running_no'] = SnowFlake::createOnlyId();

            validate(DispatchValidate::class)->scene('zsBatchTransfer')->check($value);
            // 他行对公户传超额行号
            if ($value['account_type'] == AccountEnum::ACCOUNT_TYPE_COMPANY && $value['is_zs_card'] == 'N') {
                $bank_code = $value['bank_code'] ?? '';
                if (empty($bank_code)) {
                    throw new ApiException('他行对公户必传超额行号!');
                }
            }
            // 他行且快速通道需提供开户行信息
            if ($value['account_type'] == AccountEnum::ACCOUNT_TYPE_COMPANY && $this->getArrivalType($value) == 'Q') {
                if (!($value['inst_province'] ?? '')) {
                    throw new ApiException('开户行所在省必填!');
                }
                if (!($value['inst_city'] ?? '')) {
                    throw new ApiException('开户行所在市必填!');
                }
                if (!($value['inst_branch_name'] ?? '')) {
                    throw new ApiException('开户行所属支行必填!');
                }
            }
            $bb1payopx1[$key] =
                [
                    'ccyNbr' => '10',                               // 币种
                    'crtAcc' => $value['identity'],                 // 收方账号
                    'crtNam' => $value['name'],                     // 收方户名
                    'dbtAcc' => $value['out_identity'],             // 转出账号
                    'nusAge' => $value['order_title'],              // 用途
                    'trsAmt' => $money,                             // 金额
                    'yurRef' => $value['running_no'],          // 业务参考号
                    "bnkFlg" => $value['is_zs_card'],         // 收方类型
                    "stlChn" => $this->getArrivalType($value),      // G普通、Q快速、R实时
                    "busNar" => $value['remark'],                   // 业务摘要
                    "brdNbr" => $value['bank_code'] ?? '',          // 银行编码
                    "crtBnk" => $value['inst_name'] ?? '',          // 收方开户行名称
                    "crtAdr" => $value['inst_province'] ?? '' . $value['inst_city'] ?? '' . $value['inst_branch_name'] ?? '',    // 收方开户行地址
                ];
        }

        $this->batch_order = $params['list'];
        $param['body']     = [
            // 企业信息
            'bb1bmdbhx1' => [
                [
                    "busCod" => 'N02030',                                              // 业务类型
                    "busMod" => $params['business_scene_code'],                        // 业务模式
                    "bthNbr" => $this->batch_running_no,                             // 批次编号
                    "dtlNbr" => $count,                                                      // 总笔数
                    "ctnFlg" => 'N'                                                     // 总笔数
                ]
            ],
            // 支付明细
            'bb1paybhx1' => $bb1payopx1
        ];
        $data['request']   = $param;


        return $this->postData($data,'BB1PAYBH');
    }

    /**
     * @return \stdClass
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function batchDispatch() {
        try {
            $url = $this->url;
            $option = [
                'form_params' => $this->batchDispatchOption(),
                'http_errors' => false
            ];


            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();
            $data = $this->verifyResponse($body);

            // 发送消息至查询账单队列
            $result          = $data['bb1paybhz1'][0];
            $channel_running_no = $data['response']['head']['rspid'];
            $result['rspid'] = $channel_running_no;
            $result['code']  = ($result['reqSts'] ?? '') . ($result['rtnFlg'] ?? '');

            $order = $this->save($result);
            // 推送消息队列
            array_map(function ($d) {
                $mq = new QueryBillMq();
                $mq->dispatchPayNotify1min(json_encode(['num' => 0, 'running_no' => $d['running_no']]));
            }, $this->batch_order);
            return $this->vo($result);
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @param $result
     * @return mixed
     * @throws Exception
     */
    public function save($result) {
        $params = ContextPay::getRaw();
        $list   = $this->batch_order;

        $account = ContextPay::getAccount();
        $brand   = ContextPay::getBrand();
        foreach ($list as $key => $value) {
            $order                     = new Order();
            $orderDetail               = new OrderDetail();
            $order->appid              = $brand->appid;
            $order->order_no           = $value['order_sn'];
            $order->user_no            = $value['user_no'];
            $order->notify_url         = $params['notify_url'] ?? '';
            $order->money              = $value['money'];
            $order->account_id         = $account->id;
            $order->channel_id         = $account->channel_id;
            $order->channel_name       = $account->channel_name;
            $order->brand_id           = $brand->brand_id;
            $order->brand_name         = $brand->name;
            $order->source             = OrderEnum::SOURCE_OUT;
            $order->running_no         = $value['running_no'];
            $order->status             = OrderEnum::STATUS_DEALING;  // 默认支付中
            $order->remark             = $value['remark'];
            $order->channel_running_no = $result['rspid'] ?? '';
            $order->transfer_type      = ContextPay::getMixed() ?? '';
            $order->founder_name       = $params['founder_name'] ?? '';
            $order->founder_no         = $params['founder_no'] ?? '';
            $order->batch_running_no   = $this->batch_running_no;
            $order->code               = $result['code'] ?? '';
            $order->save();

            $orderDetail->order_id       = $order->id;
            $orderDetail->real_name      = $params['list'][$key]['name'] ?? '';            // 真实姓名
            $orderDetail->receive_number = $params['list'][$key]['identity'] ?? '';        // 收款标识
            $orderDetail->receive_bank   = $params['list'][$key]['inst_name'] ?? '';       // 收款标识
            $orderDetail->phone          = $params['list'][$key]['phone'] ?? '';           // 电话号码
            $orderDetail->id_card_number = $params['list'][$key]['id_card_number'] ?? '';  // 身份证号码
            $orderDetail->save();
        }
        return true;
    }

    /**
     * @param array $result
     * @return \stdClass
     */
    public function vo(array $result) {
        $brand      = ContextPay::getBrand();
        $channel    = ContextPay::getChannel();
        $tradePayVo = new \stdClass();

        $tradePayVo->appid              = $brand->appid;
        $tradePayVo->pay_type           = $channel->aligns;
        $tradePayVo->nonce_str          = getRandomStr(32);
        $tradePayVo->batch_running_no   = $this->batch_running_no;
        $tradePayVo->channel_running_no = $result['rspid'] ?? '';

        return $tradePayVo;
    }

    /**
     * 创建子单元参数
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getCreateNumberOption() {
        $account       = ContextPay::getAccount();
        $params        = ContextPay::getRaw();
        $code          = 'NTDUMADD';
        $this->yurref  = SnowFlake::createOnlyId();
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body'] = [
            'ntbusmody'  => [
                [
                    'busmod' => Env::get('PAYMENT.zs_mod')                  // 业务模式编号
                ]
            ],
            'ntdumaddx1' => [
                [
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),               // 分行号
                    'inbacc' => $account->business_no,     // 活期结算账户
                    'dyanbr' => $params['exclusiveNumber'],     // 记账子单元编号
                    'dyanam' => $params['exclusiveName'],     // 记账子单元记账子单元
                    'eftdat' => date('Ymd'),     // 生效日期
                    'yurref' => $this->yurref,     // 业务参考号
                    'ovrctl' => 'N'     // 是否允许透支
                ]
            ]
        ];

        $data['request'] = $param;

        return $this->postData($data, $code, $account);
    }
    /**
     * 关闭记账子单元
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getCloseNumberOption() {
        $account       = ContextPay::getAccount();
        $params        = ContextPay::getRaw();
        $code          = 'NTDUMDLT';
        $this->yurref  = SnowFlake::createOnlyId();
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body'] = [
            'ntbusmody'  => [
                [
                    'busmod' => Env::get('PAYMENT.zs_mod')                  // 业务模式编号
                ]
            ],
            'ntdumdltx1' => [
                [
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),               // 分行号
                    'inbacc' => $account->business_no     // 活期结算账户
                ]
            ],
            'ntdumdltx2' => [
                [
                    "dyanbr"=> $params['exclusiveNumber'],
                    "yurref"=> $this->yurref
                ]
            ]
        ];

        $data['request'] = $param;

        return $this->postData($data, $code, $account);
    }
    /**
     * 查询历史七天子单元流水
     * @param string $account
     * @param string $ctnkey
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getQueryExclusiveOption($account = '',$ctnkey = '') {
        $account       = !empty($account) ? $account : ContextPay::getAccount();
        $params = ContextPay::getRaw();
        $code          = 'NTDMTQRY';
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body'] = [
            'ntdmtqryy1' => [
                [
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),               // 分行号
                    'inbacc' => $account->business_no,     // 活期结算账户
                    'dyanbr' => $params['exclusiveNumber'] ?? '',     // 记账子单元编号
                    'begdat' => date('Ymd', strtotime('-8 days')),     // 记账子单元编号
                    'enddat' => date('Ymd', strtotime('-1 days')),     // 记账子单元编号
                    'ctnkey' => $ctnkey
                ]
            ]
        ];

        $data['request'] = $param;
        return $this->postData($data, $code, $account);
    }

    /**
     * 查询记账子单元流水
     * @param string $account
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getQueryCurrentExclusiveOption($account = '',$ctnkey='') {

        $account       = !empty($account) ? $account : ContextPay::getAccount();
        $params = ContextPay::getRaw();
        $code          = 'NTDMTQRD';
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body'] = [
            'ntdmtqrdy1' => [
                [
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),               // 分行号
                    'inbacc' => $account->business_no,     // 活期结算账户
                    'dyanbr' => $params['exclusiveNumber'] ?? '',     // 记账子单元编号
                    'ctnkey' => $ctnkey,     // 续传字段
                ]
            ]
        ];

        $data['request'] = $param;

        return $this->postData($data, $code, $account);
    }

    /**
     * 记账子单元内部转账
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     */
    public function getTransferExclusiveOption() {
        $account       = ContextPay::getAccount();
        $params        = ContextPay::getRaw();
        $code          = 'NTDMITRX';
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body']   = [
            'ntbusmody'  => [
                'busmod' => Env::get('PAYMENT.zs_mod')
            ],
            'ntdmitrxx1' => [
                [
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),                           // 分行号
                    'accnbr' => $account->business_no,             // 账号
                    'dmadbt' => $params['receipt_number'],     // 借方记账子单元编号
                    'dmacrt' => $params['pay_number'],      // 贷方记账子单元编号
                    'trsamt' => $params['money'],               // 转账金额
                    'trstxt' => $params['remark'] ?? '内部转账',  // 交易摘要
                    'yurref' => SnowFlake::createOnlyId()       // 交易摘要
                ]
            ]
        ];
        $data['request'] = $param;

        return $this->postData($data, $code, $account);
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRefundExclusiveOption($running_no) {
        $account = ContextPay::getAccount();
        $order   = ContextPay::getOrder();
        $params   = ContextPay::getRaw();
        /** @var OrderDetail $order_detail */
        $order_detail  = OrderDetail::where('order_id', $order->id)->find();
        $code          = 'NTOPRDMR';
        $money         = (string)($params['refund_money'] / 100);
        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];
        $param['body'] = [
            'ntbusmody'  => [
                'busmod' => Env::get('PAYMENT.zs_mod')
            ],
            'ntoprdmrx1' => [
                [
                    'setnbr' => $order->channel_running_no,     // 原交易套号
                    'trxnbr' => $order->running_no,     // 原交易流水号
                    'trsamt' => sprintf('%.2f', $money),     // 金额
                    'bbknbr' => Env::get('PAYMENT.zs_bank_code'),     // 分行号
                    'accnbr' => $account->business_no,      // 主账号
                    'dumnbr' => $order->user_no,  // 子单元编号
                    'eptdat' => date('Ymd'),      // 退款日期
                    'rpyacc' => $order_detail->receive_number,     // 原付方账号
                    'rpynam' => $order_detail->real_name,     // 原付方名称
                    'intflg' => 'N',     // 是否退息
                    'intamt' => 0,        // 利息
                    'yurref' => $running_no        // 业务参考号
                ]
            ]
        ];

        $data['request'] = $param;
        return $this->postData($data, $code, $account);
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \FG\ASN1\Exception\ParserException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getComplexQueryOption() {
        $account = ContextPay::getAccount();
        $params = ContextPay::getRaw();
        $code          = 'NTDUMRED';

        $param['head'] = [
            'funcode' => $code,
            'userid'  => $account->appid,
            'reqid'   => date('YmdHis') . '0000001'
        ];

        $param['body'] = [
            'ntdumredx1' => [
                [
                    'yurref' => $params['yurref'],     // 业务参考号
                    'bgndat' => date('Ymd',$params['start_date']),     // 开始日期
                    'enddat' => date('Ymd',$params['end_date']),     // 结束日期
                ]
            ]
        ];

        $data['request'] = $param;
        return $this->postData($data, $code, $account);
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createNumber() {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getCreateNumberOption()
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();


            $data = $this->verifyResponse($body);

            $data   = $data['ntdumaddz1'][0] ?? [];
            $number = ['number' => $data['dyanbr'], 'account_no' => $data['inbacc'], 'yurref' => $this->yurref];
            return $number;

        } catch (Exception $e) {
            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }

    /**
     * 获取7天历史交易
     * @param  $account
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function queryExclusive($account = '',$ctnkey='') {
        static $responseData = [];
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getQueryExclusiveOption($account,$ctnkey)
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body, $account);
            //$response = $data['ntdmtqryz1'] ?? [];
            $responseData = array_merge($data['ntdmtqryz1'] ?? [],$responseData);
            $ctnkey = $data['ntdmtqryy1'][0]['ctnkey'] ?? '';
            if($ctnkey) {
                $this->queryExclusive($account,$ctnkey);
            }
            return $responseData;

        } catch (Exception $e) {
            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function transferExclusive() {
        try {
            $url = $this->url;

            $option = [
                'form_params' => $this->getTransferExclusiveOption()
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body);
            $data = $data['ntoprrtnz'][0] ?? [];

            return $data;

        } catch (Exception $e) {
            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }

    /**
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refundExclusive($running_no) {
        try {

            $url    = $this->url;
            $option = [
                'form_params' => $this->getRefundExclusiveOption($running_no)
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body);
            $data = $data['ntoprrtnz'][0] ?? [];

            return $data;

        } catch (Exception $e) {
            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }

    /**
     * @param  $account
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function queryCurrentExclusive($account = '',$ctnkey='') {
        static $responseData = [];
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getQueryCurrentExclusiveOption($account,$ctnkey)
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body, $account);
            $responseData = array_merge($data['ntdmtqryz1'] ?? [],$responseData);
            $ctnkey = $data['ntdmtqryy1'][0]['ctnkey'] ?? '';
            if($ctnkey) {
                $this->queryCurrentExclusive($account,$ctnkey);
            }

            // $this->saveRefund($data);
            return $responseData;

        } catch (Exception $e) {

            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }
    /**
     * @param  $account
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function complexQuery($account = '') {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getComplexQueryOption($account)
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();

            $data = $this->verifyResponse($body, $account);

            $data = $data['ntdmaqryz1'] ?? [];

            return $data;

        } catch (Exception $e) {

            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }
    /**
     * 关闭记账子单元
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function closeExclusiveNumber() {
        try {
            $url    = $this->url;
            $option = [
                'form_params' => $this->getCloseNumberOption()
            ];

            $client = new Client();
            $result = $client->post($url, $option);
            $body   = $result->getBody()->getContents();


            $data = $this->verifyResponse($body);

            $data   = $data['ntdumdltz1'][0] ?? [];
            $number = ['number' => $data['dyanbr'], 'account_no' => $data['inbacc'], 'yurref' => $this->yurref];
            return $number;

        } catch (Exception $e) {
            $content = mb_convert_encoding($e->getMessage(), "UTF-8", "auto");
            throw new ApiException($content);
        }
    }

    /**
     * @param $result
     * @return Order
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function saveRefund($result): Order {

        $originOrder = ContextPay::getOrder();
        /** @var OrderDetail $order_detail */
        $order_detail               = OrderDetail::where('order_id', $originOrder->id)->find();
        $param                      = ContextPay::getRaw();
        $order                      = new Order();
        $order->appid               = $originOrder->appid;
        $order->order_no            = '';
        $order->goods_name          = '招商专属号退款';
        $order->user_no             = $originOrder->user_no;
        $order->client              = OrderEnum::CLIENT_WEB;
        $order->notify_url          = $param['notify_url'];
        $order->money               = $param['refund_money'];
        $order->account_id          = $originOrder->id;
        $order->channel_id          = $originOrder->channel_id;
        $order->channel_name        = $originOrder->channel_name;
        $order->brand_id            = $originOrder->brand_id;
        $order->brand_name          = $originOrder->brand_name;
        $order->running_no          = $result['sqrnbr'] ?? '';   // 流水号
        $order->channel_running_no  = $result['trxset'] ?? '';   // 套号
        $order->relation_running_no = $originOrder->running_no;    // 原订单流水号
        $order->order_type          = OrderEnum::ORDER_TYPE_PAY; // 默认订单支付
        $order->status              = OrderEnum::STATUS_PAIED; // 默认支付中
        $order->source              = OrderEnum::SOURCE_OUT;
        $order->remark              = $param['explanation'] ?? '';    // 备注
        $order->trade_time          = time();    // 交易时间

        /** @var Order $order */
        $order->save();

        $orderDetail                 = new OrderDetail();
        $orderDetail->order_id       = $order->id;
        $orderDetail->real_name      = $order_detail->real_name;            // 真实姓名
        $orderDetail->receive_number = $order_detail->receive_number;        // 收款标识
        $orderDetail->bank_address   = $order_detail->bank_address;        // 收付方地址
        $orderDetail->bank_code      = $order_detail->bank_code;        // 行号
        $orderDetail->save();
        return $order;
    }


}