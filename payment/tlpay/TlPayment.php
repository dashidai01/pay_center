<?php
namespace app\payment\tlpay;

use app\common\exception\ApiException;
use app\payment\PaymentInterface;
use app\payment\tlpay\lib\TlPayApi;
use Exception;
class TlPayment implements PaymentInterface
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
            $tLpayApi = new TlPayApi();
            $result = $tLpayApi->pay($running_no);
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
    public function refund(string $running_no): array
    {
        try {
            $tlPayApi = new TlPayApi();
            $result = $tlPayApi->refund($running_no);
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
            $zSpayApi = new TlPayApi();
            $result = $zSpayApi->dispatch($running_no);
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
            $zSpayApi = new TlPayApi();
            $result = $zSpayApi->applyReceipt();
            return $result;

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * 查看电子回单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchReceipt(): array{
        try {
            $zSpayApi = new TlPayApi();
            $result = $zSpayApi->searchReceipt();
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
            $tlPayApi = new TlPayApi();
            $result = $tlPayApi->commonQuery();
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

    }
    /**
     * @return \app\common\vo\TradeRefundVo
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unionRefund(){

    }
    /**
     * @return array
     * @throws ApiException
     */
    public function commonReceipt(): array {
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }
}