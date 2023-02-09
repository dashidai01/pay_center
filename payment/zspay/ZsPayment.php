<?php
namespace app\payment\zspay;

use app\common\exception\ApiException;
use app\payment\PaymentInterface;
use app\payment\zspay\lib\ZsPayApi;
use Exception;
class ZsPayment implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pay(string $running_no): array
    {


    }


    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refund(string $running_no): array
    {
        try{
            $zsLogic = new ZsPayApi();

            $result = $zsLogic->refundExclusive($running_no);
            return $result;
        }catch (Exception $e){
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
            $zSpayApi = new ZsPayApi();
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
            $zSpayApi = new ZsPayApi();
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
            $zSpayApi = new ZsPayApi();
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
            $zsPayApi = new ZsPayApi();
            $result = $zsPayApi->commonQuery();
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

    /**
     * 批量转账
     * @return \stdClass
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function batchDispatch() {
        try {
            $zsPayApi = new ZsPayApi();
            $result = $zsPayApi->batchDispatch();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
}