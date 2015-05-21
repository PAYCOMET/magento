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

    const IT_OFFSITE = 0;
    const IT_IFRAME = 1;
    const OP_TPVWEB = 0;
    const OP_BANKSTORE = 1;

    const SALE = 0;
    const PREAUTHORIZATION = 1;

    protected $_arrCustomerCards = null;
    protected $_arrCustomerSuscriptions = null;

    // Test Mode Card;
    public $_arrTestCard = array("5325298401138208","5392661198415436","5534958931200656");
    public $_TestCard_mm = 5;
    public $_TestCard_yy = 20;
    public $_TestCard_merchan_cvc2 = 123;

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

    public function isRecurring(){
        $quote = Mage::getModel('checkout/cart')->getQuote();
        foreach ($quote->getAllItems() as $item) {
            if (!$item->getProduct()->getIsRecurring())
                return false;
        }
        return true;
    }

    public function canUseForCurrency($currencyCode){
       
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
        if(self::OP_TPVWEB == parent::getConfigData('operativa'))
            return true;
        return $this->isSecureTransaction();
    }


    public function _getValidParamKey($key){
        if (substr($key, 0, 10) !== 'paytpv3ds_')
            return null;
        
        return substr($key, 10);
    }
    
    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        if ('order_status' == $field)
            return $this->useIframe() ? 'pending' : 'processing';
        
        if ('payment_action' == $field){
            $terminales = $this->getConfigData('terminales');
            $transaction_type = $this->getConfigData('transaction_type');
            if ($this->isSecureTransaction() || ($transaction_type==self::PREAUTHORIZATION)){
                return 'authorize';
            }else{
                return 'authorize_capture';
            }
        }
        $path = 'payment/' . $this->getCode() . '/' . $field; 

        return Mage::getStoreConfig($path, $storeId);
    }

    public function authorize(Varien_Object $payment, $amount)
    {
        parent::authorize($payment, $amount);
        $order = $payment->getOrder();
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        $card = array();
        $remember = (isset($payment_data["remember"]) && $payment_data["card"]==0)?1:0;
        // NUEVA TARJETA o SUSCRIPCION
        if ($payment_data["card"]==0){
            if ($payment_data['cc_number'] && $payment_data['cc_number']) {
                if ($this->getConfigData('environment')!=1){
                    $res = $this->addUser($payment_data);
                // Test Mode
                }else{
                    $res = $this->addUserTest($payment_data);
                }

                $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
                $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
                $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';

                if ((int)$DS_ERROR_ID == 0){

                    $card["paytpv_iduser"] = $DS_IDUSER;
                    $card["paytpv_tokenuser"] = $DS_TOKEN_USER;

                    // Si ha pulsado en el acuerdo y es un usuario registrado guardamos el token
                    if ($remember && $order->getCustomerId()>0){
                        if ($this->getConfigData('environment')!=1){
                            $result = $this->infoUser($DS_IDUSER,$DS_TOKEN_USER);
                        // Test Mode
                        }else{
                            $result['DS_MERCHANT_PAN'] = $payment_data['cc_number'];
                            $result['DS_CARD_BRAND'] = 'MASTERCARD';

                        }
                        $card = $this->save_card($DS_IDUSER,$DS_TOKEN_USER,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$order->getCustomerId());
                    }
                }
            } 
            if ((int)$DS_ERROR_ID != 0) {
                if (!isset($res['DS_ERROR_ID']))
                    $res['DS_ERROR_ID'] = 666;
                $message = Mage::helper('payment')->__('Authorization failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                throw new Mage_Payment_Model_Info_Exception($message);
            }
        // TARJETA EXISTENTE
        }else{
            if ($this->getConfigData('commerce_password') && !$this->verifyPwd($payment_data["userpwd"])){
                $message = Mage::helper('payment')->__('Payment failed. Contraseña incorrecta');
                throw new Mage_Payment_Model_Info_Exception($message);
            }
            $paytpv_iduser =  $payment_data["card"];
            $card = $this->getToken($paytpv_iduser);
        }

        $order->setPaytpvIduser($card["paytpv_iduser"])
              ->setPaytpvTokenuser($card["paytpv_tokenuser"]);
        $order->save();

        if ($this->getConfigData("payment_action")=="authorize"){
            $transaction_type = $this->getConfigData('transaction_type');
            if (!$this->isSecureTransaction() && $transaction_type == self::PREAUTHORIZATION){
                if ($this->getConfigData('environment')!=1){
                    $res = $this->create_preauthorization($order, $amount);
                // Test Mode
                }else{
                    $res['DS_ERROR_ID'] = 0;
                    $res['DS_MERCHANT_AUTHCODE'] = 'TESTAUTHCODE_'.date("dmyHis");
                }
                if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {
                    $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE']);
                    $payment->setIsTransactionClosed(0);
                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
                    $payment->unsLastTransId();
                }else {
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
        if ($this->_isPreauthorizeCapture($payment)){
            $this->_preauthorizeCapture($payment, $amount);
        }else{
            parent::capture($payment, $amount);

            $order = $payment->getOrder();
            $customer_id = $order->getCustomerId();
            $customer = Mage::getModel('customer/customer')->load($customer_id);
            $payment_data = Mage::app()->getRequest()->getParam('payment', array());

            $Secure = ($this->isSecureTransaction())?1:0;

            switch ($Secure){
                // PAGO NO SEGURO
                case 0:
                    $card = $this->authorize($payment, 0);

                    if ($this->getConfigData('environment')!=1){
                        $res = $this->executePurchase($order, $amount);
                    // Test Mode
                    }else{
                        $res['DS_ERROR_ID'] = 0;
                        $res['DS_MERCHANT_AUTHCODE'] = 'TESTAUTHCODE_'.date("dmyHis");
                    }

                    if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {

                        $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE'])
                        ->setCurrencyCode($order->getBaseCurrencyCode())
                        ->setPreparedMessage("PayTPV Pago Correcto")
                        ->setIsTransactionClosed(1)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);

                        // Obtener CardBrand y BicCode
                        if ($this->getConfigData('environment')!=1){
                            $operationcall = $this->getConfigData('operationcall');
                            if ($operationcall==1){         
                                $res = $this->operationCall($order);
                                if ('' == $res[0]->PAYTPV_ERROR_ID || 0 == $res[0]->PAYTPV_ERROR_ID) {
                                    $CardBrand = $res[0]->PAYTPV_OPERATION_CARDBRAND;
                                    $BicCode =  $res[0]->PAYTPV_OPERATION_BICCODE;
                                    $order->setPaytpvCardBrand($CardBrand)
                                    ->setPaytpvBicCode($BicCode);
                                }
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

    public function _isPreauthorizeCapture($payment){
        $idtran = (int)$payment->getTransactionId();
        $lastTransaction = $payment->getTransaction($idtran);
        if (!$lastTransaction || $lastTransaction->getTxnType() != Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH)
            return false;
        return true;
    }

    public function _preauthorizeCapture($payment, $amount){
        $order = $payment->getOrder();
        if ($this->getConfigData('environment')!=1){
            $res = $this->preauthorization_confirm($order, $amount);
        // Test Mode
        }else{
            $order = $payment->getOrder();
            $paytpv_tokenuser = $order->getPaytpvTokenuser();
            // Operacion realizada en Modo Test
            if (strstr($paytpv_tokenuser, 'TESTTOKEN')){
                $res['DS_ERROR_ID'] = 0;
                $res['DS_MERCHANT_AUTHCODE'] = 'TESTAUTHCODE_'.date("dmyHis");
            // Operacion real
            }else{
                $res['DS_RESPONSE' ] = 1;
                $res["DS_ERROR_ID"] = 4002;
            }
        }
        if ('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) {
            $transaction_id = (int)$payment->getTransactionId();
            $payment->setIsTransactionClosed(1);
            $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE']);
            $payment->setData('parent_transaction_id',$transaction_id);
            $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
            
            $payment->unsLastTransId();

            // Obtener CardBrand y BicCode
            if ($this->getConfigData('environment')!=1){
                $operationcall = $this->getConfigData('operationcall');
                if ($operationcall==1){         
                    $res = $this->operationCall($order);
                    if ('' == $res[0]->PAYTPV_ERROR_ID || 0 == $res[0]->PAYTPV_ERROR_ID) {
                        $CardBrand = $res[0]->PAYTPV_OPERATION_CARDBRAND;
                        $BicCode =  $res[0]->PAYTPV_OPERATION_BICCODE;
                        $order->setPaytpvCardBrand($CardBrand)
                        ->setPaytpvBicCode($BicCode);
                    }
                }
            }

        } else {
            if (!isset($res['DS_ERROR_ID']))
                $res['DS_ERROR_ID'] = -1;
            $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }
    }

    public function verifyPwd($password){
        $username = Mage::getSingleton('customer/session')->getCustomer()->getEmail();
        try{
            $blah = Mage::getModel('customer/customer')
            ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
            ->authenticate($username, $password);
            return true;
        }catch( Exception $e ){
            return false;
        }
    }

    public function processSuccess(&$order, $session,$params=null)
    {
        $orderStatus = $this->getConfigData('paid_status');
        if($session){
            $session->unsErrorMessage();
            $session->addSuccess(Mage::helper('payment')->__('Successful payment'));
        }
        $comment = Mage::helper('payment')->__('Successful payment');
        $order->setState($orderStatus, $orderStatus, $comment, true);
        $order->sendNewOrderEmail();
        $order->setEmailSent(true);

        $order->save();
        if ($session){
            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
            Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/success'));
        }
    }

    public function preauthSuccess(&$order, $session,$params=null)
    {
        $orderStatus = $this->getConfigData('paid_status');
        if($session){
            $session->unsErrorMessage();
            $session->addSuccess(Mage::helper('payment')->__('Successful Preauthorization'));
        }
        $comment = Mage::helper('payment')->__('Successful Preauthorization');
        $order->setState($orderStatus, $orderStatus, $comment, true);
        $order->sendNewOrderEmail();
        $order->setEmailSent(true);

        $order->save();
        if ($session){
            Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
            Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/success'));
        }
    }

    public function processFail($order, $session, $message, $comment)
    {
      
        $state = $this->getConfigData('error_status');
        if ($state == Mage_Sales_Model_Order::STATE_CANCELED)
            $order->cancel();
        else
            $order->setState($state, $state, $comment, true);

        $order->save();
        $order->sendOrderUpdateEmail(true, $message);

        $session->addError($message);
        Mage::app()->getResponse()->setRedirect(Mage::getUrl('sales/order/reorder', array('order_id' => $order->getIncrementId())));
    }



    public function getOrderPlaceRedirectUrl(){
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        if (self::OP_BANKSTORE == $this->getConfigData('operativa'))
            if ($this->isSecureTransaction()){
                if ($this->isRecurring())
                    return Mage::getUrl('paytpvcom/standard/bankstorerecurring');
                else
                    return Mage::getUrl('paytpvcom/standard/bankstore');
            }else{
                return null;
            }
        $it = $this->getConfigData('integracion');
        switch ($it) {
            case self::IT_OFFSITE:
                return Mage::getUrl('paytpvcom/standard/redirect');
                break;
            case self::IT_IFRAME:
            default:
                return Mage::getUrl('paytpvcom/standard/iframe');
        }

        return null;
    }

    private function getClient()
    {
        if (null == $this->_client)
            $this->_client = new Zend_Soap_Client('https://secure.paytpv.com/gateway/xml_bankstore.php?wsdl');
        $this->_client->setSoapVersion(SOAP_1_1);
        return $this->_client;
    }

    private function getClientOperation()
    {
        if (null == $this->_clientoperation)
            $this->_clientoperation = new Zend_Soap_Client('https://www.paytpv.com/gateway/xml_operations.php?wsdl');
        $this->_clientoperation->setSoapVersion(SOAP_1_1);
        return $this->_clientoperation;
    }

    public function infoUser($DS_IDUSER, $DS_TOKEN_USER)
    {
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));

        return $this->getClient()->info_user(
            $DS_MERCHANT_MERCHANTCODE, $DS_MERCHANT_TERMINAL, $DS_IDUSER, $DS_TOKEN_USER, $DS_MERCHANT_MERCHANTSIGNATURE, $_SERVER['REMOTE_ADDR']);
    }

    private function executePurchase($order, $amount, $original_ip = '')
    {

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_IDUSER = $order->getPaytpvIduser();
        $DS_TOKEN_USER = $order->getPaytpvTokenuser();
        $DS_MERCHANT_AMOUNT = round($amount * 100);
        $DS_MERCHANT_ORDER = $order->getIncrementId();
        $DS_MERCHANT_CURRENCY = $order->getOrderCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AMOUNT . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
        $DS_MERCHANT_PRODUCTDESCRIPTION = $order->getIncrementId();
        $DS_MERCHANT_OWNER = ''; /*@TODO: Set owner*/
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
            $DS_MERCHANT_OWNER
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
        $arrDatos["PAYTPV_OPERATIONS_CURRENCY"]        = $order->getOrderCurrencyCode();

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
        $DS_MERCHANT_CURRENCY = $order->getOrderCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AMOUNT . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
        $DS_MERCHANT_PRODUCTDESCRIPTION = $order->getIncrementId();
        $DS_MERCHANT_OWNER = ''; /*@TODO: Set owner*/
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
            $DS_MERCHANT_OWNER
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
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
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
        $DS_MERCHANT_CURRENCY = $order->getOrderCurrencyCode();
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
        if ($res['DS_ERROR_ID']==130){
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
        if ($res['DS_ERROR_ID']==130 || $res['DS_ERROR_ID']==1001 ){
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

    private function addUser($payment_data, $original_ip = '')
    {
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_PAN = trim($payment_data['cc_number']);
        $DS_MERCHANT_EXPIRYDATE = str_pad($payment_data['cc_exp_month'], 2, "0", STR_PAD_LEFT) . substr($payment_data['cc_exp_year'], 2, 2);
        $DS_MERCHANT_CVV2 = $payment_data['cc_cid'];

        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_MERCHANT_PAN . $DS_MERCHANT_CVV2 . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
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

    private function addUserTest($payment_data)
    {
        // Test Mode
        // First 100.000 paytpv_iduser for Test_Mode
        if (in_array(trim($payment_data['cc_number']),$this->_arrTestCard) && str_pad($payment_data['cc_exp_month'], 2, "0", STR_PAD_LEFT)==$this->_TestCard_mm && substr($payment_data['cc_exp_year'], 2, 2)==$this->_TestCard_yy && $payment_data['cc_cid']==$this->_TestCard_merchan_cvc2){
            $model = Mage::getModel('paytpvcom/customer');
            $collection = $model->getCollection()
                ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
                ->addFieldToFilter('paytpv_iduser', array('lt' => 100000))
                ->setOrder('paytpv_iduser', 'DESC')
                ->getFirstItem()->getData();
            if (empty($collection) === true){
                $paytpv_iduser = 1;
            }else{
                $paytpv_iduser = $collection["paytpv_iduser"]+1;
            }
            $paytpv_tokenuser = "TESTTOKEN_".date("dmyHis");

            $res["DS_IDUSER"] = $paytpv_iduser;
            $res["DS_TOKEN_USER"] = $paytpv_tokenuser;
            $res["DS_ERROR_ID"] = 0;
        }else{
            $res["DS_ERROR_ID"] = 4001;
        }   
        return $res;
    }

     

    private function removeUser($idUser, $tokeUser)
    {

        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_IDUSER = $idUser;
        $DS_TOKEN_USER = $tokeUser;
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1( $DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $_SERVER['REMOTE_ADDR'];
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
        $DS_ORIGINAL_IP = $_SERVER['REMOTE_ADDR'];
        
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
        }
        return "es";
    }

    
    public function getStandardCheckoutFormFields()
    {
        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);

        $convertor = Mage::getModel('sales/convert_order');
        $invoice = $convertor->toInvoice($order);
        $currency = $order->getOrderCurrencyCode();
        $amount = round($order->getGrandTotal() * 100);
        if ($currency == 'JPY')
            $amount = round($order->getGrandTotal());

       
        $client = $this->getConfigData('client');
        $user = $this->getConfigData('user');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language = $this->calcLanguage(Mage::app()->getLocale()->getLocaleCode());

        $operation = "1";

        $signature = md5($client . $user . $terminal . $operation . $order->getIncrementId() . $amount . $currency . md5($pass));

        $sArr = array
        (
            'ACCOUNT' => $client,
            'USERCODE' => $user,
            'TERMINAL' => $terminal,
            'OPERATION' => $operation,
            'REFERENCE' => $order_id,
            'AMOUNT' => $amount, // convert to minor units
            'CURRENCY' => $currency,
            'SIGNATURE' => $signature,
            'CONCEPT' => '',
            'LANGUAGE' => $language,
            'URLOK' => Mage::getUrl('paytpvcom/standard/recibo'),
            'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')
        );
        //
        // Make into request data
        //
        $sReq = '';
        $rArr = array();
        foreach ($sArr as $k => $v) {
            /* replacing & char with and. otherwise it will break the post */
            $value = str_replace("&", "and", $v);
            $rArr[$k] = $value;
            $sReq .= '&' . $k . '=' . $value;
        }

        return $rArr;
    }

    public function getBankStoreFormFields($operation=1)
    {
        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);
        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language_settings = strtolower(Mage::app()->getStore()->getCode());

        if ($language_settings == "default") {
            $language = "ES";
        } else {
            $language = "EN";
        }
        $amount = $currency='';
        $amount = round($order->getGrandTotal() * 100);
        $currency = $order->getOrderCurrencyCode();
        

        // execute purchase
        if ($operation == 1){
            $signature = md5($client . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
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
                '3DSECURE' => $this->getConfigData('secure_first')
            );
        }


        // add_user
        if ($operation == 107){
            $order = Mage::getSingleton('customer/session')->getCustomer()->getId();
            $signature = md5($client . $terminal . $operation . $order . md5($pass));
            $sArr = array
            (
                'MERCHANT_MERCHANTCODE' => $client,
                'MERCHANT_TERMINAL' => $terminal,
                'OPERATION' => $operation,
                'LANGUAGE' => $language,
                'MERCHANT_MERCHANTSIGNATURE' => $signature,
                'MERCHANT_ORDER' => $order,
                'URLOK' => Mage::getUrl('paytpvcom/standard/tarjetas'),
                'URLKO' => Mage::getUrl('paytpvcom/standard/cancel')                
            );
        }

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

        $language_settings = strtolower(Mage::app()->getStore()->getCode());

        if ($language_settings == "default") {
            $language = "ES";
        } else {
            $language = "EN";
        }
        $amount = $currency='';
        $amount = round($order->getGrandTotal() * 100);
        $currency = $order->getOrderCurrencyCode();

        $paytpv_iduser = $order->getPaytpvIduser();
        $paytpv_tokenuser = $order->getPaytpvTokenuser();

        // execute_purchase_token
        if ($operation == 109){
            $signature = md5($client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
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
        }

        // execute_preauthorization_token
        if ($operation == 111){
            $signature = md5($client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
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
        }


        return $sArr;
    }


    public function getBankStorerecurringTokenFormFields($arrDatos)
    {
        $operation=110; // create_subscription_token;

        $order_id = $arrDatos["MERCHANT_ORDER"];

        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $language_settings = strtolower(Mage::app()->getStore()->getCode());

        if ($language_settings == "default") {
            $language = "ES";
        } else {
            $language = "EN";
        }
        $amount = $currency='';
        $amount = round($arrDatos["MERCHANT_AMOUNT"] * 100);
        $currency = $arrDatos["MERCHANT_CURRENCY"];

        $paytpv_iduser = $arrDatos["IDUSER"];
        $paytpv_tokenuser = $arrDatos["TOKEN_USER"];
        
        $subscription_stratdate = $arrDatos["DS_SUBSCRIPTION_STARTDATE"];

        $subs_periodicity = $arrDatos["DS_SUBSCRIPTION_PERIODICITY"];
        $subscription_enddate = $arrDatos["DS_SUBSCRIPTION_ENDDATE"];


        $signature = md5($client . $paytpv_iduser . $paytpv_tokenuser . $terminal . $operation . $order_id . $amount . $currency . md5($pass));
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
        

        return $sArr;
    }

    
    // Cargar tarjetas tokenizadas
    public function loadCustomerCards(){
        $model = Mage::getModel('paytpvcom/customer');
        $collection = $model->getCollection()
            ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
            ->setOrder("paytpv_iduser", 'DESC');
        $arrCards = array();
        foreach($collection as $item){
            $arrCards[] =  $item->getData();
        }
        $this->setCustomerCards($arrCards);
        return $arrCards;
    }


    public function getStandardFormTemplate()
    {
        $op = $this->getConfigData('operativa');
        if (self::OP_BANKSTORE == $op){
            $this->loadCustomerCards();
            $terminales = $this->getConfigData('terminales');
            return 'paytpvcom/form_bankstore_ws.phtml';
        }else{
            return 'paytpvcom/form.phtml';
        }
    }

    function isSecureTransaction(){
        $op = $this->getConfigData('operativa');
        $terminales = $this->getConfigData('terminales');
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        // Transaccion Segura:
            // Si es TPVWEB
            // Si es Bankstore y solo tiene Terminal Seguro
            if (self::OP_TPVWEB == $op || (self::OP_BANKSTORE == $op && $terminales==0))
                return true;   
       
            // Si esta definido que el pago es 3d secure y no estamos usando una tarjeta tokenizada
            if ($this->getConfigData('secure_first') && $payment_data["card"]==0)
                return true;

            // Si se supera el importe maximo para compra segura
            if ($terminales==2 && ($this->getConfigData('secure_amount')!="" && $this->getConfigData('secure_amount') < $this->getCurrentOrderAmount()))
                return true;

            // Si esta definido como que la primera compra es Segura y es la primera compra aunque este tokenizada
            if ($terminales==2 && $this->getConfigData('secure_first') && $payment_data["card"]>0 && $this->isFirstPurchaseToken($payment_data["card"]))
                return true;
        
        return false;
    }

    public function getToken($paytpv_iduser){
        $model = Mage::getModel('paytpvcom/customer');
        $card = $model->getCollection()->addFilter("paytpv_iduser",$paytpv_iduser,"and")->getFirstItem()->getData();
        return $card;
    }

    
    /**
     * removeCard
     *
     * 
     */
    function removeCard($customer_id){
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
        try{
            $customer = $model->getCollection()
                        ->addFilter("customer_id",$customer_id,"and")
                        ->getFirstItem()->delete();
        } catch (Exception $e){
            return false;
        }
        return true;
    }


    /**
     * saveDescriptionCard
     *
     * 
     */
    function saveDescriptionCard($customer_id,$card_desc){

        $paytpv_cc = $card_name;
        $data = array("customer_id"=>$customer_id,"card_desc"=>$card_desc);
        $model = Mage::getModel('paytpvcom/customer')->setData($data);
        try {
            $insertId = $model->save()->getId();
            //echo "CUSTOMER Data successfully inserted. Insert ID: ".$insertId;
        } catch (Exception $e){
            return false;
        }
        return true;
    }


    /**
     * addCard
     *
     * 
     */
    function addCard($params){
        $id_customer = Mage::getSingleton('customer/session')->getCustomer()->getId();

        if ($id_customer>0){
            if ($this->getConfigData('environment')!=1){
                $res = $this->addUser($params);
            // Test Mode
            }else{
                $res = $this->addUserTest($params);
            }

            $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
            $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
            $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';

            if ((int)$DS_ERROR_ID == 0){

                $card["paytpv_iduser"] = $DS_IDUSER;
                $card["paytpv_tokenuser"] = $DS_TOKEN_USER;

                // Si ha pulsado en el acuerdo y es un usuario registrado guardamos el token
                if ($id_customer>0){
                    if ($this->getConfigData('environment')!=1){
                        $result = $this->infoUser($DS_IDUSER,$DS_TOKEN_USER);
                    // Test Mode
                    }else{
                        $result['DS_MERCHANT_PAN'] = $params['cc_number'];
                        $result['DS_CARD_BRAND'] = 'MASTERCARD';
                    }
                    $this->save_card($DS_IDUSER,$DS_TOKEN_USER,$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$id_customer);
                }
                return "";
            }else{
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
        return $order->getGrandTotal();
    }

//
// Simply return the url for the paytpv.com Payment window
//
    public function getPayTpvUrl()
    {
        return "https://www.paytpv.com/gateway/fsgateway.php";
    }

    public function getPayTpvIframeUrl()
    {
        return "https://www.paytpv.com/gateway/ifgateway.php";
    }

    public function getPayTpvBankStoreUrl()
    {
        if ($this->getConfigData('environment')!=1){
            return "https://secure.paytpv.com/gateway/bnkgateway.php";
        // Test Mode
        }else{
            return Mage::getUrl('paytpvcom/standard/bankstore3dstest');
        }
    }

    /*public function refund(Varien_Object $payment, $amount)
    {
        parent::refund($payment, $amount);
       
        //@TODO comprobar devolución completa
        $res = $this->executeRefund($payment,$amount);
        if (('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) && 1 == $res['DS_RESPONSE']) {
            $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE']);
            $payment->setIsTransactionClosed(1);
            $payment->setTransactionAdditionalInfo(
                Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,
                $res);
        } else {
            if (!isset($res['DS_ERROR_ID']))
                $res['DS_ERROR_ID'] = -1;
            $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }
        return $this;
    } //refund api
    */

    public function refund(Varien_Object $payment, $amount)
    {
        parent::refund($payment, $amount);
       
        /*@TODO comprobar devolución completa*/
        if ($this->getConfigData('environment')!=1){
            $res = $this->executeRefund($payment,$amount);
        // Test Mode
        }else{
            $order = $payment->getOrder();
            $paytpv_tokenuser = $order->getPaytpvTokenuser();
            // Operacion realizada en Modo Test
            if (strstr($paytpv_tokenuser, 'TESTTOKEN')){
                $res['DS_RESPONSE'] = 1;
                $res["DS_ERROR_ID"] = 0;
                $res['DS_MERCHANT_AUTHCODE'] = 'TESTAUTHCODE_'.date("dmyHis");
            // Operacion real
            }else{
                $res[ 'DS_RESPONSE' ] = 1;
                $res["DS_ERROR_ID"] = 4000;
            }
        }
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

    public function save_card($paytpv_iduser,$paytpv_tokenuser,$paytpv_cc,$paytpv_brand,$id_customer){
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
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $payment
    )
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
                $arrData["MERCHANT_ORDER"] = $api->getMerchantTransId();
                $arrData["MERCHANT_AMOUNT"] = $profile->getInitAmount();
                $arrData["MERCHANT_CURRENCY"] = $profile->getCurrencyCode();

                Mage::helper('paytpvcom')->prepare3ds($arrData);

                $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING);

                return $this;
            }

            // Pago NO Seguro. -->realizamos la suscripcon
            $res = $api->callCreateRecurringPaymentsSuscriptionToken($DS_IDUSER,$DS_TOKEN_USER);
            if ((int)$res['DS_ERROR_ID'] == 0){
                // add order assigned to the recurring profile with initial fee
                if ((float)$profile->getInitAmount()){
                    $productItemInfo = new Varien_Object;
                    $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_INITIAL);
                    $productItemInfo->setPrice($profile->getInitAmount());

                    $order = $profile->createOrder($productItemInfo);
                    $order->setPaytpvIduser($DS_IDUSER)
                          ->setPaytpvTokenuser($DS_TOKEN_USER);
                    $order->save();

                    $profile->addOrderRelation($order->getId());
                    $payment->save();

                    // Creamos la factura
                    $payment = $order->getPayment();
                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$res);
                    $payment->setTransactionId($res['DS_MERCHANT_AUTHCODE'])
                    ->setCurrencyCode($order->getBaseCurrencyCode())
                    ->setPreparedMessage("PayTPV")
                    ->setParentTransactionId($res['DS_MERCHANT_AUTHCODE'])
                    ->setShouldCloseParentTransaction(true)
                    ->setIsTransactionClosed(1)
                    ->registerCaptureNotification($profile->getInitAmount());

                    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);

                    $this->processSuccess($order,null,$params);
                }
           
                return $this;
            }else{
                if (!$profile->getInitMayFail()){
                    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED);
                    $profile->save();
                }
                $message = Mage::helper('payment')->__('Payment failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
                throw new Mage_Payment_Model_Info_Exception($message);
            }
        }else {
            if (!$profile->getInitMayFail()){
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
            if ($this->getConfigData('environment')!=1){
                $res = $api->callManageRecurringPaymentsProfileStatus($profile,$action);
            // Test Mode
            }else{
                if (strstr($additionalInfo["paytpv_tokenuser"],'TESTTOKEN')){
                    $res['DS_ERROR_ID']=0;
                }else{
                    $res['DS_ERROR_ID']=4003;
                }
            }
             
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
            '145' => Mage::helper('payment')->__('PUC error message. Contact PayTPV'),
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
            '1001' => Mage::helper('payment')->__('User not found. Contact PAYTPV'),
            '1002' => Mage::helper('payment')->__('Gateway error response. Contact PAYTPV'),
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
            '1028' => Mage::helper('payment')->__('The account is not active. Contact PAYTPV'),
            '1029' => Mage::helper('payment')->__('The account is not certified. Contact PAYTPV'),
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
            '1122' => Mage::helper('payment')->__('User not found. Contact PAYTPV'),
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
