<?php

namespace timramseyjr\Ebay;

use timramseyjr\Ebay\Models\EbayStore;

class Ebay
{
    private $sdk;
    private $config;
    protected $sandbox;
    function __construct()
    {
        $this->sandbox = config('ebay.mode') === 'sandbox' ? true : false;
        $config = [
            'credentials' => config('ebay.'.config('ebay.mode').'.credentials'),
            'siteId'     => config('ebay.siteId'),
            'ruName' => config('ebay.'.config('ebay.mode').'.ruName'),
            'sandbox' => $this->sandbox
        ];

        $this->config = $config;
    }

    function __call($name, $args)
    {
        if (strpos($name, 'create') === 0) {
            $service = 'create'.substr($name, 6);
            $configuration = isset($args[0]) ? $args[0] : [];
            return $this->sdk->$service($configuration);
        }
    }

    public function getAuthToken(){
        $ebay_store = EbayStore::where('environment',config('ebay.mode'))->first();
        return $ebay_store->auth_token;
    }

    public function getOAuthToken(){
        return config('ebay.'.config('ebay.mode').'.oauthUserToken');
    }
    /*
     * Return configurations from Config
     */
    public function getConfig(){
        return $this->config;
    }

    public function keyReplacementPattern($key,$value)
    {
        $escaped = preg_quote('='.$value, '/');

        return "/^$key{$escaped}/m";
    }
    public function redirectAuthNAuthUrlForUser($sessionId){
        $url = $this->sandbox
            ? 'https://signin.sandbox.ebay.com/ws/eBayISAPI.dll?SignIn&'
            : 'https://signin.ebay.com/ws/eBayISAPI.dll?SignIn&';
        $urlParams = [
            'runame'     => config('ebay.'.config('ebay.mode').'.ruName'),
            'SessID'  => $sessionId,
            'ruparams' => 'SessID='.$sessionId
        ];
        return $url.http_build_query($urlParams, null, '&', PHP_QUERY_RFC3986);
    }
}