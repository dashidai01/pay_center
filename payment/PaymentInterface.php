<?php

namespace app\payment;


interface PaymentInterface
{
    /**
     * @param string $running_no
     * @return array
     */
    public function pay(string $running_no): array;


    /**
     * @param string $running_no
     * @return array
     */
    public function refund(string $running_no): array;

    /**
     * @param string $running_no
     * @return array
     */
    public function dispatch(string $running_no): array;

    /**
     * 申请电子回单
     * @return array
     */
    public function applyReceipt(): array;

    /**
     * 查看电子回单
     * @return array
     */
    public function searchReceipt(): array;
    /**
     * 查询账单
     * @return array
     */
    public function commonQuery(): array;
    /**
     * 电子回单聚合查询
     * @return array
     */
    public function commonReceipt(): array;
}