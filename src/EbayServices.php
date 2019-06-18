<?php

namespace timramseyjr\Ebay;

use DTS\eBaySDK\Sdk;

class EbayServices
{
    private $sdk;

    function __construct()
    {
        $ebay = new Ebay();
        $config = $ebay->getConfig();
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
