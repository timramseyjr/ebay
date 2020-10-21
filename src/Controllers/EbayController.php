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
}
