<?php

namespace app\payment\gdpay;


use app\common\exception\ApiException;
use app\payment\gdpay\lib\GdDispatchApi;
use app\payment\llpay\lib\LlDispatchApi;
use app\payment\PaymentInterface;
use Exception;

class GdPayment implements PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function pay(string $running_no): array {
        throw new ApiException('暂不支持');
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     */
    public function refund(string $running_no): array {
        throw new ApiException('暂不支持');
    }

    /**
     * @param string $running_no
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function dispatch(string $running_no): array {
        try {
            $GaoDengDispatchApi = new GdDispatchApi();
            $result        = $GaoDengDispatchApi->dispatch($running_no);
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }

    }

    /**
     * 申请电子回单
     * @return array
     * @throws ApiException
     */
    public function applyReceipt(): array {
        throw new ApiException('暂不支持!');
    }

    /**
     * 查看电子回单
     * @return array
     * @throws ApiException
     */
    public function searchReceipt(): array {
        throw new ApiException('暂不支持!');
    }

    /**
     * 查询账单
     * @return array
     * @throws ApiException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function commonQuery(): array {
        try {
            $GaoDengDispatchApi = new GdDispatchApi();
            $result        = $GaoDengDispatchApi->commonQuery();
            return $result;
        } catch (Exception $e) {
            throw new ApiException($e->getMessage());
        }
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