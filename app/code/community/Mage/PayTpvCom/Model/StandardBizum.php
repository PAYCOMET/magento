<?php

/**
 * Our test CC module adapter
 */
class Mage_PayTpvCom_Model_StandardBizum extends Mage_Payment_Model_Method_Abstract
{
    /**
     * API instance
     *
     * @var Mage_PayTpvCom_Model_Api
     */
    protected $_api = null;

    /**
     * API model type
     *
     * @var string
     */
    protected $_apiType = 'paytpvcom/api';

    /**
     * unique internal payment method identifier
     *
     * @var string [a-z0-9_]
     */
    protected $_code = 'paytpvcombizum';
    protected $_formBlockType = 'paytpvcom/standard_formbizum';
    protected $_allowCurrencyCode = array('EUR');
    protected $_canAuthorize = false;
    protected $_canCapture = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canUseInternal = false; //Payments from backend
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded = false;

    const REST_ENDPOINT = "https://rest.paycomet.com";

    protected function _construct(){
    }


    public function isAvailable(){
        return $this->getConfigData('activebizum');
    }

    /**
     * Get checkout session namespace
     *
     * @return Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get paytpv.com session namespace
     *
     * @return paytpv.com_Model_Session
     */
    public function getSession()
    {
        return Mage::getSingleton('paytpvcom/session');
    }

    /**
     * Get current quote
     *
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }

    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->_allowCurrencyCode)) {
            return false;
        }
        return true;
    }

    public function validate()
    {
        parent::validate();
    }

    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment)
    {
        return $this;
    }

    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment)
    {
        return $this;
    }


    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        // Set order status when placed
        if ('order_status' == $field) {
            return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        }
        if ('payment_action' == $field) {
            return '';
        }

        $arrFields = array("title", "activebizum", "sort_order", "allowspecific", "min_order_total", "max_order_total");
        if (in_array($field,$arrFields)) {
            $path = 'payment/paytpvcombizum/' . $field;
        } else {
            $path = 'payment/paytpvcom/' . $field;
        }
        return Mage::getStoreConfig($path, $storeId);
    }


    public function processSuccess(&$order, $session)
    {
        $orderStatus = $this->getConfigData('paid_status');
        if ($session) {
            $session->unsErrorMessage();
            $session->addSuccess(Mage::helper('payment')->__('Successful payment'));
        }
        $comment = Mage::helper('payment')->__('Successful payment');
        $order->setState($orderStatus, $orderStatus, $comment, true);
        $order->sendNewOrderEmail();
        $order->setEmailSent(true);


        $order->save();
        if ($session) {
            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
            Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/success'));
        }
    }

    public function processFail($order, $session, $message, $comment)
    {

        $state = $this->getConfigData('error_status');
        if ($state == Mage_Sales_Model_Order::STATE_CANCELED) {
            $order->cancel();
            $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Cancel Transaction.');
            $order->setStatus($state);
        } else {
            $order->setState($state, $state, $comment, true);
        }

        $order->save();
        $order->sendOrderUpdateEmail(true, $message);

        if ($message!="") {
            $session->addError($message);
            Mage::getSingleton("customer/session")->addError($message);
        }

        if ($order->getCustomerId()>0)
            Mage::app()->getResponse()->setRedirect(Mage::getUrl('sales/order/reorder', array('order_id' => $order->getIncrementId())));
        else
            Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/cart'));

    }

    public function form(
        $operationType,
        $language = 'ES',
        $terminal = '',
        $productDescription = '',
        $payment = [],
        $subscription = []
    ) {
        $params = [
            "operationType"         => (int) $operationType,
            "language"              => (string) $language,
            "terminal"              => (int) $terminal,
            "productDescription"    => (string) $productDescription,
            "payment"               => (array) $payment,
            "subscription"          => (array) $subscription
        ];

        return $this->executeRequest('/v1/form', $params);
    }

    public function executeRequest($endpoint, $params)
    {
        $jsonParams = json_encode($params);

        $curl = curl_init();

        $url = self::REST_ENDPOINT . $endpoint;

        curl_setopt_array($curl, array(
                CURLOPT_URL                 => $url,
                CURLOPT_RETURNTRANSFER      => true,
                CURLOPT_MAXREDIRS           => 3,
                CURLOPT_TIMEOUT             => 120,
                CURLOPT_FOLLOWLOCATION      => true,
                CURLOPT_HTTP_VERSION        => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST       => "POST",
                CURLOPT_POSTFIELDS          => $jsonParams,
                CURLOPT_HTTPHEADER          => array(
                    "PAYCOMET-API-TOKEN: " . $this->getConfigData('apikey'),
                    "Content-Type: application/json"
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response);
    }

    public function getOrderPlaceRedirectUrl()
    {
        return Mage::getUrl('paytpvcom/standard/bankstorebizum');
    }

    public function getBizumUrl()
    {

        $language = $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode());
        $URLOK = Mage::getUrl('paytpvcom/standard/reciboBankstore');
        $URLKO = Mage::getUrl('paytpvcom/standard/cancel');

        $terminal = $this->getConfigData('terminal');
        $productDescription = '';
        $methodId = 11; // Bizum

        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);


        $amount = $currency='';
        $amount = round($order->getBaseGrandTotal() * 100);
        $currency = $order->getBaseCurrencyCode();

        $userInteraction = 1;
        $secure_pay = 1;

        $payment = [
            'terminal' => $terminal,
            'methods' => [$methodId],
            'order' => $order_id,
            'amount' => $amount,
            'currency' => $currency,
            'userInteraction' => $userInteraction,
            'secure' => $secure_pay,
            'urlOk' => $URLOK,
            'urlKo' => $URLKO
        ];

        $subscription = array();

        $params = [
            "operationType"         => 1,
            "language"              => (string) $language,
            "terminal"              => (int) $terminal,
            "productDescription"    => (string) $productDescription,
            "payment"               => (array) $payment,
            "subscription"          => (array) $subscription
        ];


        $apiResponse = $this->executeRequest('/v1/form', $params);
        if($apiResponse->errorCode == '0') {
            $url = $apiResponse->challengeUrl;
        } else {
            $message = Mage::helper('payment')->__('Error %s - %s', $apiResponse->errorCode, $this->getErrorDesc($apiResponse->errorCode));
            throw new Mage_Payment_Model_Info_Exception($message);
        }


        return $url;
    }

    public function calcLanguage($lan)
    {
        $res = "";
        switch ($lan) {
            case "es_ES":
                return "es";
            case "fr_FR":
                return "fr";
            case "en_GB":
                return "en";
            case "en_US":
                return "en";
            case "it_IT":
                return "it";
            case "de_DE":
                return "de";
            case "pt_PT":
                return "pt";
        }
        return "es";
    }

    private function executeRefund(Varien_Object $payment,$amount)
    {
        $order = $payment->getOrder();

        $fecha = date("Ymd",strtotime($order->getCreatedAt()));

        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_CURRENCY = $order->getBaseCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_AUTHCODE = $payment->getLastTransId();
        $DS_ORIGINAL_IP = $order->getRemoteIp();
        $DS_MERCHANT_AMOUNT = round($amount * 100);

        $notifyDirectPayment = 2;

        $params = [
            "payment" => [
                'terminal'              => (int) $DS_MERCHANT_TERMINAL,
                'amount'                => (string) $DS_MERCHANT_AMOUNT,
                'currency'              => (string) $DS_MERCHANT_CURRENCY,
                'authCode'              => (string) $DS_MERCHANT_AUTHCODE,
                'originalIp'            => (string) $DS_ORIGINAL_IP,
                'notifyDirectPayment'   => (int) $notifyDirectPayment
            ]
        ];

        $executeRefundReponse = $this->executeRequest('/v1/payments/' . $DS_MERCHANT_ORDER . '/refund', $params);

        $result["DS_RESPONSE"] = ($executeRefundReponse->errorCode > 0) ? 0 : 1;
        $result["DS_ERROR_ID"] = $executeRefundReponse->errorCode;

        if ($executeRefundReponse->errorCode == 0) {
            $result['DS_MERCHANT_AUTHCODE'] = $executeRefundReponse->authCode;
        }

        return $result;
    }


    public function refund(Varien_Object $payment, $amount)
    {
        parent::refund($payment, $amount);

        $res = $this->executeRefund($payment,$amount);

        if (('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) && 1 == $res['DS_RESPONSE']) {
             $refundTransactionId = $res['DS_MERCHANT_AUTHCODE'];
             $payment->setTransactionId($refundTransactionId);
             $payment->resetTransactionAdditionalInfo();
             $payment->setData('is_transaction_closed',0);
             $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
        } else {
            if (!isset($res['DS_ERROR_ID']))
                $res['DS_ERROR_ID'] = -1;
            $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }
        return $this;
    } //refund api


    public function getCode() {
        return $this->_code;
    }

    public function getErrorDesc($code)
    {
        $paytpv_error_codes = array(
            '1' => Mage::helper('payment')->__('Error'),
            '100' => Mage::helper('payment')->__('Expired Card'),
            '101' => Mage::helper('payment')->__('Blacklisted Card'),
            '102' => Mage::helper('payment')->__('Operation not allowed for the type of card'),
            '103' => Mage::helper('payment')->__('Please contact the issuing bank'),
            '104' => Mage::helper('payment')->__('Unexpected error'),
            '105' => Mage::helper('payment')->__('Insufficient credit for the post'),
            '106' => Mage::helper('payment')->__('Card not discharged or not registered by the issuing bank'),
            '107' => Mage::helper('payment')->__('Format error in the captured data. CodValid'),
            '108' => Mage::helper('payment')->__('Error card number'),
            '109' => Mage::helper('payment')->__('Error in ExpireDate'),
            '110' => Mage::helper('payment')->__('Data error'),
            '111' => Mage::helper('payment')->__('Block CVC2 wrong'),
            '112' => Mage::helper('payment')->__('Please contact the issuing bank'),
            '113' => Mage::helper('payment')->__('Credit card not valid'),
            '114' => Mage::helper('payment')->__('The credit card has restrictions'),
            '115' => Mage::helper('payment')->__('The card issuer could not identify the owner'),
            '116' => Mage::helper('payment')->__('Payment not allowed in off-line'),
            '118' => Mage::helper('payment')->__('Card expired. Please retain the card physically'),
            '119' => Mage::helper('payment')->__('Blacklisted Card. Please retain the card physically'),
            '120' => Mage::helper('payment')->__('Lost or stolen card. Please retain the card physically'),
            '121' => Mage::helper('payment')->__('CVC2 error. Please retain the card physically'),
            '122' => Mage::helper('payment')->__('Error in pre-transaction process. Please try again later'),
            '123' => Mage::helper('payment')->__('Operation denied. Please retain the card physically'),
            '124' => Mage::helper('payment')->__('Closure agreement'),
            '125' => Mage::helper('payment')->__('Close without agreement'),
            '126' => Mage::helper('payment')->__('Unable to close at this time'),
            '127' => Mage::helper('payment')->__('Invalid parameter'),
            '128' => Mage::helper('payment')->__('The transactions were not completed'),
            '129' => Mage::helper('payment')->__('Internal reference duplicate'),
            '130' => Mage::helper('payment')->__('Previous operation not found. Failed to execute the return'),
            '131' => Mage::helper('payment')->__('Preauthorization Expired'),
            '132' => Mage::helper('payment')->__('Invalid operation in current currency'),
            '133' => Mage::helper('payment')->__('Error message format'),
            '134' => Mage::helper('payment')->__('Message not recognized by the system'),
            '135' => Mage::helper('payment')->__('Block CVC2 wrong'),
            '137' => Mage::helper('payment')->__('Card not valid'),
            '138' => Mage::helper('payment')->__('Gateway error message'),
            '139' => Mage::helper('payment')->__('Error gateway format'),
            '140' => Mage::helper('payment')->__('Nonexistent Card'),
            '141' => Mage::helper('payment')->__('Number zero or invalid'),
            '142' => Mage::helper('payment')->__('Operation canceled'),
            '143' => Mage::helper('payment')->__('Authentication Error'),
            '144' => Mage::helper('payment')->__('Denied due to security level'),
            '145' => Mage::helper('payment')->__('PUC error message. Contact PAYCOMET'),
            '146' => Mage::helper('payment')->__('System Error'),
            '147' => Mage::helper('payment')->__('Duplicate transaction'),
            '148' => Mage::helper('payment')->__('MAC Error'),
            '149' => Mage::helper('payment')->__('Settlement rejected'),
            '150' => Mage::helper('payment')->__('Date / time unsynchronized system'),
            '151' => Mage::helper('payment')->__('Expiry date invalid'),
            '152' => Mage::helper('payment')->__('Could not find the preauthorization'),
            '153' => Mage::helper('payment')->__('Unable to find the requested data'),
            '154' => Mage::helper('payment')->__('Can not perform the operation with the credit card provided'),
            '500' => Mage::helper('payment')->__('Unexpected error'),
            '501' => Mage::helper('payment')->__('Unexpected error'),
            '502' => Mage::helper('payment')->__('Unexpected error'),
            '504' => Mage::helper('payment')->__('Transaction canceled previously'),
            '505' => Mage::helper('payment')->__('Original transaction denied'),
            '506' => Mage::helper('payment')->__('Invalid confirmation data'),
            '507' => Mage::helper('payment')->__('Unexpected error'),
            '508' => Mage::helper('payment')->__('Transaction still being'),
            '509' => Mage::helper('payment')->__('Unexpected error'),
            '510' => Mage::helper('payment')->__('Unable to return'),
            '511' => Mage::helper('payment')->__('Unexpected error'),
            '512' => Mage::helper('payment')->__('Unable to contact the issuing bank. Please try again later'),
            '513' => Mage::helper('payment')->__('Unexpected error'),
            '514' => Mage::helper('payment')->__('Unexpected error'),
            '515' => Mage::helper('payment')->__('Unexpected error'),
            '516' => Mage::helper('payment')->__('Unexpected error'),
            '517' => Mage::helper('payment')->__('Unexpected error'),
            '518' => Mage::helper('payment')->__('Unexpected error'),
            '519' => Mage::helper('payment')->__('Unexpected error'),
            '520' => Mage::helper('payment')->__('Unexpected error'),
            '521' => Mage::helper('payment')->__('Unexpected error'),
            '522' => Mage::helper('payment')->__('Unexpected error'),
            '523' => Mage::helper('payment')->__('Unexpected error'),
            '524' => Mage::helper('payment')->__('Unexpected error'),
            '525' => Mage::helper('payment')->__('Unexpected error'),
            '526' => Mage::helper('payment')->__('Unexpected error'),
            '527' => Mage::helper('payment')->__('Transaction Type unknown'),
            '528' => Mage::helper('payment')->__('Unexpected error'),
            '529' => Mage::helper('payment')->__('Unexpected error'),
            '530' => Mage::helper('payment')->__('Unexpected error'),
            '531' => Mage::helper('payment')->__('Unexpected error'),
            '532' => Mage::helper('payment')->__('Unexpected error'),
            '533' => Mage::helper('payment')->__('Unexpected error'),
            '534' => Mage::helper('payment')->__('Unexpected error'),
            '535' => Mage::helper('payment')->__('Unexpected error'),
            '536' => Mage::helper('payment')->__('Unexpected error'),
            '537' => Mage::helper('payment')->__('Unexpected error'),
            '538' => Mage::helper('payment')->__('Operation not cancelable'),
            '539' => Mage::helper('payment')->__('Unexpected error'),
            '540' => Mage::helper('payment')->__('Unexpected error'),
            '541' => Mage::helper('payment')->__('Unexpected error'),
            '542' => Mage::helper('payment')->__('Unexpected error'),
            '543' => Mage::helper('payment')->__('Unexpected error'),
            '544' => Mage::helper('payment')->__('Unexpected error'),
            '545' => Mage::helper('payment')->__('Unexpected error'),
            '546' => Mage::helper('payment')->__('Unexpected error'),
            '547' => Mage::helper('payment')->__('Unexpected error'),
            '548' => Mage::helper('payment')->__('Unexpected error'),
            '549' => Mage::helper('payment')->__('Unexpected error'),
            '550' => Mage::helper('payment')->__('Unexpected error'),
            '551' => Mage::helper('payment')->__('Unexpected error'),
            '552' => Mage::helper('payment')->__('Unexpected error'),
            '553' => Mage::helper('payment')->__('Unexpected error'),
            '554' => Mage::helper('payment')->__('Unexpected error'),
            '555' => Mage::helper('payment')->__('Could not find the previous operation'),
            '556' => Mage::helper('payment')->__('Inconsistency in the validation data of the cancellation'),
            '557' => Mage::helper('payment')->__('The deferred payment there'),
            '558' => Mage::helper('payment')->__('Unexpected error'),
            '559' => Mage::helper('payment')->__('Unexpected error'),
            '560' => Mage::helper('payment')->__('Unexpected error'),
            '561' => Mage::helper('payment')->__('Unexpected error'),
            '562' => Mage::helper('payment')->__('Card does not allow pre-authorizations'),
            '563' => Mage::helper('payment')->__('Confirmation data inconsistency'),
            '564' => Mage::helper('payment')->__('Unexpected error'),
            '565' => Mage::helper('payment')->__('Unexpected error'),
            '567' => Mage::helper('payment')->__('Undefined return operation correctly'),
            '569' => Mage::helper('payment')->__('Operation denied'),
            '1000' => Mage::helper('payment')->__('Account not found. Review your settings'),
            '1001' => Mage::helper('payment')->__('User not found. Contact PAYCOMET'),
            '1002' => Mage::helper('payment')->__('Gateway error response. Contact PAYCOMET'),
            '1003' => Mage::helper('payment')->__('Invalid signature. Please check your settings'),
            '1004' => Mage::helper('payment')->__('Access not allowed'),
            '1005' => Mage::helper('payment')->__('Credit Card Format Invalid'),
            '1006' => Mage::helper('payment')->__('Error Validation Code field'),
            '1007' => Mage::helper('payment')->__('Error in the Expiration Date field'),
            '1008' => Mage::helper('payment')->__('Preauthorization reference not found'),
            '1009' => Mage::helper('payment')->__('Preauthorization Data not found'),
            '1010' => Mage::helper('payment')->__('Could not send the return. Please try again later'),
            '1011' => Mage::helper('payment')->__('Could not connect to host'),
            '1012' => Mage::helper('payment')->__('Could not resolve the proxy'),
            '1013' => Mage::helper('payment')->__('Failed host resolve'),
            '1014' => Mage::helper('payment')->__('Initialization failed'),
            '1015' => Mage::helper('payment')->__('No resource found HTTP'),
            '1016' => Mage::helper('payment')->__('The range of options is not valid for the HTTP transfer'),
            '1017' => Mage::helper('payment')->__('No POST properly constructed'),
            '1018' => Mage::helper('payment')->__('The user name is not well formatted'),
            '1019' => Mage::helper('payment')->__('Timed out waiting for the request'),
            '1020' => Mage::helper('payment')->__('Out of Memory'),
            '1021' => Mage::helper('payment')->__('Could not connect to SSL server'),
            '1022' => Mage::helper('payment')->__('Protocol not supported'),
            '1023' => Mage::helper('payment')->__('The given URL is not properly formatted and can not be used'),
            '1024' => Mage::helper('payment')->__('The user in the URL is improperly formatted'),
            '1025' => Mage::helper('payment')->__('Could not register any resources available to complete the operation'),
            '1026' => Mage::helper('payment')->__('Duplicate xref'),
            '1027' => Mage::helper('payment')->__('The total return can not exceed the original transaction'),
            '1028' => Mage::helper('payment')->__('The account is not active. Contact PAYCOMET'),
            '1029' => Mage::helper('payment')->__('The account is not certified. Contact PAYCOMET'),
            '1030' => Mage::helper('payment')->__('The product is marked for deletion and can not be used'),
            '1031' => Mage::helper('payment')->__('Insufficient permissions'),
            '1032' => Mage::helper('payment')->__('The product can not be used in the test environment'),
            '1033' => Mage::helper('payment')->__('The product can not be used in the production environment'),
            '1034' => Mage::helper('payment')->__('Unable to send the request back'),
            '1035' => Mage::helper('payment')->__('Error in the source IP field of the operation'),
            '1036' => Mage::helper('payment')->__('Error in XML'),
            '1037' => Mage::helper('payment')->__('The root element is not correct'),
            '1038' => Mage::helper('payment')->__('Field Ds_Merchant_Amount wrong'),
            '1039' => Mage::helper('payment')->__('Field Ds_Merchant_Order wrong'),
            '1040' => Mage::helper('payment')->__('Field DS_MERCHANT_MERCHANTCODE wrong'),
            '1041' => Mage::helper('payment')->__('Field DS_MERCHANT_CURRENCY wrong'),
            '1042' => Mage::helper('payment')->__('Field DS_MERCHANT_PAN wrong'),
            '1043' => Mage::helper('payment')->__('Field DS_MERCHANT_CVV2 wrong'),
            '1044' => Mage::helper('payment')->__('Field Ds_Merchant_TransactionType wrong'),
            '1045' => Mage::helper('payment')->__('Field DS_MERCHANT_TERMINAL wrong'),
            '1046' => Mage::helper('payment')->__('Field DS_MERCHANT_EXPIRYDATE wrong'),
            '1047' => Mage::helper('payment')->__('Field DS_MERCHANT_MERCHANTSIGNATURE wrong'),
            '1048' => Mage::helper('payment')->__('Field DS_ORIGINAL_IP wrong'),
            '1049' => Mage::helper('payment')->__('Customer not found'),
            '1050' => Mage::helper('payment')->__('The new amount can not exceed pre-authorize the amount of the original pre-authorization'),
            '1099' => Mage::helper('payment')->__('Unexpected error'),
            '1100' => Mage::helper('payment')->__('Exceeded the daily limit per card'),
            '1103' => Mage::helper('payment')->__('ACCOUNT field error'),
            '1104' => Mage::helper('payment')->__('USERCODE field error'),
            '1105' => Mage::helper('payment')->__('TERMINAL field error'),
            '1106' => Mage::helper('payment')->__('OPERATION field error'),
            '1107' => Mage::helper('payment')->__('REFERENCE field error'),
            '1108' => Mage::helper('payment')->__('AMOUNT field error'),
            '1109' => Mage::helper('payment')->__('CURRENCY field error'),
            '1110' => Mage::helper('payment')->__('SIGNATURE field error'),
            '1120' => Mage::helper('payment')->__('Operation unavailable'),
            '1121' => Mage::helper('payment')->__('Customer not found'),
            '1122' => Mage::helper('payment')->__('User not found. Contact PAYCOMET'),
            '1123' => Mage::helper('payment')->__('Invalid signature. Please check your settings'),
            '1124' => Mage::helper('payment')->__('Operation unavailable to the user specified'),
            '1125' => Mage::helper('payment')->__('Invalid operation with a currency other than the Euro'),
            '1127' => Mage::helper('payment')->__('Number zero or invalid'),
            '1128' => Mage::helper('payment')->__('Current currency conversion invalid'),
            '1129' => Mage::helper('payment')->__('Invalid Quantity'),
            '1130' => Mage::helper('payment')->__('Product not found'),
            '1131' => Mage::helper('payment')->__('Invalid operation in current currency'),
            '1132' => Mage::helper('payment')->__('Invalid operation with a different article of the Euro currency'),
            '1133' => Mage::helper('payment')->__('Info Button corrupt'),
            '1134' => Mage::helper('payment')->__('The subscription can not be greater than the expiry date of the card'),
            '1135' => Mage::helper('payment')->__('DS_EXECUTE can not be true if DS_SUBSCRIPTION_STARTDATE is different today.'),
            '1136' => Mage::helper('payment')->__('PAYTPV_OPERATIONS_MERCHANTCODE field error'),
            '1137' => Mage::helper('payment')->__('PAYTPV_OPERATIONS_TERMINAL should be Array'),
            '1138' => Mage::helper('payment')->__('PAYTPV_OPERATIONS_OPERATIONS should be Array'),
            '1139' => Mage::helper('payment')->__('PAYTPV_OPERATIONS_SIGNATURE field error'),
            '1140' => Mage::helper('payment')->__('It is one of the PAYTPV_OPERATIONS_TERMINAL'),
            '1141' => Mage::helper('payment')->__('Error on the requested date range'),
            '1142' => Mage::helper('payment')->__('The application can not have an interval greater than 2 years'),
            '1143' => Mage::helper('payment')->__('The status of the operation is incorrect'),
            '1144' => Mage::helper('payment')->__('Error in the amounts of search'),
            '1145' => Mage::helper('payment')->__('The type of operation requested does not exist'),
            '1146' => Mage::helper('payment')->__('Sort Order unrecognized'),
            '1147' => Mage::helper('payment')->__('Invalid PAYTPV_OPERATIONS_SORTORDER'),
            '1148' => Mage::helper('payment')->__('Subscription start date wrong'),
            '1149' => Mage::helper('payment')->__('Subscription end date wrong'),
            '1150' => Mage::helper('payment')->__('Error in the periodicity of the subscription'),
            '1151' => Mage::helper('payment')->__('Invalid usuarioXML '),
            '1152' => Mage::helper('payment')->__('Invalid codigoCliente'),
            '1153' => Mage::helper('payment')->__('Invalid usuarios parameter'),
            '1154' => Mage::helper('payment')->__('Invalid firma parameter'),
            '1155' => Mage::helper('payment')->__('Invalid usuarios parameter format'),
            '1156' => Mage::helper('payment')->__('Invalid type'),
            '1157' => Mage::helper('payment')->__('Invalid name'),
            '1158' => Mage::helper('payment')->__('Invalid surname'),
            '1159' => Mage::helper('payment')->__('Invalid email'),
            '1160' => Mage::helper('payment')->__('Invalid password'),
            '1161' => Mage::helper('payment')->__('Invalid language'),
            '1162' => Mage::helper('payment')->__('Invalid maxamount '),
            '1163' => Mage::helper('payment')->__('Invalid multicurrency'),
            '1165' => Mage::helper('payment')->__('Invalid permissions_specs. Format not allowed'),
            '1166' => Mage::helper('payment')->__('Invalid permissions_products. Format not allowed'),
            '1167' => Mage::helper('payment')->__('Invalid email. Format not allowed'),
            '1168' => Mage::helper('payment')->__('Weak or invalid password'),
            '1169' => Mage::helper('payment')->__('Invalid value for type parameter'),
            '1170' => Mage::helper('payment')->__('Invalid value for language parameter'),
            '1171' => Mage::helper('payment')->__('Invalid format for maxamount parameter'),
            '1172' => Mage::helper('payment')->__('Invalid multicurrency. Format not allowed'),
            '1173' => Mage::helper('payment')->__('Invalid permission_id – permissions_specs. Not allowed'),
            '1174' => Mage::helper('payment')->__('Invalid user'),
            '1175' => Mage::helper('payment')->__('Invalid credentials'),
            '1176' => Mage::helper('payment')->__('Account not found'),
            '1177' => Mage::helper('payment')->__('User not found'),
            '1178' => Mage::helper('payment')->__('Invalid signature'),
            '1179' => Mage::helper('payment')->__('Account without products'),
            '1180' => Mage::helper('payment')->__('Invalid product_id - permissions_products. Not allowed'),
            '1181' => Mage::helper('payment')->__('Invalid permission_id -permissions_products. Not allowed'),
            '1185' => Mage::helper('payment')->__('Minimun limit not allowed'),
            '1186' => Mage::helper('payment')->__('Maximun limit not allowed'),
            '1187' => Mage::helper('payment')->__('Daily limit not allowed'),
            '1188' => Mage::helper('payment')->__('Monthly limit not allowed'),
            '1189' => Mage::helper('payment')->__('Max amount (same card / last 24 h.) not allowed'),
            '1190' => Mage::helper('payment')->__('Max amount (same card / last 24 h. / same IP address) not allowed'),
            '1191' => Mage::helper('payment')->__('Day / IP address limit (all cards) not allowed'),
            '1192' => Mage::helper('payment')->__('Country (merchant IP address) not allowed'),
            '1193' => Mage::helper('payment')->__('Card type (credit / debit) not allowed'),
            '1194' => Mage::helper('payment')->__('Card brand not allowed'),
            '1195' => Mage::helper('payment')->__('Card Category not allowed'),
            '1196' => Mage::helper('payment')->__('Authorization from different country than card issuer, not allowed'),
            '1197' => Mage::helper('payment')->__('Denied. Filter: Card country issuer not allowed'),
            '1200' => Mage::helper('payment')->__('Denied. Filter: same card, different country last 48 h.'),
            '1201' => Mage::helper('payment')->__('Number of erroneous consecutive attempts with the same card exceeded'),
            '1202' => Mage::helper('payment')->__('Number of failed attempts (last 30 minutes) from the same ip address exceeded'),
            '1203' => Mage::helper('payment')->__('Wrong or not configured PayPal credentials'),
            '1204' => Mage::helper('payment')->__('Incorrect token received'),
            '1205' => Mage::helper('payment')->__('Can not perform the operation'),
            '1206' => Mage::helper('payment')->__('providerID not available'),
            '1207' => Mage::helper('payment')->__('operations parameter missing or not in a correct format'),
            '1208' => Mage::helper('payment')->__('paytpvMerchant parameter missing'),
            '1209' => Mage::helper('payment')->__('merchatID parameter missing'),
            '1210' => Mage::helper('payment')->__('terminalID parameter missing'),
            '1211' => Mage::helper('payment')->__('tpvID parameter missing'),
            '1212' => Mage::helper('payment')->__('operationType parameter missing'),
            '1213' => Mage::helper('payment')->__('operationResult parameter missing'),
            '1214' => Mage::helper('payment')->__('operationAmount parameter missing'),
            '1215' => Mage::helper('payment')->__('operationCurrency parameter missing'),
            '1216' => Mage::helper('payment')->__('operationDatetime parameter missing'),
            '1217' => Mage::helper('payment')->__('originalAmount parameter missing'),
            '1218' => Mage::helper('payment')->__('pan parameter missing'),
            '1219' => Mage::helper('payment')->__('expiryDate parameter missing'),
            '1220' => Mage::helper('payment')->__('reference parameter missing'),
            '1221' => Mage::helper('payment')->__('signature parameter missing'),
            '1222' => Mage::helper('payment')->__('originalIP parameter missing or not in a correct format'),
            '1223' => Mage::helper('payment')->__('authcode / errorCode parameter missing'),
            '1224' => Mage::helper('payment')->__('Product of the operation missing'),
            '1225' => Mage::helper('payment')->__('The type of operation is not supported'),
            '1226' => Mage::helper('payment')->__('The result of the operation is not supported'),
            '1227' => Mage::helper('payment')->__('The transaction currency is not supported'),
            '1228' => Mage::helper('payment')->__('The date of the transaction is not in a correct format'),
            '1229' => Mage::helper('payment')->__('The signature is not correct'),
            '1230' => Mage::helper('payment')->__('Can not find the associated account information'),
            '1231' => Mage::helper('payment')->__('Can not find the associated product information'),
            '1232' => Mage::helper('payment')->__('Can not find the associated user information'),
            '1233' => Mage::helper('payment')->__('The product is not set as multicurrency'),
            '1234' => Mage::helper('payment')->__('The amount of the transaction is not in a correct format'),
            '1235' => Mage::helper('payment')->__('The original amount of the transaction is not in a correct format'),
            '1236' => Mage::helper('payment')->__('The card does not have the correct format'),
            '1237' => Mage::helper('payment')->__('The expiry date of the card is not in a correct format'),
            '1238' => Mage::helper('payment')->__('Can not initialize the service'),
            '1239' => Mage::helper('payment')->__('Can not initialize the service'),
            '1240' => Mage::helper('payment')->__('Method not implemented'),
            '1241' => Mage::helper('payment')->__('Can not initialize the service'),
            '1242' => Mage::helper('payment')->__('Service can not be completed'),
            '1243' => Mage::helper('payment')->__('operationCode parameter missing'),
            '1244' => Mage::helper('payment')->__('bankName parameter missing'),
            '1245' => Mage::helper('payment')->__('csb parameter missing'),
            '1246' => Mage::helper('payment')->__('userReference parameter missing'),
            '1247' => Mage::helper('payment')->__('Can not find the associated FUC'),
            '666' => Mage::helper('payment')->__('Missing Credit Card information'),
            '4000' => Mage::helper('payment')->__('Test mode does not support returns orders placed in Real Mode'),
            '4001' => Mage::helper('payment')->__('Test Card is invalid'),
            '4002' => Mage::helper('payment')->__('Test mode does not support confirm preathorize orders placed in Real Mode'),
            '4003' => Mage::helper('payment')->__('Test mode does not support cancel suscription placed in Real Mode'),
        );
        return $paytpv_error_codes[$code];
    }

}
