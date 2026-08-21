<?php
/*
1.  Create new stripe transaction, it will return "payID" & "payUrl":

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripeClientSecret);
    $stripeAPI->setReturnUrl(xxxx);
    $stripeAPI->setCancelUrl(xxxx);
    $stripeCheckout = $stripeAPI->doCheckout($items)
    redirect($stripeCheckout['payment_url']);
 

5.  Webhooks checking, determine whether the transaction is completed (if paid, it will return "payment_id", otherwise return "false):

    require_once(app_path('Libs/payment/StripeAPI.php'));
    $stripeAPI = new \StripeAPI($stripeClientSecret);
    $stripeResponse = $stripeAPI->webHookResult();

6.  Return link, do loop checking:
 
    $securityCheckoutId = $this->getParamValue('sc_id');
    if(!empty($securityCheckoutId)) {
        $targetTemp = $eshopOrderModel->getTempOrderByPayID($securityCheckoutId);
        if(!empty($targetTemp)) {
            $stripeClientSecret = $this->_setting_model->getByName('stripe_clientSecret');
            require_once(app_path('Libs/payment/StripeAPI.php'));
            $stripeAPI = new \StripeAPI($stripeClientSecret);

            // try to get payment result
            $maxLoop = 1;
            $paymentStatus = '';
            do {
                if($maxLoop > 1) {
                    sleep(5);
                }
                $paymentStatus = $stripeAPI->fetchResult($securityCheckoutId, true);
                $maxLoop++;
                if($paymentStatus == 'paid' || $paymentStatus == 'succeeded') {
                    $paidTransactionId = $stripeAPI->transactionID();
                    ...
                }
                $maxLoop++;
            } while(($paymentStatus != 'paid' && $paymentStatus != 'succeeded') && $maxLoop < 10);
        }
    }
*/
class StripeAPI {
    private $_secretKey = '';
    private $_currency = 'hkd';
    private $_discount = 0;
    private $_shippingFee = 0;
    private $_returnUrl = '';
    private $_cancelUrl = '';
    private $_response = null;
    private $_transactionId = '';
    private $_endpointSecret = 'whsec_';

    public function __construct($secretKey = '', $endpointSecret = '') {
        if(!empty($secretKey)) {
            $this->_secretKey = $secretKey;
        }
        if(!empty($endpointSecret)) {
            $this->_endpointSecret = $endpointSecret;
        }
    }
    
    public function setCurrency($currency = 'hkd') {
        if(!empty($currency)) {
            $this->_currency = $currency;
        }
    }
    
    public function setDiscount($value = 0) {
        if(!empty($value)) {
            $this->_discount = max(0, $value);
        }
    }
    
    public function setShippingFee($value = 0) {
        if(!empty($value)) {
            $this->_shippingFee = max(0, $value);
        }
    }
    
    public function setReturnUrl($url = '') {
        if(!empty($url)) {
            $this->_returnUrl = $url;
        }
    }
    
    public function setCancelUrl($url = '') {
        if(!empty($url)) {
            $this->_cancelUrl = $url;
        }
    }
    
    public function getResponse() {
        return $this->_response;
    }
   
    /*
    $items = 
    [
        [
            'name'      =>  'xxxxx',
            'price'     =>  100,
            'quantity'  =>  1
        ],
        [
            'name'      =>  'yyyyy',
            'price'     =>  300,
            'quantity'  =>  3
        ]
    ];
    */
    public function doCheckout($items = [], $platform = 'web') {
        require_once(app_path('Libs/payment/stripe-php/init.php')); 
        \Stripe\Stripe::setApiKey($this->_secretKey);
        
        // custom identification ID, used for comparison with Stripe.
        $securityCheckoutID = 'SC-'.md5(date('YmdHis').$platform.uniqid(rand()));
        
        // start processsing
        if(is_array($items) && !empty($items)) {
            if(strtolower($platform) == 'app') {
                $itemsDetails = [];
                $totalAmount = 0;
                $k = 1;
                foreach ($items as $item) {
                    $totalAmount += $item['price'] * $item['quantity'];
                    $itemsDetails['Item'.$k] = $item['name'].' x '.$item['quantity'].' | $'.number_format(round($item['price'], 2), 2);
                    $k++;
                }
                
                if(!empty($this->_discount)) {
                    $totalAmount -= $this->_discount;
                    $itemsDetails['Discount'] = '$'.number_format(round($this->_discount, 2), 2);
                }
                
                if(!empty($this->_shippingFee)) {
                    $totalAmount += $this->_shippingFee;
                    $itemsDetails['ShippingFee'] = '$'.number_format(round($this->_shippingFee, 2), 2);
                }
                $itemsDetails['securityCheckoutID'] = $securityCheckoutID;
  
                $paymentIntent = \Stripe\PaymentIntent::create([
                    'amount'    =>  round($totalAmount * 100),
                    'currency'  =>  $this->_currency,
                    'metadata'  =>  $itemsDetails,
                    'automatic_payment_methods' =>  ['enabled' => true],
                ]);

                return [
                    'payID'                 =>  $paymentIntent->id,
                    'clientSecret'          =>  $paymentIntent->client_secret,
                    'securityCheckoutID'    =>  $securityCheckoutID
                ];
            }
            else if(!empty($this->_returnUrl) && !empty($this->_cancelUrl)) {
                $lineItems = [];
                foreach ($items as $key => $value) {
                    $lineItems[] = [
                        'price_data' => [
                            'product_data' => [
                                'name' => $value['name'],
                            ],
                            'currency' => $this->_currency,
                            'unit_amount' => round($value['price']*100),
                        ],
                        'quantity' => $value['quantity']
                    ];
                }

                $options =
                [
                    'line_items'    =>  $lineItems,
                    'discounts'     =>  null,
                    'mode'          =>  'payment',
                    'success_url'   =>  $this->_returnUrl.((strpos($this->_returnUrl, '?') === false)?'?':'&').'sc_id='.$securityCheckoutID,
                    'cancel_url'    =>  $this->_cancelUrl.((strpos($this->_returnUrl, '?') === false)?'?':'&').'sc_id='.$securityCheckoutID,
                    'expires_at'    =>  (time() + 30 * 60), // 30 minutes
                    'metadata'      => 
                    [
                        'securityCheckoutID' => $securityCheckoutID
                    ]
                ];

                if(!empty($this->_discount)) {
                    $coupon = \Stripe\Coupon::create(
                    [
                        'currency'      =>  $this->_currency,
                        'amount_off'    =>  round($this->_discount*100),
                        'duration'      =>  'once'
                    ]);
                    $options['discounts'] = 
                    [
                        ['coupon' => $coupon->id]
                    ];
                }

                if(!empty($this->_shippingFee)) {
                    $options['shipping_options'] = 
                    [
                        [
                            'shipping_rate_data' => 
                            [
                                'type' => 'fixed_amount',
                                'fixed_amount' => 
                                [
                                    'currency'  => $this->_currency,
                                    'amount'    => round($this->_shippingFee*100)
                                ],
                                'display_name' => 'Shipping Fee'
                            ]
                        ]
                    ];
                }

                $checkoutSession = \Stripe\Checkout\Session::create($options);
                
                return 
                [
                    'payID'                 =>  $checkoutSession->id,  // e.g., cs_test_b1dcM72EiT6klc7zMCqmJB8hFusgVgseqHmWmGzHKcFh3cuju97oRs2uaG,
                    'payUrl'                =>  $checkoutSession->url,
                    'securityCheckoutID'    =>  $securityCheckoutID
                ];
            }
        }
        
        return false;
    }
    
    public function fetchResult($sessionId = '', $paymentStatusOnly = false, $platform = 'web') {
        if(!empty($sessionId)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->_secretKey);
            
            try {
                if(strtolower($platform) == 'app') {
                    $intent = \Stripe\PaymentIntent::retrieve($sessionId);
                    $this->_transactionId = $sessionId;
                    return ($paymentStatusOnly && !empty($intent['status']))?strtolower($intent['status']):$intent;
                }
                else {
                    $session = \Stripe\Checkout\Session::retrieve($sessionId);
                    $this->_transactionId = ((!empty($session['payment_intent']))?$session['payment_intent']:'');
                    return ($paymentStatusOnly && !empty($session['payment_status']))?strtolower($session['payment_status']):$session;
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
            
        return false;
    }

    public function webHookResult($overwritePayload = '') {
        $payload = @file_get_contents('php://input');
        if(!empty($overwritePayload)) {
            $payload = $overwritePayload;
        }
        
        if(!empty($payload)) {
            require_once(app_path('Libs/payment/stripe-php/init.php'));
            \Stripe\Stripe::setApiKey($this->_secretKey);
            try {
                if (!empty($this->_endpointSecret) && ($this->_endpointSecret != 'whsec_')) {
                    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
                    if(empty($sigHeader)) {
                        http_response_code(400);
                        return false;
                    }
                    $this->_response = \Stripe\Webhook::constructEvent(
                        $payload, $sigHeader, $this->_endpointSecret
                    );
                }
                else {
                    $this->_response = \Stripe\Event::constructFrom(
                        json_decode($payload, true)
                    );
                }
            } catch(\UnexpectedValueException $e) {
                http_response_code(400);
                return false;
            }
            
            if (strtolower($this->_response['type']) == 'checkout.session.completed') {
                if(strtolower($this->_response['data']['object']['payment_status']) == 'paid') {
                    return $this->_response['data']['object'];
                }
            }
            else if (strtolower($this->_response['type']) == 'payment_intent.succeeded') {
                if(strtolower($this->_response['data']['object']['status']) == 'succeeded') {
                    $relatedCheckoutSession = \Stripe\Checkout\Session::all([
                        'payment_intent' => $this->_response['data']['object']['id'],
                        'limit' => 1
                    ]);
                    
                    return  $relatedCheckoutSession->data[0] ?? null;
                }
            }
        }
        
        return false;
    }
    
    public function transactionID($sessionId = '') {
        if(!empty($sessionId)) {
            $this->fetchResult($sessionId);
        }
        
        return $this->_transactionId;
    }
}
