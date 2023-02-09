<?php
namespace app\payment\llpay;


use app\common\exception\ApiException;
use app\payment\llpay\lib\LlDispatchApi;
use app\payment\llpay\lib\LlPayApi;
use app\payment\llpay\lib\LlRefundApi;
use app\payment\PaymentInterface;
use app\payment\wxpay\lib\WxPayApi;
use Exception;

class LlPayment implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pay(string $running_no): array
    {
        try {
            $llPayApi = new LlPayApi();
            $result = $llPayApi->pay($running_no);

            return [
                'code_data' => $result['gateway_url'],
            ];
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }


    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refund(string $running_no): array
    {
        try {
            $llRefundApi = new LlRefundApi();
            $result = $llRefundApi->allRefund($running_no);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch(string $running_no): array
    {
        try {
            $llDispatchApi = new LlDispatchApi();
            $result = $llDispatchApi->dispatch($running_no);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }
    /**
     * 申请电子回单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function applyReceipt():array{
        try {
            $llDispatchApi = new LlPayApi();
            $result = $llDispatchApi->applyReceipt();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 查看电子回单
     * @return array
     * @throws ApiException
     */
    public function searchReceipt(): array{
        try {
            $llDispatchApi = new LlPayApi();
            $result = $llDispatchApi->searchReceipt();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 查询账单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery(): array {
        try {

            $llDispatchApi = new LlPayApi();

            $result = $llDispatchApi->commonQuery();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return \Psr\Http\Message\ResponseInterface|string
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function union(){
        try {
            $llDispatchApi = new LlPayApi();
            $result = $llDispatchApi->union();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
    /**
     * @return \app\common\vo\TradeRefundVo
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unionRefund(){
        try {
            $llDispatchApi = new LlRefundApi();
            $result = $llDispatchApi->unionRefund();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonReceipt():array {
        try {
            $llDispatchApi = new LlPayApi();
            $result = $llDispatchApi->commonReceipt();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
}