<?php

namespace app\payment\tlpay\lib;


use app\common\context\ContextPay;
use app\common\enum\AccountEnum;
use app\common\enum\OrderEnum;
use app\common\enum\TlEnum;
use app\common\enum\UserAgreementEnum;
use app\common\enum\UserEnum;
use app\common\exception\ApiException;
use app\common\tool\AliOss;
use app\common\tool\SnowFlake;
use app\common\vo\TradePayVo;
use app\model\Order;
use app\model\User;
use app\model\UserAgreement;
use app\validate\DispatchValidate;
use think\facade\Log;

class TlPayApi extends TlPayBase
{

    public $whiteMethod = [
        'createMember',
        'accountBalance'
    ];

    /**
     * TlPayApi constructor.
     * @throws ApiException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function __construct() {
        $param = ContextPay::getRaw();
        if (in_array(ContextPay::getMethod(), $this->whiteMethod)) {
            return true;
        }
        if (($param['user_no'] ?? '')) {
            /** @var User $user */
            $user = User::where('corporate_sn', $param['user_no'])->find();
            if (empty($user)) {
                throw new ApiException('用户不存在!');
            }
            ContextPay::setUser($user);
        }
        return true;
    }

    /**
     * 创建会员
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function createMember() {
        $param   = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $channel = ContextPay::getChannel();
        $data    = [
            'bizUserId'  => $param['user_no'],
            'memberType' => $param['user_type'],
            'source'     => $param['source'] ?? TlEnum::TL_SOURCE_PC   // 1.mobile 2.pc
        ];
        Log::write('通联测试');
        $result = $this->execute('allinpay.yunst.memberService.createMember', $data);
        Log::write('通联返回结果:' . json_encode($result));
        $user                  = new User();
        $user->appid           = $account->appid;
        $user->channel_id      = $channel->id;
        $user->channel_name    = $channel->name;
        $user->company_name    = $account->company_name;
        $user->business_no     = $account->business_no;
        $user->pay_platform    = UserEnum::USER_PAY_PLATFORM_TL;
        $user->pay_type        = UserEnum::USER_PAY_TYPE_PERSON;
        $user->merchant_type   = ($param['user_type'] == TlEnum::TL_TYPE_COMPANY) ? UserEnum::MERCHANT_TYPE_PERSON : UserEnum::MERCHANT_TYPE_COMPANY;
        $user->corporate_name  = $param['user_name'];
        $user->corporate_sn    = $param['user_no'];
        $user->inputtime       = time();
        $user->come_from       = 'PC';
        $user->income_platform = UserEnum::INCOME_PLATFORM;
        $user->status          = UserEnum::USER_STATUS_WAIT;
        $user->save();
        return $result;
    }

    /**
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function searchMember() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId' => $param['user_no']
        ];
        return $this->execute('allinpay.yunst.memberService.getMemberInfo', $data);
    }

    /**
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function union() {

        $unionDto = ContextPay::getUnionPayDto();
        $account  = ContextPay::getAccount();
        // 1.mobile 2.pc 通联枚举
        $client     = $unionDto->client == OrderEnum::CLIENT_WEB ? TlEnum::TL_SOURCE_PC : TlEnum::TL_SOURCE_MOBILE;
        $running_no = SnowFlake::createOnlyId();

        $data = [
            'payerId'      => $unionDto->user_no,
            'recieverId'   => $unionDto->payee_type == OrderEnum::PAY_LL_PERSON ? $unionDto->payee_id : TlEnum::TL_PLATFORM_NO,
            'bizOrderNo'   => $running_no,
            'amount'       => $unionDto->money,
            'fee'          => TlEnum::TL_FEE,
            'backUrl'      => env('PAYMENT.DOMAIN') . '/notify/tl/union/pay',
            'payMethod'    => $this->getPayMethod(),
            'industryCode' => TlEnum::TL_INDUSTRYCODE,
            'industryName' => TlEnum::TL_INDUSTRYNAME,
            'source'       => $client,
        ];

        /** 聚合支付添加跳转地址 */
        if (ContextPay::getMixed() == OrderEnum::PAY_TYPE_UNION) {
            $data['frontUrl'] = $unionDto->return_url;
        }
        $result = $this->execute('allinpay.yunst.orderService.consumeApply', $data);

        /** 聚合支付确认支付 获取支付链接 */
        if (ContextPay::getMixed() == OrderEnum::PAY_TYPE_UNION) {
            $confirmData       = [
                'bizUserId'  => $unionDto->user_no,
                'bizOrderNo' => $running_no,
                'consumerIp' => Request()->ip()
            ];
            $payUrl            = $this->execute('allinpay.yunst.orderService.payBySMS', $confirmData);
            $result['payInfo'] = $payUrl['url'];
        }

        $this->save($result);

        return $this->vo($result);
    }

    /**
     * 快捷支付确认
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function confirmPay() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'        => $param['user_no'],
            'bizOrderNo'       => $param['running_no'],
            'verificationCode' => $param['verify_code'],
            'consumerIp'       => Request()->ip(),
        ];

        return $this->execute('allinpay.yunst.orderService.payByBackSMS', $data);

    }

    /**
     * 聚合支付确认
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function confirmUnionPay() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'  => $param['user_no'],
            'bizOrderNo' => $param['running_no'],
            'consumerIp' => Request()->ip(),
        ];

        return $this->execute('allinpay.yunst.orderService.payBySMS', $data);

    }

    /**
     * 支付方式
     * @return array
     * @throws ApiException
     */
    public function getPayMethod() {

        $unionDto = ContextPay::getUnionPayDto();
        $pay_type = ContextPay::getMixed();
        $account  = ContextPay::getAccount();
        $money    = $unionDto->money;
        $str      = '_ORG';// 集团
        $tag      = env('PAYMENT.tl_env') == 'test' ? false : true;
        switch ($pay_type) {
            // 微信扫码
            case OrderEnum::PAY_TYPE_WX_SCAN:
                $data   = ['amount' => $money, 'limitPay' => ''];
                $method = 'SCAN_WEIXIN';
                break;
            // 微信小程序
            case OrderEnum::PAY_TYPE_WX_SMALL:
                $data   = ['subAppId' => $unionDto->appid, 'amount' => $money, 'limitPay' => '', 'acct' => $unionDto->openid];
                $method = 'WECHATPAY_MINIPROGRAM';
                break;
            // 微信APP支付（原生）
            case OrderEnum::PAY_TYPE_WX_APP:
                $data   = ['subAppId' => $unionDto->appid, 'amount' => $money, 'limitPay' => ''];
                $method = 'WECHATPAY_APP_OPEN';
                break;
            // 支付宝扫码
            case OrderEnum::PAY_TYPE_ALIPAY_SCAN:
                $data   = ['amount' => $money, 'limitPay' => ''];
                $method = 'SCAN_ALIPAY';

                break;
            // 支付宝APP
            case OrderEnum::PAY_TYPE_ALIPAY_APP:
                $data   = ['amount' => $money, 'limitPay' => ''];
                $method = 'ALIPAY_APP_VSP';
                break;
            // 收银宝H5
            case OrderEnum::PAY_TYPE_UNION:
                $data   = ['amount' => $money, 'limitPay' => ''];
                $method = 'H5_CASHIER_VSP';
                break;
            // 银行卡
            case OrderEnum::PAY_TYPE_BANK_CARD:
                $data   = ['amount' => $money, 'bankCardNo' => $this->encryptAES($unionDto->bank_card_no)];
                $method = 'QUICKPAY_VSP';
                break;
            default:
                throw new ApiException('不支持的支付类型!');
                break;

        }
        // 快捷支付不需要增加商户号及集团模式
        if ($tag && $method != 'QUICKPAY_VSP' && $method != 'WECHATPAY_APP_OPEN') {
            $method           = $method . $str;
            $data['vspCusid'] = $account->business_no;
        }
        $payMethod = [
            $method => $data
        ];
        return $payMethod;
    }

    /**
     * 绑定手机号
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function bindPhone() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'        => $param['user_no'],
            'phone'            => $param['phone'],
            'verificationCode' => $param['verify_code'],
        ];
        /** @var User $user */
        $user            = ContextPay::getUser();
        $user->legal_tel = $param['phone'];
        $user->status    = UserEnum::USER_STATUS_PASS;
        $user->save();

        return $this->execute('allinpay.yunst.memberService.bindPhone', $data);
    }

    /**
     * 解绑手机号
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function unBindPhone() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'        => $param['user_no'],
            'phone'            => $param['phone'],
            'verificationCode' => $param['verify_code'],
        ];
        /** @var User $user */
        $user            = ContextPay::getUser();
        $user->legal_tel = '';
        $user->save();
        return $this->execute('allinpay.yunst.memberService.unbindPhone', $data);
    }

    /**
     * 发送短信
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function sendMessage() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'            => $param['user_no'],
            'phone'                => $param['phone'],
            'verificationCodeType' => $param['verify_type'],
        ];
        return $this->execute('allinpay.yunst.memberService.sendVerificationCode', $data);
    }

    /**
     * 实名认证
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function authName() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'    => $param['user_no'],
            'isAuth'       => true,
            'name'         => $param['name'],
            'identityType' => TlEnum::CARD_TYPE_ID,
            'identityNo'   => $this->encryptAES($param['id_card']),
        ];
        /** @var User $user */
        $user                   = ContextPay::getUser();
        $user->business_license = $param['id_card'];
        $user->legal_id         = $param['id_card'];
        $user->legal_name       = $param['name'];
        $user->save();
        return $this->execute('allinpay.yunst.memberService.setRealName', $data);
    }

    /**
     * 实名认证
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function signContract() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId' => $param['user_no'],
            'backUrl'   => env('PAYMENT.DOMAIN') . '/notify/tl/sign/contract',
            'source'    => $param['source'] ?? TlEnum::TL_SOURCE_PC
        ];
        return $this->execute('allinpay.yunst.memberService.signContract', $data);
    }

    /**
     * 请求绑定银行卡
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function requestBindCard() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'    => $param['user_no'],
            'cardNo'       => $this->encryptAES($param['bank_card_no']),
            'phone'        => $param['phone'],
            'name'         => $param['name'],
            'identityType' => TlEnum::CARD_TYPE_ID,
            'identityNo'   => $this->encryptAES($param['id_card_no'])
        ];
        /** @var User $user */
        $user           = ContextPay::getUser();
        $user->basic_sn = $param['bank_card_no'];
        $user->bank_sn  = $param['bank_card_no'];
        $user->bank_tel = $param['phone'];
        $user->save();
        return $this->execute('allinpay.yunst.memberService.applyBindBankCard', $data);
    }

    /**
     * 请求绑定银行卡
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function confirmBindCard() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'        => $param['user_no'],
            'phone'            => $param['phone'],
            'verificationCode' => $param['verify_code'],
            'tranceNum'        => $param['tranceNum']
        ];
        return $this->execute('allinpay.yunst.memberService.bindBankCard', $data);
    }

    /**
     * 查询绑定银行卡
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function searchBindCard() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId' => $param['user_no']
        ];
        return $this->execute('allinpay.yunst.memberService.queryBankCard', $data);
    }

    /**
     * 解除绑定银行卡
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function unBindCard() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId' => $param['user_no'],
            'cardNo'    => $this->encryptAES($param['bank_card_no'])
        ];
        return $this->execute('allinpay.yunst.memberService.unbindBankCard', $data);
    }

    /**
     * 设置企业信息
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function setCompanyInfo() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'        => $param['user_no'],
            'companyBasicInfo' => [
                'companyName'      => $param['companyName'],
                'legalName'        => $param['legalName'],
                'identityType'     => TlEnum::CARD_TYPE_ID,
                'legalIds'         => $this->encryptAES($param['legalIds']),
                'legalPhone'       => $this->encryptAES($param['legalPhone']),
                'accountNo'        => $this->encryptAES($param['accountNo']),
                'parentBankName'   => $param['parentBankName'],
                'bankName'         => $param['bankName'],
                'unionBank'        => $param['unionBank'],
                'businessLicense'  => $param['businessLicense'],
                'organizationCode' => $param['organizationCode'],
                'taxRegister'      => $param['taxRegister']
            ],
            'isAuth'           => true
        ];
        /** @var User $user */
        $user                    = ContextPay::getUser();
        $user->legal_name        = $param['legalName'];
        $user->legal_phone       = $param['legalPhone'];
        $user->legal_id          = $param['legalIds'];
        $user->business_license  = $param['businessLicense'];
        $user->bank_branch_title = $param['bankName'];
        $user->bank_id           = $param['unionBank'];
        $user->taxation_sn       = $param['taxRegister'];
        $user->mechanism_sn      = $param['organizationCode'];
        $user->bank_type         = TlEnum::TL_ACCOUNT_COMPANY;
        $user->linked_brbankno   = $param['accountNo'];
        $user->linked_acctname   = $param['companyName'];
        $user->linked_brbankname = $param['parentBankName'];
        $user->contacts_name     = $param['legalName'];
        $user->contacts_tel      = $param['legalPhone'];
        $user->document_type     = TlEnum::TL_TYPE_COMPANY_CERT_MERCHANT;

        $user->save();
        return $this->execute('allinpay.yunst.memberService.setCompanyInfo', $data);
    }

    /**
     * 上传文件
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function uploadFile() {

        $param = ContextPay::getRaw();
        $data  = [
            'bizUserId'                      => $param['user_no'],
            'picType'                        => $param['picType'],
            'picture'                        => $param['picture'],
            'ocrComparisonResultBackUrl	' => env('PAYMENT.DOMAIN') . '/notify/tl/upload',
        ];

        /** @var User $user */
        $user = ContextPay::getUser();
        switch ($param['picType']) {
            // 营业执照
            case TlEnum::PIC_TYPE_MERCHANT:
                $user->business_license_img = $this->getUrl();
                break;
            // 组织机构代码证
            case TlEnum::PIC_TYPE_MECHANISM_SN:
                $user->mechanism_img = $this->getUrl();
                break;
            // 税务登记证
            case TlEnum::PIC_TYPE_TAXATION_SN:
                $user->taxation_img = $this->getUrl();
                break;
            // 银行开户证明
            case TlEnum::PIC_TYPE_BANK_CERT:
                $user->bank_licence_img = $this->getUrl();
                break;
            // 机构信用代码
            case TlEnum::PIC_TYPE_UNIFIED_CODE:
                $user->unified_code_img = $this->getUrl();
                break;
            // 身份证正面
            case TlEnum::PIC_TYPE_RIGHT_CARD:
                $user->id_just_img = $this->getUrl();
                break;
            // 身份证反面
            case TlEnum::PIC_TYPE_BACK_CARD:
                $user->id_back_img = $this->getUrl();
                break;
            default:
                break;
        }
        $img             = str_replace('data:image/png;base64,', '', $data['picture']);
        $img             = str_replace(' ', '+', $img);
        $data['picture'] = $img;
        $result          = $this->execute('allinpay.yunst.memberService.idcardCollect', $data);
        $user->save();
        return $result;

    }

    /**
     * 保存图片
     * @return string
     * @throws ApiException
     */
    public function getUrl() {
        $param    = ContextPay::getRaw();
        $dir_path = runtime_path() . '/download';
        if (!is_dir($dir_path)) {
            @mkdir($dir_path, 0777, true);
        }
        $file_path = $dir_path . '/' . date('YmdHis') . '.png';
        $img       = str_replace('data:image/png;base64,', '', $param['picture']);
        $img       = str_replace(' ', '+', $img);
        $data      = base64_decode($img);

        file_put_contents($file_path, $data);
        $savePath = 'pay_center/tl/' . date('YmdHis') . '.jpg';
        $aliOss   = new AliOss();
        $flag     = $aliOss->upload($savePath, $file_path);
        if ($flag) {
            @unlink($file_path);
        }
        return $savePath;
    }

    /**
     * 平台转账
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function platformTransfer() {

        $param   = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $data    = [
            'bizTransferNo'      => SnowFlake::createOnlyId(),
            'sourceAccountSetNo' => TlEnum::TL_ACCOUNT,
            'targetBizUserId'    => $param['user_no'],
            'targetAccountSetNo' => TlEnum::TL_ACCOUNT_SET,
            'amount'             => $param['amount'],
        ];
        return $this->execute('allinpay.yunst.orderService.applicationTransfer', $data);

    }

    /**
     * 平台转账
     * @return mixed
     * @param $running_no
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function dispatch($running_no) {

        $param = ContextPay::getRaw();
        validate(DispatchValidate::class)->scene('tlTransfer')->check($param);
        $account_type = ($param['account_type'] == AccountEnum::ACCOUNT_TYPE_PERSON) ? 0 : 1;

        $data   = [
            'bizOrderNo'   => $running_no,
            'bizUserId'    => $param['user_no'],
            'accountSetNo' => TlEnum::TL_ACCOUNT_SET,
            'amount'       => $param['money'],
            'fee'          => TlEnum::TL_FEE,
            'validateType' => 0,
            'backUrl'      => env('PAYMENT.DOMAIN') . '/notify/tl/withdraw',
            'bankCardNo'   => $this->encryptAES($param['identity']),
            'industryCode' => TlEnum::TL_INDUSTRYCODE,
            'industryName' => TlEnum::TL_INDUSTRYNAME,
            'source'       => TlEnum::TL_SOURCE_PC,
            'summary'      => $param['remark'] ?? '',
            'bankCardPro'  => $account_type
        ];
        $result = $this->execute('allinpay.yunst.orderService.withdrawApply', $data);
        return $result;

    }

    /**
     * 账户余额
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \app\common\exception\ApiException
     */
    public function queryBalance() {

        $param   = ContextPay::getRaw();
        $account = ContextPay::getAccount();
        $data    = [
            'bizUserId'    => $param['user_no'],
            'accountSetNo' => TlEnum::TL_ACCOUNT_SET
        ];
        return $this->execute('allinpay.yunst.orderService.queryBalance', $data);

    }

    public function commonQuery() {
        $param = ContextPay::getRaw();
        $data  = [
            'bizOrderNo' => $param['running_no']
        ];
        return $this->execute('allinpay.yunst.orderService.getOrderStatus', $data);
    }

    /**
     * 账户集余额
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function accountBalance() {
        $data = [
            'accountSetNo' => TlEnum::TL_ACCOUNT
        ];
        return $this->execute('allinpay.yunst.merchantService.queryMerchantBalance', $data);
    }

    /**
     * 平台头寸查询
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function accountReserveFundBalance() {
        $data = [
            'accountSetNo' => TlEnum::TL_ACCOUNT
        ];
        return $this->execute('allinpay.yunst.merchantService.queryReserveFundBalance', $data);
    }

    /**
     * 平台头寸调拨 TODO
     * @param  $amount
     * @return array|mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function accountPlatformFundTransfer($amount) {
        $running_no = SnowFlake::createOnlyId();
        $param      = ContextPay::getRaw();
        $data       = [
            'bizOrderNo' => $running_no,
            'amount'     => $amount,
            'backUrl'    => env('PAYMENT.DOMAIN') . '/notify/tl/platform/fund/transfer',
            'payMethod'  => [
                'TRANSFER_BANK' => [
                    'transferOrgType' => TlEnum::TRANSFER_BANK_TRANSFER_ORG_TYPE,
                    'transferAmount'  => $amount
                ]
            ]
        ];
        return $this->execute('allinpay.yunst.merchantService.platformFundTransfer', $data);
    }

    /**
     * 订单退款
     * @param $running_no
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refund($running_no) {
        $param = ContextPay::getRaw();
        $order = ContextPay::getOrder();
        $this->allocation();
        $data   = [
            'bizOrderNo'    => $running_no,
            'oriBizOrderNo' => $order->running_no,
            'bizUserId'     => $order->user_no ?? TlEnum::TL_PLATFORM_NO,
            'amount'        => $param['refund_money'],
            'backUrl'       => env('PAYMENT.DOMAIN') . '/notify/tl/refund',
            'refundType'    => 'D0',
        ];
        $result = $this->execute('allinpay.yunst.orderService.refund', $data);

//        if(($result['payStatus'] ?? '') && $result['payStatus'] = 'fail') {
//            throw new ApiException($result['payFailMessage'] ?? '通联退款失败!');
//        }
        return $result;
    }

    /**
     * 资金调拨
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function allocation() {
        $order   = ContextPay::getOrder();
        $account = ContextPay::getAccount();
        $data    = [
            'bizOrderNo' => SnowFlake::createOnlyId(),
            'payMethod'  => ['TLT_TRANSFER_REFUND_VSP' => ['transferAmount' => $order->money, 'vspCusid' => $account->business_no]],
            'amount'     => $order->money,
            'backUrl'    => env('PAYMENT.DOMAIN') . '/notify/tl/allocation',
        ];
        return $this->execute('allinpay.yunst.orderService.transferTeaRefundFund', $data);
    }

    /**
     * 账户集余额
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function applyAgreement() {
        $param = ContextPay::getRaw();
        /** @var User $user */
        $user                              = ContextPay::getUser();
        $data                              = [
            'bizUserId'    => $user->corporate_sn,
            'signAcctName' => $param['sign_name'],
            'backUrl'      => env('PAYMENT.DOMAIN') . '/notify/tl/agreement',
            'source'       => TlEnum::TL_SOURCE_PC,
        ];
        $data                              = $this->execute('allinpay.yunst.memberService.signAcctProtocol', $data);
        $userAgreement                     = new UserAgreement();
        $userAgreement->user_id            = $user->id;
        $userAgreement->trade_number       = SnowFlake::createOnlyId();
        $userAgreement->channel_running_no = '';
        $userAgreement->sign_url           = $data['url'] ?? '';
        $userAgreement->type               = UserAgreementEnum::TYPE_CLIENT;
        $userAgreement->agreement_type     = UserAgreementEnum::AGREEMENT_TYPE_FREE;
        $userAgreement->created_at         = time();
        $userAgreement->updated_at         = time();
        $userAgreement->save();

        return $data;
    }

    /**
     * @param $result
     * @return Order
     */
    public function save($result): Order {

        $unionPayDto = ContextPay::getUnionPayDto();
        $account     = ContextPay::getAccount();
        $brand       = ContextPay::getBrand();

        $order                     = new Order();
        $order->appid              = $brand->appid;
        $order->order_no           = $unionPayDto->order_no;
        $order->goods_no           = $unionPayDto->goods_no;
        $order->goods_name         = $unionPayDto->goods_name;
        $order->user_no            = $unionPayDto->user_no;
        $order->notify_url         = $unionPayDto->notify_url ?? '';
        $order->client             = $unionPayDto->client ?? '';
        $order->money              = $unionPayDto->money;
        $order->account_id         = $account->id;
        $order->channel_id         = $account->channel_id;
        $order->channel_name       = $account->channel_name;
        $order->brand_id           = $brand->brand_id;
        $order->brand_name         = $brand->name;
        $order->source             = OrderEnum::SOURCE_ENTRY;
        $order->running_no         = $result['bizOrderNo'];
        $order->order_type         = $unionPayDto->order_type ?? 1; //默认订单支付
        $order->third_appid        = $unionPayDto->appid ?? ''; //默认订单支付
        $order->return_url         = $unionPayDto->return_url ?? ''; //支付成功跳转地址
        $order->pay_type           = ContextPay::getMixed() ?? ''; //支付类型
        $order->payee_type         = $unionPayDto->payee_type ?? ''; //收款类型
        $order->payee_id           = $unionPayDto->payee_id ?? ''; //收款用户ID
        $order->remark             = $unionPayDto->explanation ?? ''; //订单描述
        $order->channel_running_no = $result['orderNo'] ?? ''; //通联订单号
        $order->founder_name       = $unionPayDto->founder_name ?? ""; //操作人
        $order->founder_no         = $unionPayDto->founder_no ?? ""; //操作人工号


        $order->save();
        return $order;
    }

    /**
     * @param array $result
     * @return object
     */
    public function vo(array $result) {

        $brand = ContextPay::getBrand();

        $tradePayVo             = new TradePayVo();
        $tradePayVo->appid      = $brand->appid;
        $tradePayVo->paytype    = ContextPay::getMixed();
        $tradePayVo->nonce_str  = getRandomStr(32);
        $tradePayVo->running_no = $result['bizOrderNo'];
        $tradePayVo->code_data  = $result['payInfo'] ?? '';

        return $tradePayVo;
    }
    /**
     * 收银宝资金查询
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function VSPFBalance() {
        $account = ContextPay::getAccount();
        $data    = [
            'vspCusid' => $account->business_no,
            'vspOrgid' => TlEnum::TL_VSP_ORGID
        ];
        return $this->execute('allinpay.yunst.merchantService.queryVSPFund', $data);
    }

    /**
     * 银行存管账户余额查询
     * @return mixed
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
//    public function QueryBankBalance() {
//        $account = ContextPay::getAccount();
//        $data    = [
//            'acctOrgType' => 17,
//            'acctNo'      => TlEnum::TL_VSP_ORGID,
//            'acctName'    => TlEnum::TL_VSP_ORGID
//        ];
//        return $this->execute('allinpay.yunst.merchantService.queryBankBalance', $data);
//    }
}