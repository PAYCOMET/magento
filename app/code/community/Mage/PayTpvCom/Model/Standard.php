<?php

/**
 * Our test CC module adapter
 */
class Mage_PayTpvCom_Model_Standard extends Mage_Payment_Model_Method_Abstract implements Mage_Payment_Model_Recurring_Profile_MethodInterface
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
    protected $_code = 'paytpvcom';
    protected $_formBlockType = 'paytpvcom/standard_form';
    protected $_allowCurrencyCode = array('EUR', 'USD', 'GBP', 'JPY');
    protected $_canAuthorize = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canCapture = true;
    protected $_canUseInternal = false; //Payments from backend
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded = false;

    private $_client = null;
    private $_clientoperation = null;


    const SALE = 0;
    const PREAUTHORIZATION = 1;

    protected $_arrCustomerCards = null;
    protected $_arrCustomerSuscriptions = null;

    protected function _construct(){

       $this->_init("paytpvcom/paytpvcom");

    }


    

    /**
     * API instance getter
     * Sets current store id to current config instance and passes it to API
     *
     * @return Mage_Paytpvcom_Model_Api
     */
    public function getApi()
    {
        if (null === $this->_api) {
            $this->_api = Mage::getModel($this->_apiType);
        }
        $this->_api->setConfigObject($this->_config);
        return $this->_api;
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
     * Get checkout session namespace
     *
     * @return Checkout_Model_Session
     */
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
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

    public function createFormBlock($name)
    {
        $block = $this->getLayout()->createBlock('paytpvcom/form', $name);
        $block->setMethod('paytpvcom')
            ->setPayment($this->getPayment())
            ->setTemplate('paytpvcom/form.phtml');
        return $block;
    }

    public function isRecurring()
    {
        $quote = Mage::getModel('checkout/cart')->getQuote();
        foreach ($quote->getAllItems() as $item) {
            if (!$item->getProduct()->getIsRecurring())
                return false;
        }
        return true;
    }

    public function canUseForCurrency($currencyCode)
    {
       
        if (!in_array($currencyCode, $this->_allowCurrencyCode)) {
            return false;
        }
        return true;
    }

     /* validate the currency code is avaialable to use for paytpvcom or not */

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

    public function setCustomerCards($arrCards){
        $this->_arrCustomerCards = $arrCards;
    }

    public function setCustomerSuscriptions($arrSuscriptions){
        $this->_arrCustomerSuscriptions = $arrSuscriptions;
    }

    public function getCustomerCards(){
        return $this->_arrCustomerCards;
    }

    private function useIframe(){
        return $this->isSecureTransaction();
    }


    public function _getValidParamKey($key)
    {
        if (substr($key, 0, 10) !== 'paytpv3ds_')
            return null;
        
        return substr($key, 10);
    }
    
    public function new_page_payment()
    {
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;

        return ($this->getConfigData('paytpviframe')=="1" && $payment_data_card==0)?true:false;
    }


    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        
        // Set order status when placed
        if ('order_status' == $field) {
            // If Secure or Payment in new Page -> Set order status as Pending_Payment
            return ($this->isSecureTransaction() || $this->new_page_payment())? Mage_Sales_Model_Order::STATE_PENDING_PAYMENT : Mage_Sales_Model_Order::STATE_PROCESSING;
        }
        
        if ('payment_action' == $field) {

            $terminales = $this->getConfigData('terminales');
            $transaction_type = $this->getConfigData('transaction_type');

            // Pago en Nueva Pagina
            if ($this->new_page_payment()) {
                $paymentInfo = $this->getInfoInstance();
                $order = $paymentInfo->getOrder();
                $remembercardselected = ($this->getConfigData('remembercardselected'))?1:0;
                $order->setPaytpvSavecard($remembercardselected); // Save Card as default
                return '';
            // Only Authorize
            } else if ($this->isSecureTransaction() || ($transaction_type==self::PREAUTHORIZATION)) {
                return 'authorize';
            // Autorize + Capture
            } else {
                return 'authorize_capture';
            }
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;

        return Mage::getStoreConfig($path, $storeId);
    }

    public function authorize(Varien_Object $payment, $amount)
    {

        // IWD Checkout Compatibility
        $sess = Mage::getSingleton('checkout/session');
        $processedOPC   = $sess->getProcessedOPC();
        if($processedOPC == 'opc')  $sess->setProcessedOPC('paytpv_opc');

        // Score
        $sess->setPaytpvOriginalIp($this->getOriginalIp());

        $order = $payment->getOrder();
        $amount = $order->getBaseGrandTotal();

        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        $card = array();

        $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;

        $remember = (isset($payment_data["remember"]) && $payment_data_card==0)?1:0;

        $Secure = ($this->isSecureTransaction())?1:0;

        // Mark as Payment Review when is Secure Payment --> 3D Secure
        $payment->setIsTransactionPending(true);

        // PAYCOMET iframe: When Card=0
        if ($this->getConfigData('paytpviframe')=="1" && $payment_data_card==0) {
            $order->setPaytpvSavecard($remember);
            $order->save();
            return;
        }

        parent::authorize($payment, $amount);

        // NUEVA TARJETA o SUSCRIPCION
        if ($payment_data_card==0) {
            if (isset($payment_data['cc_number']) || $payment_data['paytpvToken']) {
                $res = $this->addUser($payment_data);
               
                $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
                $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
                $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';

                if ((int)$DS_ERROR_ID == 0) {
                    $card["paytpv_iduser"] = $DS_IDUSER;
                    $card["paytpv_tokenuser"] = $DS_TOKEN_USER;
                }
            } 
            if ((int)$DS_ERROR_ID != 0) {
                if (!isset($res['DS_ERROR_ID']))
                    $res['DS_ERROR_ID'] = 666;
                $message = Mage::helper('payment')->__('Authorization failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                throw new Mage_Payment_Model_Info_Exception($message);
            }
        // TARJETA EXISTENTE
        } else {
            if ($this->getConfigData('commerce_password') && !$this->verifyPwd($payment_data["userpwd"])) {
                $message = Mage::helper('payment')->__('Payment failed. Contraseña incorrecta');
                throw new Mage_Payment_Model_Info_Exception($message);
            }
            $paytpv_iduser =  $payment_data_card;
            $card = $this->getToken($paytpv_iduser);
            if (!isset($card["paytpv_iduser"])) {
                $message = Mage::helper('payment')->__($this->getErrorDesc(110), $this->getErrorDesc(110));
                throw new Mage_Payment_Model_Info_Exception($message);
            }
        }

        if (isset($card["paytpv_iduser"], $card["paytpv_tokenuser"])) {
            $order->setPaytpvIduser($card["paytpv_iduser"])
                  ->setPaytpvTokenuser($card["paytpv_tokenuser"])
                  ->setPaytpvSavecard($remember);
            $order->save();
        }

        if ($this->getConfigData("payment_action")=="authorize") {
            $transaction_type = $this->getConfigData('transaction_type');
            if (!$Secure && $transaction_type == self::PREAUTHORIZATION) {
                $res = $this->create_preauthorization($order, $amount);
                if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {                
                   
                    $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE'])
                        ->setCurrencyCode($order->getBaseCurrencyCode())
                        ->setIsTransactionPending(false)
                        ->setIsTransactionClosed(0)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);


                } else {
                    if (!isset($res['DS_ERROR_ID']))
                        $res['DS_ERROR_ID'] = -1;
                    $message = Mage::helper('payment')->__('Preathorization failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                    throw new Mage_Payment_Model_Info_Exception($message);
                }
            }
            return $this;
        }

        return $card;
    }


    public function capture(Varien_Object $payment, $amount)
    {

        $order = $payment->getOrder();
        $amount = $order->getBaseGrandTotal();
        if ($this->_isPreauthorizeCapture($payment)) {
            $this->_preauthorizeCapture($payment, $amount);
        } else {
            parent::capture($payment, $amount);
            
            $customer_id = $order->getCustomerId();
            $customer = Mage::getModel('customer/customer')->load($customer_id);
            $payment_data = Mage::app()->getRequest()->getParam('payment', array());

            $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;

            $Secure = ($this->isSecureTransaction())?1:0;

            switch ($Secure) {
                // PAGO NO SEGURO
                case 0:
                    $card = $this->authorize($payment, 0);

                    $res = $this->executePurchase($order);

                    if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {
                        $DS_IDUSER = $order->getPaytpvIduser();
                        $DS_TOKEN_USER = $order->getPaytpvTokenuser();

                        $remember = (isset($payment_data["remember"]) && $payment_data_card==0)?1:0;
                        // Si es un pago NO Seguro, ha pulsado en el acuerdo, es una tarjeta nueva y es un usuario registrado guardamos el token
                        if ($remember && $order->getCustomerId()>0){
                            $result = $this->infoUser($DS_IDUSER,$DS_TOKEN_USER);
                            $card = $this->save_card($DS_IDUSER,$DS_TOKEN_USER,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$order->getCustomerId());
                        }

                        $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE'])
                        ->setCurrencyCode($order->getBaseCurrencyCode())
                        ->setPreparedMessage("PAYCOMET Pago Correcto")
                        ->setIsTransactionPending(false)
                        ->setIsTransactionClosed(1)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);


                        // Obtener CardBrand y BicCode
                        if ($this->getConfigData('operationcall')==1) {         
                            $res = $this->operationCall($order);
                            if ('' == $res[0]->PAYTPV_ERROR_ID || 0 == $res[0]->PAYTPV_ERROR_ID) {
                                $CardBrand = $res[0]->PAYTPV_OPERATION_CARDBRAND;
                                $BicCode =  $res[0]->PAYTPV_OPERATION_BICCODE;
                                $order->setPaytpvCardBrand($CardBrand)
                                ->setPaytpvBicCode($BicCode);
                            }
                        }

                    } else {
                        if (!isset($res['DS_ERROR_ID']))
                            $res['DS_ERROR_ID'] = -1;
                        $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                        throw new Mage_Payment_Model_Info_Exception($message);
                    }
                break;
            }
        }
        return $this;
    }


    public function _isPreauthorizeCapture($payment)
    {
        $idtran = (int)$payment->getTransactionId();
        $lastTransaction = $payment->getTransaction($idtran);
        if (!$lastTransaction || $lastTransaction->getTxnType() != Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH)
            return false;
        return true;
    }

    public function _preauthorizeCapture($payment, $amount)
    {
        $order = $payment->getOrder();
        $res = $this->preauthorization_confirm($order, $amount);
       
        if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {
            $DS_IDUSER = $order->getPaytpvIduser();
            $DS_TOKEN_USER = $order->getPaytpvTokenuser();
            $payment_data = Mage::app()->getRequest()->getParam('payment', array());
            $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;
            
            $remember = (isset($payment_data["remember"]) && $payment_data_card==0)?1:0;
            // Si es un pago NO seguro, ha pulsado en el acuerdo y es un usuario registrado guardamos el token
            if ($remember && $order->getCustomerId()>0) {
                $result = $this->infoUser($DS_IDUSER,$DS_TOKEN_USER);
                $card = $this->save_card($DS_IDUSER,$DS_TOKEN_USER,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$order->getCustomerId());
            }

            $transaction_id = (int)$payment->getTransactionId();
            $payment->setIsTransactionClosed(1);
            $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE']);
            $payment->setData('parent_transaction_id',$transaction_id);
            $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
            
            $payment->unsLastTransId();

            // Obtener CardBrand y BicCode
            if ($this->getConfigData('operationcall')==1) {          
                $res = $this->operationCall($order);
                if ('' == $res[0]->PAYTPV_ERROR_ID || 0 == $res[0]->PAYTPV_ERROR_ID) {
                    $CardBrand = $res[0]->PAYTPV_OPERATION_CARDBRAND;
                    $BicCode =  $res[0]->PAYTPV_OPERATION_BICCODE;
                    $order->setPaytpvCardBrand($CardBrand)
                    ->setPaytpvBicCode($BicCode);
                }
            }
        } else {
            if (!isset($res['DS_ERROR_ID']))
                $res['DS_ERROR_ID'] = -1;
            $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }
    }

    public function verifyPwd($password)
    {
        $username = Mage::getSingleton('customer/session')->getCustomer()->getEmail();
        try {
            $blah = Mage::getModel('customer/customer')
            ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
            ->authenticate($username, $password);
            return true;
        } catch( Exception $e ) {
            return false;
        }
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

    public function preauthSuccess(&$order, $session)
    {
        $orderStatus = $this->getConfigData('paid_status');
        if ($session) {
            $session->unsErrorMessage();
            $session->addSuccess(Mage::helper('payment')->__('Successful Preauthorization'));
        }
        $comment = Mage::helper('payment')->__('Successful Preauthorization');
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



    public function getOrderPlaceRedirectUrl()
    {
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;
        $paytpviframe = $this->getConfigData('paytpviframe');

        if ($this->isSecureTransaction()) {
            // Pago Recurring
            if ($this->isRecurring())
                return Mage::getUrl('paytpvcom/standard/bankstorerecurring');
            // Pago Iframe. Si esta activada la opcion y no hay seleccionada una tarjeta
            else if ($paytpviframe && $payment_data_card==0)
                return Mage::getUrl('paytpvcom/standard/bankstoreiframe');
            // Pago Iframe Token
            else
                return Mage::getUrl('paytpvcom/standard/bankstore');
        } else {
            // Pago Iframe. Si esta activada la opcion y no hay seleccionada una tarjeta
            if ($paytpviframe && $payment_data_card==0)
                return Mage::getUrl('paytpvcom/standard/bankstoreiframe');
        }

        return null;
    }

    private function getClient()
    {
        if (null == $this->_client)
            $this->_client = new Zend_Soap_Client('https://api.paycomet.com/gateway/xml-bankstore?wsdl');
        $this->_client->setSoapVersion(SOAP_1_1);
        return $this->_client;
    }

    private function getClientOperation()
    {
        if (null == $this->_clientoperation)
            $this->_clientoperation = new Zend_Soap_Client('https://api.paycomet.com/gateway/xml-operations?wsdl');
        $this->_clientoperation->setSoapVersion(SOAP_1_1);
        return $this->_clientoperation;
    }


    public function getMerchantData($order)
    {		
        return null;
        
        $MERCHANT_EMV3DS = $this->getEMV3DS($order);
		$SHOPPING_CART = $this->getShoppingCart($order);
        
        $datos = array_merge($MERCHANT_EMV3DS,$SHOPPING_CART);        

		return urlencode(base64_encode(json_encode($datos)));
    }

    public function isoCodeToNumber($code) 
    {
		try {
		    $arrCode = array("AF" => "004", "AX" => "248", "AL" => "008", "DE" => "276", "AD" => "020", "AO" => "024", "AI" => "660", "AQ" => "010", "AG" => "028", "SA" => "682", "DZ" => "012", "AR" => "032", "AM" => "051", "AW" => "533", "AU" => "036", "AT" => "040", "AZ" => "031", "BS" => "044", "BD" => "050", "BB" => "052", "BH" => "048", "BE" => "056", "BZ" => "084", "BJ" => "204", "BM" => "060", "BY" => "112", "BO" => "068", "BQ" => "535", "BA" => "070", "BW" => "072", "BR" => "076", "BN" => "096", "BG" => "100", "BF" => "854", "BI" => "108", "BT" => "064", "CV" => "132", "KH" => "116", "CM" => "120", "CA" => "124", "QA" => "634", "TD" => "148", "CL" => "52", "CN" => "156", "CY" => "196", "CO" => "170", "KM" => "174", "KP" => "408", "KR" => "410", "CI" => "384", "CR" => "188", "HR" => "191", "CU" => "192", "CW" => "531", "DK" => "208", "DM" => "212", "EC" => "218", "EG" => "818", "SV" => "222", "AE" => "784", "ER" => "232", "SK" => "703", "SI" => "705", "ES" => "724", "US" => "840", "EE" => "233", "ET" => "231", "PH" => "608", "FI" => "246", "FJ" => "242", "FR" => "250", "GA" => "266", "GM" => "270", "GE" => "268", "GH" => "288", "GI" => "292", "GD" => "308", "GR" => "300", "GL" => "304", "GP" => "312", "GU" => "316", "GT" => "320", "GF" => "254", "GG" => "831", "GN" => "324", "GW" => "624", "GQ" => "226", "GY" => "328", "HT" => "332", "HN" => "340", "HK" => "344", "HU" => "348", "IN" => "356", "ID" => "360", "IQ" => "368", "IR" => "364", "IE" => "372", "BV" => "074", "IM" => "833", "CX" => "162", "IS" => "352", "KY" => "136", "CC" => "166", "CK" => "184", "FO" => "234", "GS" => "239", "HM" => "334", "FK" => "238", "MP" => "580", "MH" => "584", "PN" => "612", "SB" => "090", "TC" => "796", "UM" => "581", "VG" => "092", "VI" => "850", "IL" => "376", "IT" => "380", "JM" => "388", "JP" => "392", "JE" => "832", "JO" => "400", "KZ" => "398", "KE" => "404", "KG" => "417", "KI" => "296", "KW" => "414", "LA" => "418", "LS" => "426", "LV" => "428", "LB" => "422", "LR" => "430", "LY" => "434", "LI" => "438", "LT" => "440", "LU" => "442", "MO" => "446", "MK" => "807", "MG" => "450", "MY" => "458", "MW" => "454", "MV" => "462", "ML" => "466", "MT" => "470", "MA" => "504", "MQ" => "474", "MU" => "480", "MR" => "478", "YT" => "175", "MX" => "484", "FM" => "583", "MD" => "498", "MC" => "492", "MN" => "496", "ME" => "499", "MS" => "500", "MZ" => "508", "MM" => "104", "NA" => "516", "NR" => "520", "NP" => "524", "NI" => "558", "NE" => "562", "NG" => "566", "NU" => "570", "NF" => "574", "NO" => "578", "NC" => "540", "NZ" => "554", "OM" => "512", "NL" => "528", "PK" => "586", "PW" => "585", "PS" => "275", "PA" => "591", "PG" => "598", "PY" => "600", "PE" => "604", "PF" => "258", "PL" => "616", "PT" => "620", "PR" => "630", "GB" => "826", "EH" => "732", "CF" => "140", "CZ" => "203", "CG" => "178", "CD" => "180", "DO" => "214", "RE" => "638", "RW" => "646", "RO" => "642", "RU" => "643", "WS" => "882", "AS" => "016", "BL" => "652", "KN" => "659", "SM" => "674", "MF" => "663", "PM" => "666", "VC" => "670", "SH" => "654", "LC" => "662", "ST" => "678", "SN" => "686", "RS" => "688", "SC" => "690", "SL" => "694", "SG" => "702", "SX" => "534", "SY" => "760", "SO" => "706", "LK" => "144", "SZ" => "748", "ZA" => "710", "SD" => "729", "SS" => "728", "SE" => "752", "CH" => "756", "SR" => "740", "SJ" => "744", "TH" => "764", "TW" => "158", "TZ" => "834", "TJ" => "762", "IO" => "086", "TF" => "260", "TL" => "626", "TG" => "768", "TK" => "772", "TO" => "776", "TT" => "780", "TN" => "788", "TM" => "795", "TR" => "792", "TV" => "798", "UA" => "804", "UG" => "800", "UY" => "858", "UZ" => "860", "VU" => "548", "VA" => "336", "VE" => "862", "VN" => "704", "WF" => "876", "YE" => "887", "DJ" => "262", "ZM" => "894", "ZW" => "716");
            return $arrCode[$code];
        } catch (exception $e) {}
        
        return "";
    }
    
    public function isoCodePhonePrefix($code)
    {
		try {
            $arrCode = array("AC" => "247", "AD" => "376", "AE" => "971", "AF" => "93","AG" => "268", "AI" => "264", "AL" => "355", "AM" => "374", "AN" => "599", "AO" => "244", "AR" => "54", "AS" => "684", "AT" => "43", "AU" => "61", "AW" => "297", "AX" => "358", "AZ" => "374", "AZ" => "994", "BA" => "387", "BB" => "246", "BD" => "880", "BE" => "32", "BF" => "226", "BG" => "359", "BH" => "973", "BI" => "257", "BJ" => "229", "BM" => "441", "BN" => "673", "BO" => "591", "BR" => "55", "BS" => "242", "BT" => "975", "BW" => "267", "BY" => "375", "BZ" => "501", "CA" => "1", "CC" => "61", "CD" => "243", "CF" => "236", "CG" => "242", "CH" => "41", "CI" => "225", "CK" => "682", "CL" => "56", "CM" => "237", "CN" => "86", "CO" => "57", "CR" => "506", "CS" => "381", "CU" => "53", "CV" => "238", "CX" => "61", "CY" => "392", "CY" => "357", "CZ" => "420", "DE" => "49", "DJ" => "253", "DK" => "45", "DM" => "767", "DO" => "809", "DZ" => "213", "EC" => "593", "EE" => "372", "EG" => "20", "EH" => "212", "ER" => "291", "ES" => "34", "ET" => "251", "FI" => "358", "FJ" => "679", "FK" => "500", "FM" => "691", "FO" => "298", "FR" => "33", "GA" => "241", "GB" => "44", "GD" => "473", "GE" => "995", "GF" => "594", "GG" => "44", "GH" => "233", "GI" => "350", "GL" => "299", "GM" => "220", "GN" => "224", "GP" => "590", "GQ" => "240", "GR" => "30", "GT" => "502", "GU" => "671", "GW" => "245", "GY" => "592", "HK" => "852", "HN" => "504", "HR" => "385", "HT" => "509", "HU" => "36", "ID" => "62", "IE" => "353", "IL" => "972", "IM" => "44", "IN" => "91", "IO" => "246", "IQ" => "964", "IR" => "98", "IS" => "354", "IT" => "39", "JE" => "44", "JM" => "876", "JO" => "962", "JP" => "81", "KE" => "254", "KG" => "996", "KH" => "855", "KI" => "686", "KM" => "269", "KN" => "869", "KP" => "850", "KR" => "82", "KW" => "965", "KY" => "345", "KZ" => "7", "LA" => "856", "LB" => "961", "LC" => "758", "LI" => "423", "LK" => "94", "LR" => "231", "LS" => "266", "LT" => "370", "LU" => "352", "LV" => "371", "LY" => "218", "MA" => "212", "MC" => "377", "MD"  > "533", "MD" => "373", "ME" => "382", "MG" => "261", "MH" => "692", "MK" => "389", "ML" => "223", "MM" => "95", "MN" => "976", "MO" => "853", "MP" => "670", "MQ" => "596", "MR" => "222", "MS" => "664", "MT" => "356", "MU" => "230", "MV" => "960", "MW" => "265", "MX" => "52", "MY" => "60", "MZ" => "258", "NA" => "264", "NC" => "687", "NE" => "227", "NF" => "672", "NG" => "234", "NI" => "505", "NL" => "31", "NO" => "47", "NP" => "977", "NR" => "674", "NU" => "683", "NZ" => "64", "OM" => "968", "PA" => "507", "PE" => "51", "PF" => "689", "PG" => "675", "PH" => "63", "PK" => "92", "PL" => "48", "PM" => "508", "PR" => "787", "PS" => "970", "PT" => "351", "PW" => "680", "PY" => "595", "QA" => "974", "RE" => "262", "RO" => "40", "RS" => "381", "RU" => "7", "RW" => "250", "SA" => "966", "SB" => "677", "SC" => "248", "SD" => "249", "SE" => "46", "SG" => "65", "SH" => "290", "SI" => "386", "SJ" => "47", "SK" => "421", "SL" => "232", "SM" => "378", "SN" => "221", "SO" => "252", "SO" => "252", "SR"  > "597", "ST" => "239", "SV" => "503", "SY" => "963", "SZ" => "268", "TA" => "290", "TC" => "649", "TD" => "235", "TG" => "228", "TH" => "66", "TJ" => "992", "TK" =>  "690", "TL" => "670", "TM" => "993", "TN" => "216", "TO" => "676", "TR" => "90", "TT" => "868", "TV" => "688", "TW" => "886", "TZ" => "255", "UA" => "380", "UG" =>  "256", "US" => "1", "UY" => "598", "UZ" => "998", "VA" => "379", "VC" => "784", "VE" => "58", "VG" => "284", "VI" => "340", "VN" => "84", "VU" => "678", "WF" => "681", "WS" => "685", "YE" => "967", "YT" => "262", "ZA" => "27","ZM" => "260", "ZW" => "263");
            return $arrCode[$code];
        } catch (exception $e) {}
        return "";
	}
    
    public function getEMV3DS($order)
    {
        /*Datos Scoring*/
        $s_cid = $order->getCustomerId();
        if ($s_cid == "" ) {
            $s_cid = 0;
        }

        $Merchant_EMV3DS = array();

        $Merchant_EMV3DS["customer"]["id"] = $s_cid;
		$Merchant_EMV3DS["customer"]["name"] = $order->getCustomerFirstname();
		$Merchant_EMV3DS["customer"]["surname"] = $order->getCustomerLastname();    
		$Merchant_EMV3DS["customer"]["email"] = $order->getCustomerEmail();
    
        
        $billing = $order->getBillingAddress();
        $phone = "";
        if (!empty($billing))   $phone = $billing->getTelephone();

        $shippingAddressData = $order->getShippingAddress();
        if ($shippingAddressData) {
            $streetData = $shippingAddressData->getStreet();
            $street0 = strtolower($streetData[0]);
            $street1 = strtolower($streetData[1]);
        }

        
        if ($phone!="") {
            $phone_prefix = $this->isoCodePhonePrefix($shippingAddressData->getCountry());
            if ($phone_prefix!="") {
                $arrDatosWorkPhone["cc"] = $phone_prefix;
                $arrDatosWorkPhone["subscriber"] = $phone;
                $Merchant_EMV3DS["customer"]["workPhone"] = $arrDatosWorkPhone;	
            }
        }

        $Merchant_EMV3DS["shipping"]["shipAddrCity"] = ($shippingAddressData)?$shippingAddressData->getCity():"";							
        $Merchant_EMV3DS["shipping"]["shipAddrCountry"] = ($shippingAddressData)?$shippingAddressData->getCountry():"";
        
        if ($Merchant_EMV3DS["shipping"]["shipAddrCountry"]!="") {
            $Merchant_EMV3DS["shipping"]["shipAddrCountry"] = $this->isoCodeToNumber($Merchant_EMV3DS["shipping"]["shipAddrCountry"]);
        }
        
        $Merchant_EMV3DS["shipping"]["shipAddrLine1"] = ($shippingAddressData)?$street0:"";
        $Merchant_EMV3DS["shipping"]["shipAddrLine2"] = ($shippingAddressData)?$street1:"";
        $Merchant_EMV3DS["shipping"]["shipAddrPostCode"] = ($shippingAddressData)?$shippingAddressData->getPostcode():"";
        //$Merchant_EMV3DS["shipping"]["shipAddrState"] = ($shippingAddressData)?$shippingAddressData->getRegion():"";	 // ISO 3166-2

        // Billing
        $billingAddressData = $order->getBillingAddress();
        if ($billingAddressData) {
            $streetData = $billingAddressData->getStreet();
            $street0 = strtolower($streetData[0]);
            $street1 = strtolower($streetData[1]);
        }        

        $Merchant_EMV3DS["billing"]["billAddrCity"] = ($billingAddressData)?$billingAddressData->getCity():"";				
        $Merchant_EMV3DS["billing"]["billAddrCountry"] = ($billingAddressData)?$billingAddressData->getCountry():"";
        if ($Merchant_EMV3DS["billing"]["billAddrCountry"]!="") {
            $Merchant_EMV3DS["billing"]["billAddrCountry"] = $this->isoCodeToNumber($Merchant_EMV3DS["billing"]["billAddrCountry"]);
        }
        $Merchant_EMV3DS["billing"]["billAddrLine1"] = ($billingAddressData)?$street0:"";
        $Merchant_EMV3DS["billing"]["billAddrLine2"] = ($billingAddressData)?$street1:"";
        $Merchant_EMV3DS["billing"]["billAddrPostCode"] = ($billingAddressData)?$billingAddressData->getPostcode():"";			

        //$Merchant_EMV3DS["billing"]["billAddrState"] = ($billingAddressData)?$billingAddressData->getRegion():"";     // ISO 3166-2

        
        // acctInfo
		$Merchant_EMV3DS["acctInfo"] = $this->acctInfo($order);

		// threeDSRequestorAuthenticationInfo
        $Merchant_EMV3DS["threeDSRequestorAuthenticationInfo"] = $this->threeDSRequestorAuthenticationInfo(); 
        

		// AddrMatch	
		$Merchant_EMV3DS["addrMatch"] = ($order->getBillingAddress()->getData('customer_address_id') == $order->getShippingAddress()->getData('customer_address_id'))?"Y":"N";	

		$Merchant_EMV3DS["challengeWindowSize"] = 05;
               
        
        return $Merchant_EMV3DS;

    }

    public function acctInfo($order) 
    {

		$acctInfoData = array();
		$date_now = new DateTime("now");

		$isGuest = $order->getCustomerIsGuest();
		if ($isGuest) {
			$acctInfoData["chAccAgeInd"] = "01";
		} else {
            $customer = Mage::getSingleton('customer/session')->getCustomer();
			$date_customer = new DateTime( $customer->getCreatedAt());
			
			$diff = $date_now->diff($date_customer);
			$dias = $diff->days;
            
			if ($dias==0) {
				$acctInfoData["chAccAgeInd"] = "02";
			} else if ($dias < 30) {
				$acctInfoData["chAccAgeInd"] = "03";
			} else if ($dias < 60) {
				$acctInfoData["chAccAgeInd"] = "04";
			} else {
				$acctInfoData["chAccAgeInd"] = "05";
            }
            
            
            $acctInfoData["chAccChange"] = Mage::getModel('core/date')->date('Ymd', $customer->getUpatedAt());

            $date_customer_upd = new DateTime($customer->getUpatedAt());
            $diff = $date_now->diff($date_customer_upd);
            $dias_upd = $diff->days;

            if ($dias_upd==0) {
                $acctInfoData["chAccChangeInd"] = "01";
            } else if ($dias_upd < 30) {
                $acctInfoData["chAccChangeInd"] = "02";
            } else if ($dias_upd < 60) {
                $acctInfoData["chAccChangeInd"] = "03";
            } else {
                $acctInfoData["chAccChangeInd"] = "04";
            }

            $acctInfoData["chAccDate"] = Mage::getModel('core/date')->date('Ymd', $customer->getCreatedAt());

            $acctInfoData["nbPurchaseAccount"] = $this->numPurchaseCustomer($order->getCustomerId(),1,6,"month");
            //$acctInfoData["provisionAttemptsDay"] = "";
            
            $acctInfoData["txnActivityDay"] = $this->numPurchaseCustomer($order->getCustomerId(),0,1,"day");
            $acctInfoData["txnActivityYear"] = $this->numPurchaseCustomer($order->getCustomerId(),0,1,"year");


            $firstAddressDelivery = $this->firstAddressDelivery($order->getCustomerId(),$order->getShippingAddress()->getData('customer_address_id'));

            if ($firstAddressDelivery!="") {

                $acctInfoData["shipAddressUsage"] = date("Ymd",strtotime($firstAddressDelivery));
                
                $date_firstAddressDelivery = new DateTime(strftime('%Y%m%d', strtotime($firstAddressDelivery)));
                $diff = $date_now->diff($date_firstAddressDelivery);
                $dias_firstAddressDelivery = $diff->days;
                
                if ($dias_firstAddressDelivery==0) {
                    $acctInfoData["shipAddressUsageInd"] = "01";
                } else if ($dias_upd < 30) {
                    $acctInfoData["shipAddressUsageInd"] = "02";
                } else if ($dias_upd < 60) {
                    $acctInfoData["shipAddressUsageInd"] = "03";
                } else {
                    $acctInfoData["shipAddressUsageInd"] = "04";
                }
            }

        }            
        
        if ( ($order->getCustomerFirstname() != $order->getShippingAddress()->getData('firstname')) ||
        ($order->getCustomerLastname() != $order->getShippingAddress()->getData('lastname'))) { 
            $acctInfoData["shipNameIndicator"] = "02";
        } else {
            $acctInfoData["shipNameIndicator"] = "01";
        }
        
        $acctInfoData["suspiciousAccActivity"] = "01";
            

		return $acctInfoData;
    }
    

    /**
	 * Obtiene transacciones realizadas
	 * @param int $id_customer codigo cliente
	 * @param int $valid completadas o no
	 * @param int $interval intervalo
	 * @return string $intervalType tipo de intervalo (DAY,MONTH)
	 **/
	public function numPurchaseCustomer($id_customer,$valid=1,$interval=1,$intervalType="day")
    {
        
        try {
            $from = new DateTime("now");
            $from->modify('-' . $interval . ' ' . $intervalType);
        
            $from = Mage::getModel('core/date')->date('Y-m-d h:m:s',$from->date);

            if ($valid==1) {
                $orderCollection = Mage::getModel('sales/order')->getCollection()
                    ->addFieldToFilter('customer_id', array('eq' => array($id_customer)))
                    ->addFieldToFilter('status', array(
                        'nin' => array('pending','cancel','canceled','refund'),
                        'notnull'=>true))
                    ->addAttributeToFilter('created_at', array('gt' => $from));
            } else {
                $orderCollection = Mage::getModel('sales/order')->getCollection()
                    ->addFieldToFilter('customer_id', array('eq' => array($id_customer)))
                    ->addFieldToFilter('status', array(
                        'notnull'=>true))
                    ->addAttributeToFilter('created_at', array('gt' => $from));
           
            }
            return $orderCollection->getSize();
        } catch (exception $e) {
            return 0;
        }
    }
    

    public function threeDSRequestorAuthenticationInfo() 
    {		
        
        $threeDSRequestorAuthenticationInfo = array();
        
		//$threeDSRequestorAuthenticationInfo["threeDSReqAuthData"] = "";
        
        $logged = Mage::getSingleton('customer/session')->isLoggedIn();
        $threeDSRequestorAuthenticationInfo["threeDSReqAuthMethod"] = ($logged)?"02":"01";
        
        if ($logged) {

            $lastVisited = Mage::getSingleton('customer/session')->getCustomer()->getLoginAt();
            $threeDSReqAuthTimestamp = Mage::getModel('core/date')->date('Ymdhm', $lastVisited);
		    $threeDSRequestorAuthenticationInfo["threeDSReqAuthTimestamp"] = $threeDSReqAuthTimestamp;
        }
        
		return $threeDSRequestorAuthenticationInfo;
	}

    /**
	 * Obtiene Fecha del primer envio a una direccion
	 * @param int $id_customer codigo cliente
	 * @param int $id_address_delivery direccion de envio
	 **/

	public function firstAddressDelivery($id_customer,$id_address_delivery)
    {
       
        try {
            $orderCollection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('customer_id', array('eq' => $id_customer))
            ->getSelect()
            ->joinLeft('sales_flat_order_address', "main_table.entity_id = sales_flat_order_address.parent_id",array('customer_address_id'))
            ->where("sales_flat_order_address.customer_address_id = $id_address_delivery ")
            ->limit('1')
            ->order('created_at ASC');

            $resource = Mage::getSingleton('core/resource');
            $readConnection = $resource->getConnection('core_read');
                
            $results = $readConnection->fetchAll($orderCollection);
        
            if (sizeof($results)>0) { 
                $firstOrder = current($results);
                return $firstOrder["created_at"];
            } else {
                return "";
            }
        } catch (exception $e) {
            return "";
        }
    }
    

    public function getShoppingCart($order) 
    {

		$shoppingCartData = array();

        foreach ($order->getAllItems() as $key=>$item) {
            $shoppingCartData[$key]["sku"] = $item->getSku();
			$shoppingCartData[$key]["quantity"] = number_format($item->getQtyOrdered(), 0, '.', '');
			$shoppingCartData[$key]["unitPrice"] = number_format($item->getPrice()*100, 0, '.', '');
            $shoppingCartData[$key]["name"] = $item->getName();
            
            $product = Mage::getModel('catalog/product')->load($item->getProductId());
            $categoryIds = $product->getCategoryIds();

            $cats = $product->getCategoryIds();

            $arrCat = array();
            foreach ($cats as $category_id) {
                $_cat = Mage::getModel('catalog/category')->load($category_id);
                $arrCat[] = $_cat->getName();
            }                            

			$shoppingCartData[$key]["category"] = implode("|",$arrCat);            
         }

		return array("shoppingCart"=>array_values($shoppingCartData));	
	}
    

    public function transactionScore($order)
    {
        $api = $this->getApi();
       
        // Initialize array Score
        $arrScore = array();
        $arrScore["score"] = null;
        $arrScore["scoreCalc"] = null;
        
        $shippingAddressData = $order->getShippingAddress();
        $shipping_address_country = ($shippingAddressData)?$shippingAddressData->getCountry():"";

        // First Purchase 
        if ($this->getConfigData('firstpurchase_scoring')) {
            $firstpurchase_scoring_score = $this->getConfigData('firstpurchase_scoring_score');
            if ($this->isFirstPurchaseToken($order->getPaytpvIduser())){
                $arrScore["scoreCalc"]["firstpurchase"] = $firstpurchase_scoring_score;
            }
        }

        // Complete Session Time
        if ($this->getConfigData('sessiontime_scoring')) {
            $sessiontime_scoring_val = $this->getConfigData('sessiontime_scoring_val');
            $sessiontime_scoring_score = $this->getConfigData('sessiontime_scoring_score');

            $VisitorData = Mage::getSingleton('core/session')->getVisitorData();
            if ($VisitorData && $VisitorData["first_visit_at"]) {
                $first_visit_at = $VisitorData["first_visit_at"];
                $now = now();

                $time_ss = strtotime($now) - strtotime($first_visit_at);
                $time_mm = floor($time_ss / 60);

                if ($sessiontime_scoring_val>=$time_mm) {
                    $arrScore["scoreCalc"]["completesessiontime"] = $sessiontime_scoring_score;
                }
            }
        }

        // Critical Product | Destination
        if ($this->getConfigData('critical_product_scoring')) {

            $items = $order->getAllVisibleItems();
            foreach($items as $i)
                $arrProducts[] = $i->getProductId();

            $critical_product_scoring_val = explode(",",$this->getConfigData('critical_product_scoring_val'));
            $critical_product_scoring_score = $this->getConfigData('critical_product_scoring_score');

            if (count(array_intersect($critical_product_scoring_val, $arrProducts))>0)
                $arrScore["scoreCalc"]["criticalproduct"] = $critical_product_scoring_score;


            if ($this->getConfigData('critical_product_dcountry_scoring')) {
                $critical_product_dcountry_scoring_val = explode(",",$this->getConfigData('critical_product_dcountry_scoring_val'));
                $critical_product_dcountry_scoring_score = $this->getConfigData('critical_product_dcountry_scoring_score');

                if (in_array($shipping_address_country,$critical_product_dcountry_scoring_val))
                    $arrScore["scoreCalc"]["criticaldestination"] = $critical_product_dcountry_scoring_score;
            }
        }

        // Destination 
        if ($this->getConfigData('dcountry_scoring')) {
            $dcountry_scoring_val = explode(",",$this->getConfigData('dcountry_scoring_val'));
            $dcountry_scoring_score = $this->getConfigData('dcountry_scoring_score');

            if (in_array($shipping_address_country,$dcountry_scoring_val))
                $arrScore["scoreCalc"]["destination"] = $dcountry_scoring_score;
        }

        // Ip Change 
        if ($this->getConfigData('ip_change_scoring')) {
            $sess = Mage::getSingleton('checkout/session');
            $ip_change_scoring = $this->getConfigData('ip_change_scoring');
            $ip = $this->getOriginalIp();

            if ($ip!=$sess->getPaytpvOriginalIp())
                $arrScore["scoreCalc"]["ipchange"] = $ip_change_scoring;
        }

        // Browser Unidentified 
        if ($this->getConfigData('browser_scoring')) {
            $browser_scoring_score = $this->getConfigData('browser_scoring_score');
            if ($api->browser_detection('browser_name')=="")
                $arrScore["scoreCalc"]["browser_unidentified"] = $browser_scoring_score;

        }

        // Operating System Unidentified 
        if ($this->getConfigData('so_scoring')) {
            $so_scoring_score = $this->getConfigData('so_scoring_score');
            if ($api->browser_detection('os')=="")
                $arrScore["scoreCalc"]["operating_system_unidentified"] = $so_scoring_score;
        }

        // CALC ORDER SCORE
        if (sizeof($arrScore["scoreCalc"])>0) {
            $score = floor(array_sum($arrScore["scoreCalc"]) / sizeof($arrScore["scoreCalc"]));
            $arrScore["score"] = $score;
        }

        
        return $arrScore;

    }

    public function infoUser($DS_IDUSER, $DS_TOKEN_USER)
    {
        
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));

        return $this->getClient()->info_user(
            $DS_MERCHANT_MERCHANTCODE, $DS_MERCHANT_TERMINAL, $DS_IDUSER, $DS_TOKEN_USER, $DS_MERCHANT_MERCHANTSIGNATURE, $this->getOriginalIp());
    }

    // Function to get the client ip address
    public function getOriginalIp()
    {
        $ip = Mage::helper('core/http')->getRemoteAddr();
        if($ip) {
            if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
                $ip = $_SERVER["HTTP_CLIENT_IP"];
            } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($ips[count($ips) - 1]);
            }
            return $ip;
        }
        // There might not be any data
        return "";
    }

    public function executePurchase($order, $original_ip = '')
    {
               
        $amount = $order->getBaseGrandTotal();

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_IDUSER = $order->getPaytpvIduser();
        $DS_TOKEN_USER = $order->getPaytpvTokenuser();
        $DS_MERCHANT_AMOUNT = round($amount * 100);
        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_CURRENCY = $order->getBaseCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AMOUNT . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $this->getOriginalIp();

        $DS_MERCHANT_PRODUCTDESCRIPTION = $order->getIncrementId();
        $DS_MERCHANT_OWNER = ''; /*@TODO: Set owner*/

        $score = $this->transactionScore($order);
        $DS_MERCHANT_SCORING = $score["score"];
        $DS_MERCHANT_DATA = $this->getMerchantData($order);


        return $this->getClient()->execute_purchase(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_AMOUNT,
            $DS_MERCHANT_ORDER,
            $DS_MERCHANT_CURRENCY,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP,
            $DS_MERCHANT_PRODUCTDESCRIPTION,
            $DS_MERCHANT_OWNER,
            $DS_MERCHANT_SCORING,
            $DS_MERCHANT_DATA
        );
    }


    private function operationCall($order)
    {

        // Array. Una password por cada terminal en PAYTPV_OPERATIONS_TERMINAL
        $terminal_passwords = array($this->getConfigData('pass'));

        $method = 'search_operations';

        $arrDatos["PAYTPV_OPERATIONS_MERCHANTCODE"]    = $this->getConfigData('client');
        $arrDatos["PAYTPV_OPERATIONS_SORTYPE"]         = 1;
        $arrDatos["PAYTPV_OPERATIONS_SORTORDER"]       = "ASC";
        $arrDatos["PAYTPV_OPERATIONS_LIMIT"]           = 9999;
        $arrDatos["PAYTPV_OPERATIONS_TERMINAL"]        = array($this->getConfigData('terminal'));
        $arrDatos["PAYTPV_OPERATIONS_OPERATIONS"]      = array(1,2,3,9);
        $arrDatos["PAYTPV_OPERATIONS_MINAMOUNT"]       = 0;
        $arrDatos["PAYTPV_OPERATIONS_MAXAMOUNT"]       = 999999999;
        $arrDatos["PAYTPV_OPERATIONS_STATE"]           = 1;
        $arrDatos["PAYTPV_OPERATIONS_FROMDATE"]        = date('YmdH', mktime(0, 0, 0, date("n")-1)) . "0000";
        $arrDatos["PAYTPV_OPERATIONS_TODATE"]          = date('YmdH', mktime(0, 0, 0, date("n")+1)) . "0000";
        $arrDatos["PAYTPV_OPERATIONS_CURRENCY"]        = $order->getBaseCurrencyCode();

        $arrDatos["PAYTPV_OPERATIONS_VERSION"]         = "1.10";

        // Inicio Calculo de firma
        $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]       = $arrDatos["PAYTPV_OPERATIONS_MERCHANTCODE"];
        $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]       .= $this->getConfigData('terminal').$terminal_passwords[0];
         
        foreach($arrDatos["PAYTPV_OPERATIONS_OPERATIONS"] as $oper) {
            $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]   .= $oper;
        }

        $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]       .= $arrDatos["PAYTPV_OPERATIONS_FROMDATE"].$arrDatos["PAYTPV_OPERATIONS_TODATE"];
        $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]       = sha1($arrDatos["PAYTPV_OPERATIONS_SIGNATURE"]);
        // Fin Calculo de firma

        $arrDatos["PAYTPV_OPERATIONS_REFERENCE"]       = $order->getIncrementId();
        $arrDatos["PAYTPV_OPERATIONS_SEARCHTYPE"]      = 0;

        $res = $this->getClientOperation()->search_operations(
                $arrDatos["PAYTPV_OPERATIONS_MERCHANTCODE"],
                $arrDatos["PAYTPV_OPERATIONS_SORTYPE"],
                $arrDatos["PAYTPV_OPERATIONS_SORTORDER"],
                $arrDatos["PAYTPV_OPERATIONS_LIMIT"],
                $arrDatos["PAYTPV_OPERATIONS_TERMINAL"],
                $arrDatos["PAYTPV_OPERATIONS_OPERATIONS"],
                $arrDatos["PAYTPV_OPERATIONS_MINAMOUNT"],
                $arrDatos["PAYTPV_OPERATIONS_MAXAMOUNT"],
                $arrDatos["PAYTPV_OPERATIONS_STATE"],
                $arrDatos["PAYTPV_OPERATIONS_FROMDATE"],
                $arrDatos["PAYTPV_OPERATIONS_TODATE"],
                $arrDatos["PAYTPV_OPERATIONS_CURRENCY"],
                $arrDatos["PAYTPV_OPERATIONS_SIGNATURE"],
                $arrDatos["PAYTPV_OPERATIONS_REFERENCE"],
                $arrDatos["PAYTPV_OPERATIONS_SEARCHTYPE"],
                $arrDatos["PAYTPV_OPERATIONS_VERSION"]
                
            );
        return $res;
    }


    private function create_preauthorization($order, $amount, $original_ip = '')
    {
        
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_IDUSER = $order->getPaytpvIduser();
        $DS_TOKEN_USER = $order->getPaytpvTokenuser();
        $DS_MERCHANT_AMOUNT = round($amount * 100);
        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_CURRENCY = $order->getBaseCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AMOUNT . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $this->getOriginalIp();
        $DS_MERCHANT_PRODUCTDESCRIPTION = $order->getIncrementId();
        $DS_MERCHANT_OWNER = ''; /*@TODO: Set owner*/

        $score = $this->transactionScore($order);
        $DS_MERCHANT_SCORING = $score["score"];
        $DS_MERCHANT_DATA = $this->getMerchantData($order);
        
        return $this->getClient()->create_preauthorization(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_AMOUNT,
            $DS_MERCHANT_ORDER,
            $DS_MERCHANT_CURRENCY,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP,
            $DS_MERCHANT_PRODUCTDESCRIPTION,
            $DS_MERCHANT_OWNER,
            $DS_MERCHANT_SCORING,
            $DS_MERCHANT_DATA
        );
    }


    private function preauthorization_confirm($order, $amount, $original_ip = '')
    {
        
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_IDUSER = $order->getPaytpvIduser();
        $DS_TOKEN_USER = $order->getPaytpvTokenuser();
        $DS_MERCHANT_AMOUNT = round($amount * 100);
        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL .  $DS_MERCHANT_ORDER . $DS_MERCHANT_AMOUNT . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $this->getOriginalIp();
        return $this->getClient()->preauthorization_confirm(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_AMOUNT,
            $DS_MERCHANT_ORDER,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
    }

    

    private function executeRefund(Varien_Object $payment,$amount)
    {
        $order = $payment->getOrder();
                
        $fecha = date("Ymd",strtotime($order->getCreatedAt()));

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_IDUSER = $order->getPaytpvIduser();
        $DS_TOKEN_USER = $order->getPaytpvTokenuser();
        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_CURRENCY = $order->getBaseCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_AUTHCODE = $payment->getLastTransId();
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AUTHCODE . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $order->getRemoteAddr();
        $DS_MERCHANT_AMOUNT = round($amount * 100);

        $res = $this->getClient()->execute_refund(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_AUTHCODE,
            $DS_MERCHANT_ORDER,
            $DS_MERCHANT_CURRENCY,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP,
            $DS_MERCHANT_AMOUNT
        );
        // Si recibimos este error intentamos realizar la devolucion con order[iduser]fecha
        if ($res['DS_ERROR_ID']==130) {
            $DS_MERCHANT_ORDER = $order->getIncrementId() . "[" . $DS_IDUSER . "]" . $fecha;
            $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AUTHCODE . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
            
            $res = $this->getClient()->execute_refund(
                $DS_MERCHANT_MERCHANTCODE,
                $DS_MERCHANT_TERMINAL,
                $DS_IDUSER,
                $DS_TOKEN_USER,
                $DS_MERCHANT_AUTHCODE,
                $DS_MERCHANT_ORDER,
                $DS_MERCHANT_CURRENCY,
                $DS_MERCHANT_MERCHANTSIGNATURE,
                $DS_ORIGINAL_IP,
                $DS_MERCHANT_AMOUNT
            );
        }
        
        // Si recibimos este error intentamos realizar la devolucion con S-tokenuser[iduser]fecha
        if ($res['DS_ERROR_ID']==130 || $res['DS_ERROR_ID']==1001 ) {
            $DS_MERCHANT_ORDER = "S-" . $DS_TOKEN_USER . "[" . $DS_IDUSER . "]" . $fecha;
            $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AUTHCODE . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
            
            $res = $this->getClient()->execute_refund(
                $DS_MERCHANT_MERCHANTCODE,
                $DS_MERCHANT_TERMINAL,
                $DS_IDUSER,
                $DS_TOKEN_USER,
                $DS_MERCHANT_AUTHCODE,
                $DS_MERCHANT_ORDER,
                $DS_MERCHANT_CURRENCY,
                $DS_MERCHANT_MERCHANTSIGNATURE,
                $DS_ORIGINAL_IP,
                $DS_MERCHANT_AMOUNT
            );
        }

        return $res;
    }

    public function addUser($payment_data, $original_ip = '')
    {
        
        $token = isset($payment_data["paytpvToken"])?$payment_data["paytpvToken"]:"";
      
        if ($this->getConfigData('integration')==1 && $token && strlen($token) == 64) {

            $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
            $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
            $DS_MERCHANT_JETTOKEN = $token;
            $DS_MERCHANT_JETID = $this->getConfigData('jetid');
            $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_MERCHANT_JETTOKEN . $DS_MERCHANT_JETID . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
            $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $this->getOriginalIp();

            return $this->getClient()->add_user_token(
                $DS_MERCHANT_MERCHANTCODE,
                $DS_MERCHANT_TERMINAL,
                $DS_MERCHANT_JETTOKEN,
                $DS_MERCHANT_JETID,
                $DS_MERCHANT_MERCHANTSIGNATURE,
                $DS_ORIGINAL_IP
            );
        } else {

            $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
            $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
            $DS_MERCHANT_PAN = trim($payment_data['cc_number']);
            $DS_MERCHANT_EXPIRYDATE = str_pad($payment_data['cc_exp_month'], 2, "0", STR_PAD_LEFT) . substr($payment_data['cc_exp_year'], 2, 2);
            $DS_MERCHANT_CVV2 = $payment_data['cc_cid'];
            $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_MERCHANT_PAN . $DS_MERCHANT_CVV2 . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
            $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $this->getOriginalIp();
            return $this->getClient()->add_user(
                $DS_MERCHANT_MERCHANTCODE,
                $DS_MERCHANT_TERMINAL,
                $DS_MERCHANT_PAN,
                $DS_MERCHANT_EXPIRYDATE,
                $DS_MERCHANT_CVV2,
                $DS_MERCHANT_MERCHANTSIGNATURE,
                $DS_ORIGINAL_IP
            );
        }

        
    }
     

    private function removeUser($idUser, $tokeUser)
    {

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_IDUSER = $idUser;
        $DS_TOKEN_USER = $tokeUser;
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1( $DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $this->getOriginalIp();
        if ($DS_ORIGINAL_IP=="::1") $DS_ORIGINAL_IP = "127.0.0.1";

        return $this->getClient()->remove_user(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
    }

    private function removeSuscription($idUser, $tokeUser)
    {

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_IDUSER = $idUser;
        $DS_TOKEN_USER = $tokeUser;
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1( $DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $this->getOriginalIp();
        
        if ($DS_ORIGINAL_IP=="::1") $DS_ORIGINAL_IP = "127.0.0.1";



        return $this->getClient()->remove_subscription(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
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

    
    

    public function getBankStoreFormFields($operation=1)
    {

        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);


        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language = $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode());

        $amount = $currency='';
        $amount = round($order->getBaseGrandTotal() * 100);
        $currency = $order->getBaseCurrencyCode();

        $Secure = ($this->isSecureTransaction($order->getBaseGrandTotal()))?1:0;


        $score = $this->transactionScore($order);
       

        // execute purchase
        if ($operation == 1) {

            $MERCHANT_SCORING = $score["score"];        
            $MERCHANT_DATA = $this->getMerchantData($order);

            $signature = hash('sha512',$client . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_ORDER' => $order_id,
                'URLOK' => Mage::getUrl('paytpvcom/standard/reciboBankstore'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel'),
                'MERCHANT_AMOUNT' => $amount,
                'MERCHANT_CURRENCY' => $currency,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                '3DSECURE' => $Secure
            );
            if ($MERCHANT_SCORING!=null)        $sArr["MERCHANT_SCORING"] = $MERCHANT_SCORING;
            if ($MERCHANT_DATA!=null)           $sArr["MERCHANT_DATA"] = $MERCHANT_DATA;
        }


        // add_user
        if ($operation == 107) {

            $secure = 0;
            $terminales = $this->getConfigData('terminales');
            if ($terminales==0) $secure = 1;

            $order = Mage::getSingleton('customer/session')->getCustomer()->getId();
            $signature = hash('sha512',$client . $terminal . $operation . $order . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                'MERCHANT_ORDER' => $order,
                'URLOK' => Mage::getUrl('paytpvcom/standard/tarjetas'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel'),
                '3DSECURE' => $secure                
            );
        }

        // crate_preauthorization
        if ($operation == 3) {

            $MERCHANT_SCORING = $score["score"];        
            $MERCHANT_DATA = $this->getMerchantData($order);
            
            $signature = hash('sha512',$client . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                'MERCHANT_ORDER' => $order_id,
                'MERCHANT_AMOUNT' => $amount,
                'MERCHANT_CURRENCY' => $currency,
                '3DSECURE' => $Secure,
                'URLOK' => Mage::getUrl('paytpvcom/standard/reciboBankstore'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')
            );
            if ($MERCHANT_SCORING!=null)        $sArr["MERCHANT_SCORING"] = $MERCHANT_SCORING;
            if ($MERCHANT_DATA!=null)           $sArr["MERCHANT_DATA"] = $MERCHANT_DATA;
        }


        $query = http_build_query($sArr);
        $vhash = hash('sha512', md5($query.md5($pass)));

        $sArr["VHASH"] = $vhash;

        return $sArr;

    }


    public function getBankStoreTokenFormFields($operation=109)
    {
        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language = $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode());

        $amount = $currency='';
        $amount = round($order->getBaseGrandTotal() * 100);
        $currency = $order->getBaseCurrencyCode();

        $paytpv_iduser = $order->getPaytpvIduser();
        $paytpv_tokenuser = $order->getPaytpvTokenuser();

        
        $score = $this->transactionScore($order);
        $MERCHANT_SCORING = $score["score"];
        $MERCHANT_DATA = $this->getMerchantData($order);


        // execute_purchase_token
        if ($operation == 109) {
            $signature = hash('sha512',$client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                'MERCHANT_ORDER' => $order_id,
                'MERCHANT_AMOUNT' => $amount,
                'MERCHANT_CURRENCY' => $currency,
                'IDUSER' => $paytpv_iduser,
                'TOKEN_USER' => $paytpv_tokenuser,          
                '3DSECURE' => 1,
                'URLOK' => Mage::getUrl('paytpvcom/standard/reciboBankstore'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')
            );
            if ($MERCHANT_SCORING!=null)        $sArr["MERCHANT_SCORING"] = $MERCHANT_SCORING;
            if ($MERCHANT_DATA!=null)           $sArr["MERCHANT_DATA"] = $MERCHANT_DATA;
        }

        // execute_preauthorization_token
        if ($operation == 111) {
            $signature = hash('sha512', $client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                'MERCHANT_ORDER' => $order_id,
                'MERCHANT_AMOUNT' => $amount,
                'MERCHANT_CURRENCY' => $currency,     
                'IDUSER' => $paytpv_iduser,
                'TOKEN_USER' => $paytpv_tokenuser,
                '3DSECURE' => 1,
                'URLOK' => Mage::getUrl('paytpvcom/standard/reciboBankstore'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')
            );
            if ($MERCHANT_SCORING!=null)        $sArr["MERCHANT_SCORING"] = $MERCHANT_SCORING;
            if ($MERCHANT_DATA!=null)           $sArr["MERCHANT_DATA"] = $MERCHANT_DATA;
        }


        $query = http_build_query($sArr);
        $vhash = hash('sha512', md5($query.md5($pass)));

        $sArr["VHASH"] = $vhash;

        return $sArr;
    }


    public function getBankStorerecurringTokenFormFields($arrDatos)
    {
        $operation=110; // create_subscription_token;

        $order_id = $arrDatos["MERCHANT_ORDER"];
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);

        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language = $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode());
        
        $amount = $currency='';
        $amount = round($arrDatos["MERCHANT_AMOUNT"] * 100);
        $currency = $arrDatos["MERCHANT_CURRENCY"];

        $paytpv_iduser = $arrDatos["IDUSER"];
        $paytpv_tokenuser = $arrDatos["TOKEN_USER"];
        
        $subscription_stratdate = $arrDatos["DS_SUBSCRIPTION_STARTDATE"];

        $subs_periodicity = $arrDatos["DS_SUBSCRIPTION_PERIODICITY"];
        $subscription_enddate = $arrDatos["DS_SUBSCRIPTION_ENDDATE"];


        $score = $this->transactionScore($order);
        $MERCHANT_SCORING = $score["score"];
        $MERCHANT_DATA = $this->getMerchantData($order);


        $signature = hash('sha512',$client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
        $sArr = array
        (
            'MERCHANT_MERCHANTCODE' => $client,
            'MERCHANT_TERMINAL' => $terminal,
            'OPERATION' => $operation,
            'LANGUAGE' => $language,
            'MERCHANT_MERCHANTSIGNATURE' => $signature,
            'MERCHANT_ORDER' => $order_id,
            'MERCHANT_AMOUNT' => $amount,
            'MERCHANT_CURRENCY' => $currency,
            'SUBSCRIPTION_STARTDATE' => $subscription_stratdate, 
            'SUBSCRIPTION_ENDDATE' => $subscription_enddate,
            'SUBSCRIPTION_PERIODICITY' => $subs_periodicity,
            'IDUSER' => $paytpv_iduser,
            'TOKEN_USER' => $paytpv_tokenuser,
            '3DSECURE' => 1,
            'URLOK' => Mage::getUrl('paytpvcom/standard/reciboBankstore'),
            'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')
        );

        if ($MERCHANT_SCORING!=null)        $sArr["MERCHANT_SCORING"] = $MERCHANT_SCORING;
        if ($MERCHANT_DATA!=null)           $sArr["MERCHANT_DATA"] = $MERCHANT_DATA;

        $query = http_build_query($sArr);
        $vhash = hash('sha512', md5($query.md5($pass)));

        $sArr["VHASH"] = $vhash;

        return $sArr;
        
    }

    
    // Cargar tarjetas tokenizadas
    public function loadCustomerCards()
    {
        $model = Mage::getModel('paytpvcom/customer');
        $collection = $model->getCollection()
            ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
            ->setOrder("paytpv_iduser", 'DESC');
        $arrCards = array();
        foreach($collection as $item) {
            $arrCards[] =  $item->getData();
        }
        $this->setCustomerCards($arrCards);
        return $arrCards;
    }


    public function getStandardFormTemplate()
    {
        $this->loadCustomerCards();
        $terminales = $this->getConfigData('terminales');
        return 'paytpvcom/form_bankstore_ws.phtml';
    }

    function isSecureTransaction($total_amount=0)
    {
        $terminales = $this->getConfigData('terminales');
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        
        $payment_data_card = (isset($payment_data["card"]))?$payment_data["card"]:0;

        // Transaccion Segura:
        // Si solo tiene Terminal Seguro
        if ($terminales==0)
            return true;   
   
        // Si esta definido que el pago es 3d secure y no estamos usando una tarjeta tokenizada
        if ($this->getConfigData('secure_first') && $payment_data_card==0)
            return true;

        $total_amount = ($total_amount==0)?$this->getCurrentOrderAmount():$total_amount;

        // Si se supera el importe maximo para compra segura
        if ($terminales==2 && ($this->getConfigData('secure_amount')!="" && $this->getConfigData('secure_amount') < $total_amount))
            return true;

        // Si esta definido como que la primera compra es Segura y es la primera compra aunque este tokenizada
        if ($terminales==2 && $this->getConfigData('secure_first') && $payment_data_card>0 && $this->isFirstPurchaseToken($payment_data_card))
            return true;
        
        return false;
    }

    public function getToken($paytpv_iduser)
    {
        $model = Mage::getModel('paytpvcom/customer');
        $card = $model->getCollection()
                        ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
                        ->addFilter("paytpv_iduser",$paytpv_iduser,"and")
                        ->getFirstItem()->getData();
        return $card;
    }

    
    /**
     * removeCard
     *
     * 
     */
    function removeCard($customer_id)
    {
        $model = Mage::getModel('paytpvcom/customer');
        $customer = $model->getCollection()
                    ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
                    ->addFilter("customer_id",$customer_id,"and")
                    ->getFirstItem()->getData();
        $paytpv_iduser = $customer["paytpv_iduser"];
        $paytpv_tokenuser = $customer["paytpv_tokenuser"];
        $customer_id = $customer["customer_id"];

        // Si se elimina el usuario no se pueden realizar devoluciones desde el backofice
        //$result = $this->removeUser( $paytpv_iduser, $paytpv_tokenuser);
        try {
            $customer = $model->getCollection()
                        ->addFilter("customer_id",$customer_id,"and")
                        ->getFirstItem()->delete();
        } catch (Exception $e) {
            return false;
        }
        return true;
    }


    /**
     * saveDescriptionCard
     *
     * 
     */
    function saveDescriptionCard($customer_id,$card_desc)
    {

        $model = Mage::getModel('paytpvcom/customer');
        $collection = $model->getCollection()
            ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
            ->addFilter("customer_id",$customer_id,"and");
        if ($collection->getSize()==0) {
            return false;
        } else {
            $data = array("customer_id"=>$customer_id,"card_desc"=>$card_desc);
            $model = Mage::getModel('paytpvcom/customer')->setData($data);
            try {
                $insertId = $model->save()->getId();
                //echo "CUSTOMER Data successfully inserted. Insert ID: ".$insertId;
            } catch (Exception $e) {
                return false;
            }
            return true;
        }
    }


    /**
     * addCard
     *
     * 
     */
    function addCard($params,$salida=0)
    {
        $id_customer = Mage::getSingleton('customer/session')->getCustomer()->getId();

        if ($id_customer>0) {
            $res = $this->addUser($params);
            
            $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
            $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
            $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';

            if ((int)$DS_ERROR_ID == 0) {

                $card["paytpv_iduser"] = $DS_IDUSER;
                $card["paytpv_tokenuser"] = $DS_TOKEN_USER;

                // Si ha pulsado en el acuerdo y es un usuario registrado guardamos el token
                $result = $this->infoUser($DS_IDUSER,$DS_TOKEN_USER);
                $this->save_card($DS_IDUSER,$DS_TOKEN_USER,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$id_customer);
                
                if ($salida==1) return $res;
                return "";
            } else {
                if ($salida==1) return $res;

                return Mage::helper('payment')->__('%s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            }
        }
        return _("Error");
    }

    function isFirstPurchaseToken($IDUSER)
    {
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        if (!$customer)
            return true;
        $orderCollection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('customer_id', array('eq' => array($customer->getId())))
            ->addFieldToFilter('paytpv_iduser', array('eq' => array($IDUSER)))
            ->addFieldToFilter('status', array(
                'nin' => array('pending','cancel','canceled','refund'),
                'notnull'=>true)
            );
        if (0 < $orderCollection->getSize()) {
            return false;
        }
        return true;
    }
       
    function isFirstPurchase() 
    {
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        if (!$customer)
            return true;
        $orderCollection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('customer_id', array('eq' => array($customer->getId())))
            ->addFieldToFilter('status', array(
                'nin' => array('pending','cancel','canceled','refund'),
                'notnull'=>true)
            );
        if (0 < $orderCollection->getSize()) {
            return false;
        }
        return true;
    }

    function getCurrentOrderAmount()
    {
        $order = Mage::helper('checkout/cart')->getQuote();
        return $order->getBaseGrandTotal();
    }


    public function getPayTpvBankStoreUrl(){
        return "https://api.paycomet.com/gateway/ifr-bankstore";        
    }


    public function getPayTpvBankStoreUrlAddUser(){
        return "https://api.paycomet.com/gateway/ifr-bankstore";
    }


    public function refund(Varien_Object $payment, $amount)
    {
        $order = $payment->getOrder();
        //$amount = $order->getBaseGrandTotal();

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

    public function save_card($paytpv_iduser,$paytpv_tokenuser,$paytpv_cc,$paytpv_brand,$id_customer)
    {
        $paytpv_cc = '************' . substr($paytpv_cc, -4);

        $arrSalida["paytpv_iduser"] = $paytpv_iduser;
        $arrSalida["paytpv_tokenuser"] = $paytpv_tokenuser;

        $data = array("paytpv_iduser"=>$paytpv_iduser,"paytpv_tokenuser"=>$paytpv_tokenuser,"paytpv_cc"=>$paytpv_cc,"paytpv_brand"=>$paytpv_brand,"id_customer"=>$id_customer,"date"=>now());
        $model = Mage::getModel('paytpvcom/customer')->setData($data);
        try {
            $insertId = $model->save()->getId();
            //echo "CUSTOMER Data successfully inserted. Insert ID: ".$insertId;
        } catch (Exception $e){
         echo $e->getMessage();
        }
        return $arrSalida;
    }

    
    /* RECURRING PROFILES */

    /**
     * Validate RP data
     *
     * @param  Mage_Payment_Model_Recurring_Profile $profile
     * @throws Mage_Core_Exception
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        $errors = array();
        if (strlen($profile->getSubscriberName()) > 32) { // up to 32 single-byte chars
            $errors[] = Mage::helper('paytpv')->__('Subscriber name is too long.');
        }
        $refId = $profile->getInternalReferenceId(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[ a-z\d\s ]+$/i', $refId)
            $errors[] = Mage::helper('paytpv')->__('Merchant reference ID format is not supported.');
        }
        $scheduleDescr = $profile->getScheduleDescription(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[ a-z\d\s ]+$/i', $scheduleDescr)
            $errors[] = Mage::helper('paytpv')->__('Schedule description is too long.');
        }
        if ($errors) {
            Mage::throwException(implode(' ', $errors));
        }
    }

    /**
     * Submit RP to the gateway
     *
     * @param  Mage_Payment_Model_Recurring_Profile $profile
     * @param  Mage_Payment_Model_Info $paymentInfo
     * @throws Mage_Core_Exception
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $payment)
    {
        $profile->setRecurringAmount( $this->_formatAmount($profile->getTaxAmount() + $profile->getBillingAmount() + $profile->getShippingAmount()) );
        $api = $this->getApi();

        $api->setRecurringProfile($profile);
        $api->setPayment($payment);


        $res = $api->callCreateRecurringPaymentsProfile();

        $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
        $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
        $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';  
        
        if ((int)$res['DS_ERROR_ID'] == 0){
            $profile->setReferenceId($api->getMerchantTransId($DS_TOKEN_USER));
            $additionalInfo["paytpv_iduser"] = "paytpv_iduser_".$res["DS_IDUSER"]."_";
            $additionalInfo["paytpv_tokenuser"] = $DS_TOKEN_USER;
            $profile->setAdditionalInfo(serialize($additionalInfo));
            $profile->save();

            // Si es 3D Secure vamos al pago Seguro Iframe create_suscription_token
            if ($this->isSecureTransaction()){
                $subs_cycles = 0;
                if ($profile->getPeriodMaxCycles())
                    $subs_cycles = $profile->getPeriodMaxCycles();

                $DS_SUBSCRIPTION_STARTDATE = Mage::getModel('core/date')->date('Ymd', strtotime($profile->getStartDatetime()));
                
                $freq = $profile->getPeriodFrequency();
                switch ($profile->getPeriodUnit()){
                    case 'day':         $subs_periodicity = 1; break;
                    case 'week':        $subs_periodicity = 7; break;
                    case 'semi_month':  $subs_periodicity = 14; break;
                    case 'month':       $subs_periodicity = 30; break;
                    case 'year':        $subs_periodicity = 365; break;
                }

                $subs_periodicity = $subs_periodicity * $freq;

                // Si es indefinido, ponemos como fecha tope la fecha + 5 años.
                if ($subs_cycles==0){
                    $start_datetime = $profile->getStartDatetime();
                    $subscription_enddate = Mage::getModel('core/date')->date('Y', strtotime($start_datetime))+5 . Mage::getModel('core/date')->date('m', strtotime($start_datetime)) . Mage::getModel('core/date')->date('d', strtotime($start_datetime));
                }else{
                    // Dias suscripcion
                    $dias_subscription = $subs_cycles * $subs_periodicity;
                    $subscription_enddate = Mage::getModel('core/date')->date('Ymd', strtotime($profile->getStartDatetime(). " +".$dias_subscription." days"));
                }
                $DS_SUBSCRIPTION_ENDDATE = $subscription_enddate;
                $DS_SUBSCRIPTION_PERIODICITY = $subs_periodicity;

                $arrData["IDUSER"] = $res["DS_IDUSER"];
                $arrData["TOKEN_USER"] = $res["DS_TOKEN_USER"];
                $arrData["DS_SUBSCRIPTION_STARTDATE"] = $DS_SUBSCRIPTION_STARTDATE;
                $arrData["DS_SUBSCRIPTION_ENDDATE"] = $DS_SUBSCRIPTION_ENDDATE;
                $arrData["DS_SUBSCRIPTION_PERIODICITY"] = $DS_SUBSCRIPTION_PERIODICITY;
                $arrData["MERCHANT_ORDER"] = $api->getMerchantTransId($res["DS_TOKEN_USER"]);
                $arrData["MERCHANT_AMOUNT"] = $this->_formatAmount($this->getQuote()->getStore()->convertPrice($profile->getInitAmount()));
                $arrData["MERCHANT_CURRENCY"] = Mage::app()->getStore()->getCurrentCurrencyCode();                
               
                Mage::helper('paytpvcom')->prepare3ds($arrData);

                $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING);

                return $this;
            }

            // Pago NO Seguro. -->realizamos la suscripcon
            $res = $api->callCreateRecurringPaymentsSuscriptionToken($DS_IDUSER,$DS_TOKEN_USER);
            if ((int)$res['DS_ERROR_ID'] == 0) {
                // add order assigned to the recurring profile with initial fee
                if ((float)$profile->getInitAmount()){
                    $productItemInfo = new Varien_Object;
                    $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_INITIAL);
                    $productItemInfo->setPrice($profile->getInitAmount());

                    $order = $profile->createOrder($productItemInfo);
                    $order->setPaytpvIduser($DS_IDUSER)
                          ->setPaytpvTokenuser($DS_TOKEN_USER);

                    $grandtotal = $this->_formatAmount($this->getQuote()->getStore()->convertPrice($profile->getInitAmount()));
                    $order->setOrderCurrencyCode( Mage::app()->getStore()->getCurrentCurrencyCode())
                       ->setGrandTotal($grandtotal)
                       ->setSubtotal($grandtotal);

                    $order->save();

                    $profile->addOrderRelation($order->getId());
                    $payment->save();

                    // Creamos la factura
                    $payment = $order->getPayment();
                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
                    $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE'])
                    ->setCurrencyCode($order->getBaseCurrencyCode())
                    ->setPreparedMessage("PAYCOMET")
                    ->setParentTransactionId($res['DS_MERCHANT_AUTHCODE'])
                    ->setShouldCloseParentTransaction(true)
                    ->setIsTransactionClosed(1)
                    ->registerCaptureNotification($profile->getInitAmount());

                    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);

                    $this->processSuccess($order,null);
                }
           
                return $this;
            } else {
                if (!$profile->getInitMayFail()) {
                    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED);
                    $profile->save();
                }
                $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                throw new Mage_Payment_Model_Info_Exception($message);
            }
        } else {
            if (!$profile->getInitMayFail()) {
                $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED);
                $profile->save();
            }
            $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }

        return $this;

    }

    /**
     * Fetch RP details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result)
    {
        return $this;
    }

    /**
     * Update RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {

        return $this;

    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile)
    {
        $api = $this->getApi();   

        switch ($profile->getNewState()) {
            case Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE:      $action = 'start'; break;
            case Mage_Sales_Model_Recurring_Profile::STATE_CANCELED:    $action = 'cancel'; break;
            case Mage_Sales_Model_Recurring_Profile::STATE_EXPIRED:     $action = 'cancel'; break;
            case Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED:   $action = 'stop'; break;
            default: return $this;
        }
       
        $additionalInfo = $profile->getAdditionalInfo() ? $profile->getAdditionalInfo() : array();

        if ($action=='cancel'){
            $res = $api->callManageRecurringPaymentsProfileStatus($profile,$action);
             
            if ((int)$res['DS_ERROR_ID'] == 0){
                $profile->save();
                return $this;
            }   
            $message = Mage::helper('payment')->__('Subscription update failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);

        }else{
            $message = Mage::helper('payment')->__($action . ' denied',222);
            throw new Mage_Payment_Model_Info_Exception($message);
        }

    }

    public function canGetRecurringProfileDetails()
    {
        return true;
    }

    /**
     * Round up and cast specified amount to float or string
     * @todo move it to the helper
     *
     * @param string|float $amount
     * @param bool $asFloat
     * @return string|float
     */
    public function _formatAmount($amount, $asFloat = false){
        $amount = sprintf('%.2F', $amount); // "f" depends on locale, "F" doesn't
        return $asFloat ? (float)$amount : $amount;
    }

    public function write_log(){
   
        $domain = Mage::getSingleton('core/cookie')->getDomain();
        $version_modulo = Mage::getConfig()->getNode()->modules->Mage_PayTpvCom->version;
        try {
            $url_log = "http://prestashop.paytpv.com/log_paytpv.php?dominio=".$domain."&version_modulo=".$version_modulo."&tienda=Magento&version_tienda=".Mage::getVersion();
            @file_get_contents($url_log);
        } catch (exception $e){}
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
