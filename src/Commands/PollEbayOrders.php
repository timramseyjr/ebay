<?php

namespace timramseyjr\Ebay\Commands;

use App\Models\Orders;
use App\Models\Products;
use App\Models\User;
use DTS\eBaySDK\Trading\Enums\OrderStatusCodeType;
use DTS\eBaySDK\Trading\Services\TradingService;
use DTS\eBaySDK\Trading\Types\CustomSecurityHeaderType;
use DTS\eBaySDK\Trading\Types\GetItemRequestType;
use DTS\eBaySDK\Trading\Types\GetOrdersRequestType;
use DTS\eBaySDK\Trading\Types\OrderIDArrayType;
use DTS\eBaySDK\Constants;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use timramseyjr\Ebay\Ebay;

class PollEbayOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ebay:pollorders {orderid?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get eBay Orders every 15 Minutes via Cron and creates orders to deduct inventory';
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
    public function cleanStr4QB($str){
        setlocale(LC_ALL, "en_US.utf8");
        $str = mb_convert_encoding($str, "UTF-8", "UTF-8");
        $str = iconv("UTF-8", 'ASCII//TRANSLIT//IGNORE', $str);
        $str = str_replace(':','-',$str);
        return $str;
    }
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(){
        $orderid = $this->argument('orderid');
        $module_import = config('quickbooks.QB_MODULE_IMPORT');
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new TradingService($ebayConfig);
        if(!is_null($orderid)){
            $response = $this->requestOrder($orderid);
        }else {
            $carbon = Carbon::now('UTC');
            /*
             * Note: If a GetOrders call is made within a few seconds after the creation of a multiple line item order, the caller runs the risk of retrieving orders that are in an inconsistent state, since the order consolidation involved in a multi-line item order may not have been completed. For this reason, it is recommended that sellers include the CreateTimeTo field in the call, and set its value to: Current Time - 2 minutes.
             * https://ebaydts.com/eBayKBDetails?KBid=1788
             * */
            $nowString = $carbon->subMinutes(2)->format('Y-m-d\TH:i:s\Z');
            $thenString = $carbon->subMinutes(17)->format('Y-m-d\TH:i:s\Z'); //subMinutes(17)
            $request = new GetOrdersRequestType();
            $request->RequesterCredentials = new CustomSecurityHeaderType();
            $request->RequesterCredentials->eBayAuthToken = $ebay->getAuthToken();
            $request->ModTimeFrom = new \DateTime($thenString);
            $request->ModTimeTo = new \DateTime($nowString);
            $request->OrderStatus = OrderStatusCodeType::C_COMPLETED;
            $response = $service->getOrders($request);
        }
        if ($response->Ack === 'Failure') {
            foreach($response->Errors as $error){
                Log::alert(sprintf(
                    "%s: %s\n\n",
                    $error->ErrorClassification,
                    $error->LongMessage
                ));
            }
        } else {
            foreach ($response->OrderArray->Order as $order) {
                $listingIdentifier = null;
                $tld_order = [
                    'channelOrderNnum' => $order->OrderID,
                    'orderStatusInt' => 0,
                    'orderCreated' => $order->CreatedTime,
                    'comments' => '',
                    'shippingAmt' => $order->ShippingServiceSelected->ShippingServiceCost->value,
                    'tax' => $order->ShippingDetails->SalesTax->SalesTaxAmount->value ?? 0,
                    'total' => $order->Total->value
                ];
                $new_order = Orders::firstOrNew(['channelOrderNum' => $order->OrderID]);
                $fields = $new_order->fields;
                $fields['Shipping'] = $order->ShippingServiceSelected->ShippingService;
                $fields['eBayUserId'] = $order->BuyerUserID;
                if(!empty($order->BuyerCheckoutMessage)){
                    preg_match('/[A-Z0-9]{17}/i',$order->BuyerCheckoutMessage,$matches);
                    if(count($matches)){
                        $fields['VIN'] = $matches[0];
                    }
                }
                $nameParser = new \FullNameParser();
                $name_array = $nameParser->parse_name($order->ShippingAddress->Name);
                $order_address['shipping'] = [
                    'label' => 'Shipping Address',
                    'first_name' => $this->cleanStr4QB($name_array['fname']),
                    'last_name' => $this->cleanStr4QB($name_array['lname']),
                    'company' => '',
                    'address_1' => $this->cleanStr4QB($order->ShippingAddress->Street1),
                    'address_2' => $this->cleanStr4QB($order->ShippingAddress->Street2),
                    'city' => $order->ShippingAddress->CityName,
                    'state' => $order->ShippingAddress->StateOrProvince,
                    'zip' => $order->ShippingAddress->PostalCode,
                    'phone' => $order->ShippingAddress->Phone,
                    'country' => $order->ShippingAddress->Country,
                    'order_num' => $order->OrderID
                ];
                $new_order->addresses = $order_address;
                $buyer_email = '';
                $item_array = [];
                foreach ($order->TransactionArray->Transaction as $k => $transaction) {
                    if($transaction->Buyer->Email === "Invalid Request"){
                        continue 2;
                    }
                    $item = $transaction->Item;
                    $sku =  $transaction->Variation->SKU ?? $item->SKU ?? str_random(20);
                    $buyer_email = $transaction->Buyer->Email;
                    $title =  $transaction->Variation->VariationTitle ?? $item->Title; //isset($item->Variation->VariationTitle) ?
                    $this_item = Products::firstOrNew(['code' => $sku]);
                    if($module_import){
                        $mpn = $this->getMPN($item->ItemID);
                        $item_array[$k] = array(
                            'prod_id' => substr($item->ItemID,0,29),
                            'name' => $title,
                            'qty' => $transaction->QuantityPurchased,
                            'price' => $transaction->TransactionPrice->value,
                            'linenum' => $k
                        );
                        //Search MPN and add PartNumber so QB will match PN and deduct Inventory
                        if(!empty($mpn)){
                            $item_array[$k]['options'] = json_encode([[
                                'name' => 'PartNumber',
                                'value' => $mpn
                            ]]);
                        }
                    }elseif($this_item->exists){
                        $item_array[$k] = array(
                            'prod_id' => substr($this_item->yid,0,29),
                            'code' => $this_item->code,
                            'ebay_id' => $item->ItemID,
                            'qty' => $transaction->QuantityPurchased,
                            'price' => $transaction->TransactionPrice->value,
                            'linenum' => $k
                        );
                        $logrArray = ['type' => 'Notice', 'description' => 'Item was found in system', 'action' => 'Add eBay Orders'];
                    }else {
                        /*TODO: Address*/
                        if(false){//Create Item if it does exist
                            $sales_tax = new \DTS\eBaySDK\Trading\Types\TaxDetailsType();
                            $this_item->code = $sku;
                            $this_item->yid = 'test1234';
                            $this_item->name = $title;
                            $this_item->price = $this_item->single_price = $transaction->TransactionPrice->value;
                            $this_item->QuickbooksQueuePriority = 4;
                            $this_item->save();
                        }else {
                            $logrArray = ['type' => 'Notice', 'description' => printf('sku: %d could not be found in the catalog', $item->SKU), 'action' => 'Add eBay Orders'];
                        }
                    }
                }
                $user = User::firstOrNew(['email' => $buyer_email]);
                $user->name = $this->cleanStr4QB($order->ShippingAddress->Name);
                $user->email = $buyer_email;
                $user->active = 1;
                if(!$user->exists) {
                    $user->api_token = bcrypt(str_random(40));
                    $user->password = bcrypt(str_random(40));
                }
                $user->QuickbooksQueuePriority = 5;
                $user->save();
                $new_order->user_id = $user->id;
                $new_order->fields = $fields;
                $new_order->items = $item_array;
                $new_order->channel = 'ebay';
                $new_order->fill($tld_order);
                $new_order->save();
            }
        }
    }
    public function handleRest(){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $ebayConfig['authorization'] = $ebay->getOAuthToken();
        $carbon = Carbon::now();
        $now = $carbon->subMinutes(2)->toDateString();
        $then = $carbon->subDays(5)->toDateString(); //subMinutes(17)
        $service = new \DTS\eBaySDK\Fulfillment\Services\FulfillmentService($ebayConfig);
        /*
         * Note: If a GetOrders call is made within a few seconds after the creation of a multiple line item order, the caller runs the risk of retrieving orders that are in an inconsistent state, since the order consolidation involved in a multi-line item order may not have been completed. For this reason, it is recommended that sellers include the CreateTimeTo field in the call, and set its value to: Current Time - 2 minutes.
         * */
        $request = new \DTS\eBaySDK\Fulfillment\Types\GetOrdersRestRequest();

        /*$filter = new \DTS\eBaySDK\Fulfillment\Types\FilterField();
        $filter->field = 'creationdate';
        $range = new \DTS\eBaySDK\Fulfillment\Types\RangeValue();
        $range->start = $now;
        $range->end = $then;
        $filter->range = $range;*/

        $request->filter = 'creationdate:%5B'.$then.'..'.$now.'%5D';
        $response = $service->getOrders($request);
        dd($response);
    }
    private function getMPN($itemID){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new TradingService($ebayConfig);
        $request = new GetItemRequestType();
        $request->IncludeItemSpecifics = true;
        $request->RequesterCredentials = new CustomSecurityHeaderType();
        $request->RequesterCredentials->eBayAuthToken = $ebay->getAuthToken();
        $request->ItemID = $itemID;
        $response = $service->getItem($request);
        $mpn = '';
        if (isset($response->Item->ItemSpecifics)) {
            foreach ($response->Item->ItemSpecifics->NameValueList as $specific) {
                $name = $specific->Name;
                if($name === 'Manufacturer Part Number'){
                    $mpn = implode(', ', iterator_to_array($specific->Value));
                }
            }
        }
        return $mpn;
    }
    private function getItemRest($itemID){
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        dd($ebay->getAuthToken());
        $service = new CatalogService([
            'authorization' => $ebay->getAuthToken()
        ]);
        $request = new GetProductRestRequest();
        $request->epid = $itemID;
        $response = $service->getProduct($request);
        dd($response);
        return $response;
    }
    private function requestOrder($orderId) {
        $ebay = new Ebay();
        $ebayConfig = $ebay->getConfig();
        $service = new TradingService($ebayConfig);
        $request = new GetOrdersRequestType();
        $request->RequesterCredentials = new CustomSecurityHeaderType();
        $request->RequesterCredentials->eBayAuthToken = $ebay->getAuthToken();
        $orderIds = new OrderIDArrayType();
        $orderIds->OrderID[] = $orderId;
        $request->OrderIDArray = $orderIds;
        $response = $service->getOrders($request);
        return $response;
    }
}
