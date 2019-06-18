<?php

namespace timramseyjr\Ebay\Facades;

use Illuminate\Support\Facades\Facade;

class Ebay extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'ebay';
    }
}
