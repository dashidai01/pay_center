<?php
namespace app\payment\cloudpay;

use app\common\exception\ApiException;
use app\payment\cloudpay\lib\CloudPayApi;
use app\payment\PaymentInterface;

use Exception;
class CloudPayment implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pay(string $running_no): array
    {
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }


    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function refund(string $running_no): array
    {
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
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
            $cloudPayApi = new CloudPayApi();
            $result = $cloudPayApi->dispatch($running_no);
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
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }

    /**
     * 查看电子回单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function searchReceipt(): array{
        try {
            $cloudPayApi = new CloudPayApi();
            $result = $cloudPayApi->searchReceipt();
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
            $cloudPayApi = new CloudPayApi();
            $result = $cloudPayApi->commonQuery();
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
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }
    /**
     * @return \app\common\vo\TradeRefundVo
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function unionRefund(){
        // TODO: Implement commonReceipt() method.
        throw new ApiException('暂不支持!');
    }
    /**
     * @return array
     * @throws ApiException
     */
    public function commonReceipt(): array {
        try {
            $cloudPayApi = new CloudPayApi();
            $result = $cloudPayApi->commonReceipt();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }

    /**
     * @return mixed
     * @throws ApiException
     */
    public function batchDispatch() {
        try {
            $cloudPayApi = new CloudPayApi();
            return $cloudPayApi->batchDispatch();

        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
    }
}