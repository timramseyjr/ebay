<?php

namespace timramseyjr\Ebay;

use DTS\eBaySDK\Sdk;

class EbayServices
{
    public $sdk;

    function __construct()
    {
        $ebay = new Ebay();
        $config = [
            'credentials' => config('ebay.'.config('ebay.mode').'.credentials'),
            'authToken'     => $ebay->getAuthToken(),
            'sandbox' =>  config('ebay.mode') === 'sandbox' ? true : false
        ];
        $this->sdk = new Sdk($config);
    }

    function __call($name, $args)
    {
        if (strpos($name, 'create') === 0) {
            $service = 'create'.substr($name, 6);
            $configuration = isset($args[0]) ? $args[0] : [];
            return $this->sdk->$service($configuration);
        }
    }

}
