<?php

namespace timramseyjr\Ebay\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use timramseyjr\Ebay\Ebay;

class CheckEbayTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ebay:checktokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks expirations and updates auth tokens if necessary';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $ebay = new Ebay();
        $config = $ebay->getConfig();
        $service = new \DTS\eBaySDK\Trading\Services\TradingService($config);
        $request = new \DTS\eBaySDK\Trading\Types\GetStoreRequestType();
        $request->RequesterCredentials = new \DTS\eBaySDK\Trading\Types\CustomSecurityHeaderType();
        $request->RequesterCredentials->eBayAuthToken = $ebay->getAuthToken();
        $response = $service->getStore($request);
        dd($response);
        //$auth = $this->keyReplacementPattern('EBAY_SANDBOX_AUTH_TOKEN',config('ebay.sandbox.authToken'));
        $ebay = new Ebay();
        $config = $ebay->getConfig();
        $oAuthService = new \DTS\eBaySDK\OAuth\Services\OAuthService($config);
        $response = $oAuthService->getAppToken();
        if ($response->getStatusCode() !== 200) {
            Log::alert(sprintf(
                "%s: %s\n\n",
                $response->error,
                $response->error_description
            ));
        } else {
            $keyToReplace = config('ebay.mode') === 'sandbox' ? 'EBAY_SANDBOX_AUTH_TOKEN' : 'EBAY_PROD_AUTH_TOKEN';
            file_put_contents(app()->environmentFilePath(), preg_replace(
                $ebay->keyReplacementPattern($keyToReplace,config('ebay.'.config('ebay.mode').'.authToken')),
                $keyToReplace.'='.$response->access_token,
                file_get_contents(app()->environmentFilePath())
            ));
        }
        print("ok");
    }
}
