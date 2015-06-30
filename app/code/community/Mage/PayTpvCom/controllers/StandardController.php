<?php

/**
 * PayTPV Standard Checkout Controller
 *
 */
class Mage_PayTpvCom_StandardController extends Mage_Core_Controller_Front_Action implements Mage_Payment_Model_Recurring_Profile_MethodInterface
{
    //
    // Flag only used for callback
    protected $_callbackAction = false;

    protected function _expireAjax()
    {
        if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
            $this->getResponse()->setHeader('HTTP/1.1', '403 Session Expired');
            exit;
        }
    }

    /**
     * Get singleton with PayTPV strandard order transaction information
     *
     * @return PayTpvCom_Model_Standard
     */
    public function getStandard()
    {
        return Mage::getSingleton('paytpvcom/standard');
    }

    public function recurringredirectAction()
    {

    }

    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function redirectAction()
    {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastOrderId()) {
            $session->setPayTpvComStandardQuoteId($session->getQuoteId());
            $this->loadLayout();
            $this->renderLayout();
        }
        return;
    }

    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function iframeAction()
    {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastOrderId()) {
            $session->setPayTpvComStandardQuoteId($session->getQuoteId());
            $this->loadLayout();
            $this->renderLayout();
        }
        return;
    }

    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function BankstoreAction()
    {
        $session = Mage::getSingleton('checkout/session');
        if ($session->getLastOrderId()) {
            $session->setPayTpvComStandardQuoteId($session->getQuoteId());
            $this->loadLayout();
            $this->renderLayout();
        }
        return;
    }

    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function Bankstore3dstestAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $this->loadLayout();
        $this->renderLayout();
        
        return;
    }

     /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function BankstorerecurringAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $this->loadLayout();
        $this->renderLayout();
        return;
    }


    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function TarjetasAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $this->loadLayout();
        $this->renderLayout();
        
        return;
    }


    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function ActionsAction()
    {
        $params = $this->getRequest()->getParams();
        $model = Mage::getModel('paytpvcom/standard');

        switch ($params["action"]){
            case "removeCard":
                if ($model->removeCard($params["customer_id"]))
                    die('0');
                else die('1');
                
                break;
             case "saveDescriptionCard":
                if ($model->saveDescriptionCard($params["customer_id"],$params["card_desc"]))
                    die('0');
                else die('1');
                
                break;
            case "cancelSuscription":
                if ($model->cancelSuscription($params["suscription_id"]))
                    die('0');
                else die('1');
                break;
            case "addCard":
                $response = $model->addCard($params);
                die($response);
                break;
        }       
        return;
    }


    /**
     * When a customer chooses PayTpvCom on Checkout/Payment page
     *
     */
    public function conditionsAction()
    {
        $params = $this->getRequest()->getParams();
        $model = Mage::getModel('paytpvcom/standard');

        $locale = Mage::app()->getLocale()->getLocaleCode();
        $arr_Locale = array("es_ES","en_US");

        if (!in_array($arr_Locale,$locale))
            $locale = "es_ES";

        $file = Mage::getBaseDir('locale') . DS .  $locale . DS . "template" . DS . "paytpvcom" . DS . "conditions.html";

        print(file_get_contents($file));

    }



    /**
     * Acción a realizar tras error en el pago
     */
    public function cancelAction($firmaValida=false)
    {
        $params = $this->getRequest()->getParams();
        $model = Mage::getModel('paytpvcom/standard');

        $message = '';
        if (count($params) > 0) {
            if ($params['h'] == md5($model->getConfigData('user').$params['r'].$model->getConfigData('pass').$params["ret"]))
                $firmaValida = true;

            if ($firmaValida && $params['ret'] != "0") {
                $message = Mage::helper('payment')->__("Payment failed (Err. code %s)",$params['ret']);
                $comment = Mage::helper('payment')->__('Payment refused from PayTPV.com. Reason: #%s - %s', $params['ret'], $message);
            }
        }

        if (!$message) { // Informacion devuelta no valida
            $message = Mage::helper('payment')->__("Payment failed (Err. code %s)",-1);
            $comment = Mage::helper('payment')->__("Payment failed (Err. code %s)",-1);
        }

        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order')->load($session->getLastOrderId());

        $model->processFail($order,$session,$message,$comment);

        return;
    }

    /**
     * Página a la que vuelvge el usuario
     */
    public function callbackAction()
    {
        Mage::log(http_build_query($_REQUEST), null, 'paytpvcom.log', true);
        $model = Mage::getModel('paytpvcom/standard');
        $order = Mage::getModel('sales/order');
        $params = $this->getRequest()->getParams();
        $firmaValida = false;

        if (isset($params['h'])) {//Notificación TPV WEB
            if ($params['h'] == md5($model->getConfigData('user').$params['r'].$model->getConfigData('pass').$params["ret"]))
                $firmaValida = true;
            $order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
            $session = Mage::getSingleton('checkout/session');
            $session->setQuoteId($session->getPayTpvComStandardQuoteId());
            if ($firmaValida && $params['ret'] == 0) {
                $model->processSuccess($order,$session,$params);
            } else {
                $this->cancelAction();
            }
        }
        // NOTIFICACIÓN BANK STORE
        // (execute_purchase)
        if (($params['TransactionType']==="1" || $params['TransactionType']==="109_TEST")
            AND $params['Order']
            AND $params['Response']
            AND $params['ExtendedSignature'])
        {
            $importe  = number_format($params['Amount']/ 100, 2);
            $ref = $params['Order'];
            $result = $params['Response']=='OK'?0:-1;
            $sign = $params['ExtendedSignature'];
            $esURLOK = false;
            $session = null;


            if ($model->getConfigData('environment')!=1){
                $local_sign = md5(  $model->getConfigData('client').
                                    $model->getConfigData('terminal').
                                    $params['TransactionType'].
                                    $ref.
                                    $params['Amount'].
                                    $params['Currency'].
                                    md5($model->getConfigData('pass')).
                                    $params['BankDateTime'].
                                    $params['Response']);   
            // Modo Test
            }else{
                $local_sign = md5(  $model->getConfigData('client').
                                    $params['IdUser'].
                                    $params['TokenUser'].
                                    $model->getConfigData('terminal').
                                    "109".
                                    $ref.
                                    $params['Amount'].
                                    $params['Currency'].
                                    md5($model->getConfigData('pass')));  
                $session = Mage::getSingleton('checkout/session');
            }
            
            if ($sign!=$local_sign || $params['Response']!="OK") die('Error en el pago');
            else{
                $id_order = $ref;
                $order->loadByIncrementId($id_order);

                if($order->getId()>0 && (isset($params['CardBrand']) || isset($params['BicCode']))){
                    $order
                    ->setPaytpvCardBrand($params['CardBrand'])
                    ->setPaytpvBicCode($params['BicCode']);
                    $order->save();
                }

                if(isset($params['IdUser']) && isset($params['TokenUser'])){
                    
                    // Creamos la factura
                    $payment = $order->getPayment();

                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$params);
                    $payment->setTransactionId($params['AuthCode'])
                        ->setCurrencyCode($order->getBaseCurrencyCode())
                        ->setPreparedMessage("PayTPV Pago Correcto.")
                        ->setIsTransactionClosed(1)
                        ->registerCaptureNotification($order->getBaseGrandTotal());

                    $order
                        ->setPaytpvIduser($params['IdUser'])
                        ->setPaytpvTokenuser($params['TokenUser']);
                    $order->save();

                    $model->processSuccess($order,$session,$params);
                }
            }
        // (preathorization)       
        }else if (($params['TransactionType']==="3" || $params['TransactionType']==="111_TEST")
            AND $params['Order']
            AND $params['Response']
            AND $params['ExtendedSignature'])
        {
            $importe  = number_format($params['Amount']/ 100, 2);
            $ref = $params['Order'];
            $result = $params['Response']=='OK'?0:-1;
            $sign = $params['ExtendedSignature'];
            $esURLOK = false;
            $sign = $params['ExtendedSignature'];
            $session = null;

            if ($model->getConfigData('environment')!=1){
                $local_sign = md5(  $model->getConfigData('client').
                                $model->getConfigData('terminal').
                                $params['TransactionType'].
                                $ref.
                                $params['Amount'].
                                $params['Currency'].
                                md5($model->getConfigData('pass')).
                                $params['BankDateTime'].
                                $params['Response']);
            // Modo Test
            }else{
                $local_sign = md5(  $model->getConfigData('client').
                                    $params['IdUser'].
                                    $params['TokenUser'].
                                    $model->getConfigData('terminal').
                                    "111".
                                    $ref.
                                    $params['Amount'].
                                    $params['Currency'].
                                    md5($model->getConfigData('pass')));  
                $session = Mage::getSingleton('checkout/session');
            }

            if ($sign!=$local_sign || $params['Response']!="OK") die('Error en preauthorization');
            else{
                $id_order = $ref;
                $order->loadByIncrementId($id_order);

                if($order->getId()>0 && (isset($params['CardBrand']) || isset($params['BicCode']))){
                    $order
                    ->setPaytpvCardBrand($params['CardBrand'])
                    ->setPaytpvBicCode($params['BicCode']);
                    $order->save();
                }

                if(isset($params['IdUser']) && isset($params['TokenUser'])){
                    
                    $payment = $order->getPayment();
                    $newTransactionType = Mage_Sales_Model_Order_Payment_Transaction::TYPE_AUTH;
                    $message = Mage::helper('payment')->__('Preautorizacion confirmada'); 
                    
                    $payment->setTransactionId($params['AuthCode']);
                    $payment->setIsTransactionClosed(0);
                    $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$params);
                    $transaction = $payment->addTransaction($newTransactionType, null, false , $message);
                    $payment->unsetData('is_transaction_closed');
                    $payment->unsLastTransId();

                    $payment->setSkipTransactionCreation(true);

                    $order
                        ->setPaytpvIduser($params['IdUser'])
                        ->setPaytpvTokenuser($params['TokenUser']);
                    $order->save();

                    $model->preauthSuccess($order,$session,$params);
                }
            } 
 
        // (add_user)
        }else if ($params['TransactionType']==="107"){
            
            $ref = $params['Order'];
            $sign = $params['Signature'];
            $esURLOK = false;
            $local_sign = md5(  $model->getConfigData('client').
                                $model->getConfigData('terminal').
                                $params['TransactionType'].
                                $ref.
                                $params['DateTime'].md5($model->getConfigData('pass')));

            if ($sign!=$local_sign) die('Error 2');

            $id_customer = $ref;
            $result = $model->infoUser($params['IdUser'],$params['TokenUser']);
            $model->save_card($params['IdUser'],$params['TokenUser'],$result['DS_MERCHANT_PAN'],$result['DS_CARD_BRAND'],$id_customer);
            
            die('Usuario Registrado');
        // (create_subscription)
        }else if ($params['TransactionType']==="9" || $params['TransactionType']=="110_TEST"){
            $suscripcion = 1;  // Inicio Suscripcion
            $importe  = number_format($params['Amount']/ 100, 2);
            $ref = $params['Order'];

            // Miramos a ver si es la orden inicial o un pago de la suscripcion (orden[Iduser]Fecha)
            $datos = explode("[",$ref);
            $ref = $datos[0];

            if (sizeof($datos)>1){
                $datos2 = explode("]",$params['Order']);
                $fecha = $datos2[sizeof($datos2)-1];

                $fecha_act = date("Ymd");
                // Si la fecha no es la de hoy es un pago de cuota suscripcion
                if ($fecha!=$fecha_act)
                    $suscripcion = 2;   // Pago cuota suscripcion

                $datos3 = explode("]",$datos[1]);
                $paytpv_iduser = $datos3[0];
            }else{
                $paytpv_iduser = $params['IdUser'];
            }


            // Por si es un pago de suscripcion.
            $result = $params['Response']=='OK'?0:-1;
            $sign = $params['ExtendedSignature'];
            $esURLOK = false;
            $session = null;
            
            if ($model->getConfigData('environment')!=1){
                $local_sign = md5(  $model->getConfigData('client').
                                $model->getConfigData('terminal').
                                $params['TransactionType'].
                                $params['Order'].
                                $params['Amount'].
                                $params['Currency'].
                                md5($model->getConfigData('pass')).
                                $params['BankDateTime'].
                                $params['Response']);
            // Modo Test
            }else{
                $local_sign = md5(  $model->getConfigData('client').
                                    $params['IdUser'].
                                    $params['TokenUser'].
                                    $model->getConfigData('terminal').
                                    "110".
                                    $ref.
                                    $params['Amount'].
                                    $params['Currency'].
                                    md5($model->getConfigData('pass')));  
                $session = Mage::getSingleton('checkout/session');
            }

            if ($sign!=$sign) die('Error 3');
            else{

                $recurringProfileCollection = Mage::getModel('sales/recurring_profile')
                    ->getCollection()
                    ->addFieldToFilter('additional_info', array(
                        array('like' => '%paytpv_iduser_'.$paytpv_iduser .'_%'),
                    ))
                    ->setOrder('created_at', 'DESC');
                
                $profile = $recurringProfileCollection->getFirstItem();
                $paytpv_tokenuser = substr($profile->getReferenceId(),2);

                // Pago cuota suscripcion
                if ($suscripcion==2){

                    $order = $this->_createOrder($profile);

                    $grandtotal = $importe;
                    $order->setOrderCurrencyCode($params['Currency'])
                       ->setGrandTotal($grandtotal)
                       ->setSubtotal($grandtotal);

                    $payment = $order->getPayment();
                    $payment->setTransactionId($params['AuthCode']. '-rebill')->setIsTransactionClosed(1);
                    $order->save();
                    $profile->addOrderRelation($order->getId());
                    
                // Suscripcion
                }else{
                    $profile->load();
                    // add order assigned to the recurring profile with initial fee

                    if ((float)$profile->getInitAmount()){
                        $productItemInfo = new Varien_Object;
                        $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_INITIAL);
                        $productItemInfo->setPrice($profile->getInitAmount());
                        
                        $order = $profile->createOrder($productItemInfo);

                        $grandtotal = $importe;
                        $order->setOrderCurrencyCode($params['Currency'])
                            ->setGrandTotal($grandtotal)
                            ->setSubtotal($grandtotal);

                        $payment = $order->getPayment();
                        $payment->setTransactionId($params['AuthCode']. '-initial')->setIsTransactionClosed(1);
                        $order->save();
                        $profile->addOrderRelation($order->getId());
                        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);
                        $profile->save();
                    }
                }

                // Creamos la factura
                $payment = $order->getPayment();
                $payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$params);
                $payment->setTransactionId($params['AuthCode'])
                ->setCurrencyCode($order->getBaseCurrencyCode())
                ->setPreparedMessage("PayTPV")
                ->setParentTransactionId($params['AuthCode'])
                ->setShouldCloseParentTransaction(true)
                ->setIsTransactionClosed(1)
                ->registerCaptureNotification($order->getBaseGrandTotal());

                $order->setPaytpvIduser($paytpv_iduser)
                      ->setPaytpvTokenuser($paytpv_tokenuser);

                if($order->getId()>0 && (isset($params['CardBrand']) || isset($params['BicCode']))){
                    $order
                    ->setPaytpvCardBrand($params['CardBrand'])
                    ->setPaytpvBicCode($params['BicCode']);
                    $order->save();
                }

                $order->save();

                $model->processSuccess($order,$session,$params);
            }
        
        }
    }

    public function adduserokAction()
    {
        $message = Mage::helper('payment')->__('Data succesfully saved');
        $this->renderAddUserResult($message);
        exit;
    }

    public function addusernokAction()
    {
        $message = Mage::helper('payment')->__('Incorrect input data');
        $this->renderAddUserResult($message);
        exit;
    }

    private function renderAddUserResult($message)
    {
        $block = $this->getLayout()->createBlock('core/template')
                ->setTemplate('paytpvcom/form_bankstore.phtml')
                ->setMessage($message)
                ->setPaytpvCc(Mage::getSingleton('customer/session')->getCustomer()->getPaytpvCc());

        echo $block->toHtml();
    }

    /**
     * Página a la que vuelvge el usuario
     */
    public function reciboAction()
    {
        $model = Mage::getModel('paytpvcom/standard');
        $session = Mage::getSingleton('checkout/session');

        $order = Mage::getModel('sales/order');
        $order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
        $session->setQuoteId($session->getPayTpvComStandardQuoteId());
        $params = $this->getRequest()->getParams();

        $firmaValida = false;

        if (count($params) > 0) {
            if ($params['h'] == md5($model->getConfigData('user').$params['r'].$model->getConfigData('pass').$params["ret"]))
                $firmaValida = true;

            if ($firmaValida && $params['ret'] == 0) {
                $orderStatus = $model->getConfigData('paid_status');
                $session->unsErrorMessage();
                $comment = Mage::helper('payment')->__('Successful payment');
                $session->addSuccess($comment);
                $order->setState($orderStatus, $orderStatus, $comment, true);
                $order->sendNewOrderEmail();
                $order->setEmailSent(true);
                $order->save();
                Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
                $this->_redirect('checkout/onepage/success');
            } else {
                $this->cancelAction();
            }
        }
    }


    /**
     * Página a la que vuelvge el usuario
     */
    public function reciboBankstoreAction()
    {
        $model = Mage::getModel('paytpvcom/standard');
        $session = Mage::getSingleton('checkout/session');

        $order = Mage::getModel('sales/order');
        $order->load(Mage::getSingleton('checkout/session')->getLastOrderId());
        $session->setQuoteId($session->getPayTpvComStandardQuoteId());
        $params = $this->getRequest()->getParams();

        if (count($params) > 0){
            if ($params['ret'] == 0) {
                $orderStatus = $model->getConfigData('paid_status');
                $session->unsErrorMessage();
                $comment = Mage::helper('payment')->__('Successful payment');
                $session->addSuccess($comment);
                
                Mage::getSingleton('checkout/cart')->truncate();
                Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
                $this->_redirect('checkout/onepage/success');
            } else {
                $this->cancelAction();
            }
        }
    }

    protected function _createOrder(Mage_Sales_Model_Recurring_Profile $profile){
        
        $orderInfo          = is_string($profile->getOrderInfo())           ? unserialize($profile->getOrderInfo()) : $profile->getOrderInfo();
        $orderItemInfo      = is_string($profile->getOrderItemInfo())       ? unserialize($profile->getOrderItemInfo()) : $profile->getOrderItemInfo();
        $billingAddressInfo = is_string($profile->getBillingAddressInfo())  ? unserialize($profile->getBillingAddressInfo()) : $profile->getBillingAddressInfo();
        $shippingAddressInfo= is_string($profile->getShippingAddressInfo()) ? unserialize($profile->getShippingAddressInfo()) : $profile->getShippingAddressInfo();
        
        $item = Mage::getModel('sales/order_item')
            ->setName(          'Suscripcion Perfil Repetitivo #' . $profile->getReferenceId())
            ->setQtyOrdered(    $orderItemInfo['qty'] )
            ->setBaseOriginalPrice($profile->getBillingAmount() )
            ->setPrice(         $profile->getBillingAmount() )
            ->setBasePrice(     $profile->getBillingAmount() )
            ->setRowTotal(      $profile->getBillingAmount() )
            ->setBaseRowTotal(  $profile->getBillingAmount() )
            ->setTaxAmount(     $profile->getTaxAmount() )
            ->setShippingAmount($profile->getShippingAmount() )
            ->setPaymentType(   Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_REGULAR)
            ->setIsVirtual(     $orderItemInfo['is_virtual'] )
            ->setWeight(        $orderItemInfo['weight'] )
            ->setId(null);
        
        $grandTotal = $profile->getBillingAmount() + $profile->getShippingAmount() + $profile->getTaxAmount();

        $order = Mage::getModel('sales/order');

        $billingAddress = Mage::getModel('sales/order_address')
            ->setData($billingAddressInfo)
            ->setId(null);

        $shippingAddress = Mage::getModel('sales/order_address')
            ->setData($shippingAddressInfo)
            ->setId(null);

        $payment = Mage::getModel('sales/order_payment')
            ->setMethod($profile->getMethodCode());

        $transferDataKays = array(
            'store_id',             'store_name',           'customer_id',          'customer_email',
            'customer_firstname',   'customer_lastname',    'customer_middlename',  'customer_prefix',
            'customer_suffix',      'customer_taxvat',      'customer_gender',      'customer_is_guest',
            'customer_note_notify', 'customer_group_id',    'customer_note',        'shipping_method',
            'shipping_description', 'base_currency_code',   'global_currency_code', 'order_currency_code',
            'store_currency_code',  'base_to_global_rate',  'base_to_order_rate',   'store_to_base_rate',
            'store_to_order_rate'
        );

        foreach ($transferDataKays as $key) {
            if (isset($orderInfo[$key])) {
                $order->setData($key, $orderInfo[$key]);
            } elseif (isset($shippingAddressInfo[$key])) {
                $order->setData($key, $shippingAddressInfo[$key]);
            }
        }

        $order
            ->setState(             Mage_Sales_Model_Order::STATE_NEW )
            ->setBaseToOrderRate(   $orderInfo['base_to_quote_rate'])
            ->setStoreToOrderRate(  $orderInfo['store_to_quote_rate'])
            ->setOrderCurrencyCode( $orderInfo['quote_currency_code'])
            ->setBaseSubtotal(      $profile->getBillingAmount() )
            ->setSubtotal(          $profile->getBillingAmount() )
            ->setBaseShippingAmount($profile->getShippingAmount() )
            ->setShippingAmount(    $profile->getShippingAmount() )
            ->setBaseTaxAmount(     $profile->getTaxAmount() )
            ->setTaxAmount(         $profile->getTaxAmount() )
            ->setBaseGrandTotal(    $grandTotal)
            ->setGrandTotal(        $grandTotal)
            ->setIsVirtual(         $orderItemInfo['is_virtual'] )
            ->setWeight(            $orderItemInfo['weight'] )
            ->setTotalQtyOrdered(   $orderItemInfo['qty'] )
            ->setBillingAddress(    $billingAddress )
            ->setShippingAddress(   $shippingAddress )
            ->setPayment(           $payment );
        
        $order->addItem($item);

        return $order;
    }


    /* RECURRING PROFILES */

    /**
     * Validate RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        return $this->getStandard()->validateRecurringProfile($profile);
    }

    /**
     * Submit RP to the gateway
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info              $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo
    )
    {
        $token = $paymentInfo->
                getAdditionalInformation(Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_TRANSPORT_TOKEN);
        $profile->setToken($token);
        $this->getStandard()->submitRecurringProfile($profile, $paymentInfo);
    }

    /**
     * Fetch RP details
     *
     * @param string        $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result)
    {
        return $this->getStandard()->getRecurringProfileDetails($referenceId, $result);
    }

    /**
     * Whether can get recurring profile details
     */
    public function canGetRecurringProfileDetails()
    {
        return true;
    }

    /**
     * Update RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        return $this->getStandard()->updateRecurringProfile($profile);
    }

    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile)
    {

    }

}
