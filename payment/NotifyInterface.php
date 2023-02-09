<?php
namespace app\payment;


interface NotifyInterface
{
    /**
     * @param array $params
     * @return mixed
     */
    public function payNotify(array $params);


    /**
     * @param array $params
     * @return mixed
     */
    public function refundNotify(array $params);

    /**
     * @param array $params
     * @return mixed
     */
    public function transferNotify(array $params);
}