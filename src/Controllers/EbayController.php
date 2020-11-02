<?php

namespace timramseyjr\Ebay\Controllers;

use DTS\eBaySDK\OAuth\Services\OAuthService;
use DTS\eBaySDK\OAuth\Types\GetUserTokenRestRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use \timramseyjr\Ebay\Ebay;
use anlutro\LaravelSettings\Facade as Setting;
use Illuminate\Routing\Controller as BaseController;
use \DTS\eBaySDK\Constants;
use \DTS\eBaySDK\Trading\Services;
use \DTS\eBaySDK\Trading\Types;
use \DTS\eBaySDK\Trading\Enums;
use timramseyjr\Ebay\Models\EbayStore;

class EbayController extends BaseController{
    protected $ebay;

    public function __construct(){
        $this->ebay = new Ebay();
    }
    public function authSuccess(Request $request){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new \DTS\eBaySDK\Trading\Services\TradingService($ebayConfig);
        $ebay_request = new Types\FetchTokenRequestType();
        $ebay_request->SessionID = urldecode($request->input('SessID'));
        $response = $service->fetchToken($ebay_request);
        if ($response->Ack !== 'Failure') {
            $auth_token = $response->eBayAuthToken;
            $user = $request->input('username');
            $ebay_store = EbayStore::firstOrNew(['user_id' => $user]);
            $ebay_store->user_id = $user;
            $ebay_store->auth_token = $auth_token;
            $ebay_store->auth_token_expiration = $response->HardExpirationTime;
            $ebay_store->environment = config('ebay.mode');
            $ebay_store->save();
            flash('AuthToken has been updated','success');
        }else{
            Log::alert(sprintf(
                "%s: %s\n\n",
                $response->error,
                $response->error_description
            ));
            flash('AuthToken has not been updated','error');
        }
        return redirect('settings');
    }
    public function oauthSuccess(Request $request){
        $config = $this->ebay->getConfig();
        $oAuthService = new OAuthService($config);
        $eBayrequest = new GetUserTokenRestRequest();

        $eBayrequest->code = $request->input('code');
        /**
         * Send the request.
         */
        $response = $oAuthService->getUserToken($eBayrequest);
        if ($response->getStatusCode() !== 200) {
            Log::alert(sprintf(
                "%s: %s\n\n",
                $response->error,
                $response->error_description
            ));
        } else {
            $keyToReplace = config('ebay.mode') ? 'EBAY_SANDBOX_OAUTH_USER_TOKEN' : 'EBAY_PROD_OAUTH_USER_TOKEN';
            file_put_contents(app()->environmentFilePath(), preg_replace(
                $this->ebay->keyReplacementPattern($keyToReplace,config('ebay.'.config('ebay.mode').'.oauthUserToken')),
                $keyToReplace.'='.$response->access_token,
                file_get_contents(app()->environmentFilePath())
            ));
        }
        dd($response);
    }
    public function authFailure(Request $request){
        $authToken = Setting::get('appAuthToken','test');
        dd($authToken);
    }
    public function redirectToEbay(){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new \DTS\eBaySDK\Trading\Services\TradingService($ebayConfig);
        $request = new Types\GetSessionIDRequestType();
        $ruName = config('ebay.'.config('ebay.mode').'.ruName');
        $request->RuName = $ruName;
        $response = $service->getSessionID($request);
        $sessionID = $response->SessionID;
        $url = $ebay->redirectAuthNAuthUrlForUser($sessionID);
        //echo $url;
        //echo 'https://signin.sandbox.ebay.com/ws/eBayISAPI.dll?SignIn&runame='.$ruName.'&SessID='.$sessionID.'&ruparams=SessID='.$sessionID;
        //dd($url);
        //$url = 'https://signin.sandbox.ebay.com/ws/eBayISAPI.dll?SignIn&runame='.$ruName.'&SessID='.$sessionID.'&ruparams=SessID='.$sessionID;
        return redirect($url);
    }
    public function redirectToEbayOauth(){
        $config = $this->ebay->getConfig();
        $oAuthService = new OAuthService($config);
        $state = uniqid();
        $url =  $oAuthService->redirectUrlForUser([
            'state' => $state,
            'scope' => [
                'https://api.ebay.com/oauth/api_scope/sell.account',
                'https://api.ebay.com/oauth/api_scope/sell.inventory',
                'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
                'https://api.ebay.com/oauth/api_scope'
            ]
        ]);
        return redirect($url);
    }
    public function ebayCredentials(Request $request){
        if($request->input('pwd') === 'acr54jfHU656V'){
            $config = $this->ebay->getConfig();
            $config['credentials']['eBayAuthToken'] = $this->ebay->getAuthToken();
            return $config;
        }else{
            return abort(401);
        }
    }
    public function addTestItem(){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new Services\TradingService($ebayConfig);
        $request = new Types\AddFixedPriceItemRequestType();
        $request->RequesterCredentials = new Types\CustomSecurityHeaderType();
        $request->RequesterCredentials->eBayAuthToken = $ebay->getAuthToken();

        $item = new Types\ItemType();
        $item->ListingType = Enums\ListingTypeCodeType::C_FIXED_PRICE_ITEM;
        $item->Quantity = 99;

        /**
         * Let the listing be automatically renewed every 30 days until cancelled.
         */
        $item->ListingDuration = Enums\ListingDurationCodeType::C_GTC;

        /**
         * The cost of the item is $19.99.
         * Note that we don't have to specify a currency as eBay will use the site id
         * that we provided earlier to determine that it will be United States Dollars (USD).
         */
        $item->StartPrice = new Types\AmountType(['value' => 19.99]);

        /**
         * Allow buyers to submit a best offer.
         */
        $item->BestOfferDetails = new Types\BestOfferDetailsType();
        $item->BestOfferDetails->BestOfferEnabled = true;

        /**
         * Automatically accept best offers of $17.99 and decline offers lower than $15.99.
         */
        $item->ListingDetails = new Types\ListingDetailsType();
        $item->ListingDetails->BestOfferAutoAcceptPrice = new Types\AmountType(['value' => 17.99]);
        $item->ListingDetails->MinimumBestOfferPrice = new Types\AmountType(['value' => 15.99]);

        /**
         * Provide a title and description and other information such as the item's location.
         * Note that any HTML in the title or description must be converted to HTML entities.
         */
        $item->Title = 'Bits & Bobs';
        $item->Description = '<h1>Bits & Bobs</h1><p>Just some &lt;stuff&gt; I found.</p>';
        $item->SKU = 'ABC-001';
        $item->Country = 'US';
        $item->Location = 'Beverly Hills';
        $item->PostalCode = '90210';
        /**
         * This is a required field.
         */
        $item->Currency = 'USD';

        /**
         * Display a picture with the item.
         */
        $item->PictureDetails = new Types\PictureDetailsType();
        $item->PictureDetails->GalleryType = Enums\GalleryTypeCodeType::C_GALLERY;
        $item->PictureDetails->PictureURL = ['http://lorempixel.com/1500/1024/abstract'];

        /**
         * List item in the Books > Audiobooks (29792) category.
         */
        $item->PrimaryCategory = new Types\CategoryType();
        $item->PrimaryCategory->CategoryID = '29792';

        /**
         * Tell buyers what condition the item is in.
         * For the category that we are listing in the value of 1000 is for Brand New.
         */
        $item->ConditionID = 1000;

        /**
         * Buyers can use one of two payment methods when purchasing the item.
         * Visa / Master Card
         * PayPal
         * The item will be dispatched within 1 business days once payment has cleared.
         * Note that you have to provide the PayPal account that the seller will use.
         * This is because a seller may have more than one PayPal account.
         */
        $item->PaymentMethods = [
            'VisaMC',
            'PayPal'
        ];
        $item->PayPalEmailAddress = 'sb-1by7m2606534@business.example.com';
        $item->DispatchTimeMax = 1;

        /**
         * Setting up the shipping details.
         * We will use a Flat shipping rate for both domestic and international.
         */
        $item->ShippingDetails = new Types\ShippingDetailsType();
        $item->ShippingDetails->ShippingType = Enums\ShippingTypeCodeType::C_FLAT;

        /**
         * Create our first domestic shipping option.
         * Offer the Economy Shipping (1-10 business days) service at $2.00 for the first item.
         * Additional items will be shipped at $1.00.
         */
        $shippingService = new Types\ShippingServiceOptionsType();
        $shippingService->ShippingServicePriority = 1;
        $shippingService->ShippingService = 'Other';
        $shippingService->ShippingServiceCost = new Types\AmountType(['value' => 2.00]);
        $shippingService->ShippingServiceAdditionalCost = new Types\AmountType(['value' => 1.00]);
        $item->ShippingDetails->ShippingServiceOptions[] = $shippingService;

        /**
         * Create our second domestic shipping option.
         * Offer the USPS Parcel Select (2-9 business days) at $3.00 for the first item.
         * Additional items will be shipped at $2.00.
         */
        $shippingService = new Types\ShippingServiceOptionsType();
        $shippingService->ShippingServicePriority = 2;
        $shippingService->ShippingService = 'USPSParcel';
        $shippingService->ShippingServiceCost = new Types\AmountType(['value' => 3.00]);
        $shippingService->ShippingServiceAdditionalCost = new Types\AmountType(['value' => 2.00]);
        $item->ShippingDetails->ShippingServiceOptions[] = $shippingService;

        /**
         * Create our first international shipping option.
         * Offer the USPS First Class Mail International service at $4.00 for the first item.
         * Additional items will be shipped at $3.00.
         * The item can be shipped Worldwide with this service.
         */
        $shippingService = new Types\InternationalShippingServiceOptionsType();
        $shippingService->ShippingServicePriority = 1;
        $shippingService->ShippingService = 'USPSFirstClassMailInternational';
        $shippingService->ShippingServiceCost = new Types\AmountType(['value' => 4.00]);
        $shippingService->ShippingServiceAdditionalCost = new Types\AmountType(['value' => 3.00]);
        $shippingService->ShipToLocation = ['WorldWide'];
        $item->ShippingDetails->InternationalShippingServiceOption[] = $shippingService;

        /**
         * Create our second international shipping option.
         * Offer the USPS Priority Mail International (6-10 business days) service at $5.00 for the first item.
         * Additional items will be shipped at $4.00.
         * The item will only be shipped to the following locations with this service.
         * N. and S. America
         * Canada
         * Australia
         * Europe
         * Japan
         */
        $shippingService = new Types\InternationalShippingServiceOptionsType();
        $shippingService->ShippingServicePriority = 2;
        $shippingService->ShippingService = 'USPSPriorityMailInternational';
        $shippingService->ShippingServiceCost = new Types\AmountType(['value' => 5.00]);
        $shippingService->ShippingServiceAdditionalCost = new Types\AmountType(['value' => 4.00]);
        $shippingService->ShipToLocation = [
            'Americas',
            'CA',
            'AU',
            'Europe',
            'JP'
        ];
        $item->ShippingDetails->InternationalShippingServiceOption[] = $shippingService;

        /**
         * The return policy.
         * Returns are accepted.
         * A refund will be given as money back.
         * The buyer will have 14 days in which to contact the seller after receiving the item.
         * The buyer will pay the return shipping cost.
         */
        $item->ReturnPolicy = new Types\ReturnPolicyType();
        $item->ReturnPolicy->ReturnsAcceptedOption = 'ReturnsAccepted';
        $item->ReturnPolicy->RefundOption = 'MoneyBack';
        $item->ReturnPolicy->ReturnsWithinOption = 'Days_14';
        $item->ReturnPolicy->ShippingCostPaidByOption = 'Buyer';

        /**
         * Finish the request object.
         */
        $request->Item = $item;

        /**
         * Send the request.
         */
        $response = $service->addFixedPriceItem($request);

        /**
         * Output the result of calling the service operation.
         */
        if (isset($response->Errors)) {
            foreach ($response->Errors as $error) {
                printf(
                    "%s: %s\n%s\n\n",
                    $error->SeverityCode === Enums\SeverityCodeType::C_ERROR ? 'Error' : 'Warning',
                    $error->ShortMessage,
                    $error->LongMessage
                );
            }
        }

        if ($response->Ack !== 'Failure') {
            printf(
                "The item was listed to the eBay Sandbox with the Item number %s\n",
                $response->ItemID
            );
        }
    }
}
