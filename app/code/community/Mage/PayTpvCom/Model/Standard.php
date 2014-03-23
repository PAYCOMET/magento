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
    protected $_canRefund = false;
    protected $_canCapture = true;
    protected $_canUseInternal = false; //Payments from backend
    protected $_canUseForMultishipping = true;
    protected $_isInitializeNeeded = false;

    private $_client = null;

    const IT_OFFSITE = 0;
    const IT_IFRAME = 1;
    const OP_TPVWEB = 0;
    const OP_BANKSTORE = 3;

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

    /* validate the currency code is avaialable to use for paytpvcom or not */

    public function validate()
    {
        parent::validate();
        $currency_code = $this->getQuote()->getBaseCurrencyCode();
        if (!in_array($currency_code, $this->_allowCurrencyCode)) {
            Mage::throwException(Mage::helper('payment')->__('El codigo de moneda seleccionado (%s) no es compatible con paytpv.com', $currency_code));
        }
        return $this;
    }

    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment)
    {
        return $this;
    }

    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment)
    {
        return $this;
    }

    public function authorize(Varien_Object $payment, $amount)
    {
        parent::authorize($payment, $amount);
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        $order = $payment->getOrder();
        if ($payment_data['cc_number'] && $payment_data['cc_number']) {
            $res = $this->addUser($payment_data);
            $DS_IDUSER = isset($res['DS_IDUSER']) ? $res['DS_IDUSER'] : '';
            $DS_TOKEN_USER = isset($res['DS_TOKEN_USER']) ? $res['DS_TOKEN_USER'] : '';
            $DS_ERROR_ID = isset($res['DS_ERROR_ID']) ? $res['DS_ERROR_ID'] : '';
            if ((int)$DS_ERROR_ID == 0)
                $order
                    ->setPaytpvIduser($DS_IDUSER)
                    ->setPaytpvTokenuser($DS_TOKEN_USER)
                    ->save();
        } else {
            $customer = Mage::getModel('customer/customer')->load($order->getCustomerId());
            $order
                ->setPaytpvIduser($customer->getPaytpvIduser())
                ->setPaytpvTokenuser($customer->getPaytpvTokenuser())
                ->save();

        }
        if ((int)$DS_ERROR_ID != 0 || $order->getPaytpvIduser() == '' || $order->getPaytpvTokenuser() == '') {
            if (!isset($res['DS_ERROR_ID']))
                $res['DS_ERROR_ID'] = 666;
            $message = Mage::helper('payment')->__('Authorization failed. %s - %s', $res['DS_ERROR_ID'], $this->getErrorDesc($res['DS_ERROR_ID']));
            throw new Mage_Payment_Model_Info_Exception($message);
        }
        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        parent::capture($payment, $amount);
        $order = $payment->getOrder();
        $customer = Mage::getModel('customer/customer')->load($order->getCustomerId());
        $payment_data = Mage::app()->getRequest()->getParam('payment', array());
        if ($payment_data['cc_number'] && $payment_data['cc_number']) {
            $this->authorize($payment, 0);
        } else {
            $order
                ->setPaytpvIduser($customer->getPaytpvIduser())
                ->setPaytpvTokenuser($customer->getPaytpvTokenuser())
                ->save();
        }

        $res = $this->executePurchase($order, $amount);
        if (('' == $res['DS_ERROR_ID'] || 0 == $res['DS_ERROR_ID']) && 1 == $res['DS_RESPONSE']) {
            $customer
                ->setPaytpvIduser($order->getPaytpvIduser())
                ->setPaytpvTokenuser($order->getPaytpvTokenuser())
                ->setPaytpvCc('************' . substr($payment_data['cc_number'], -4))
                ->save();
            $orderStatus = $this->getConfigData('paid_status');
            $comment = Mage::helper('payment')->__('Successful payment');
            $order->setState($orderStatus, $orderStatus, $comment, true);
            $order->save();
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
    }

    public function processSuccess(&$order, $session)
    {
        $orderStatus = $this->getConfigData('paid_status');
        $session->unsErrorMessage();
        $session->addSuccess(Mage::helper('payment')->__('Successful payment'));

        $comment = Mage::helper('payment')->__('Successful payment');
        $order->setState($orderStatus, $orderStatus, $comment, true);
        $order->sendNewOrderEmail();
        $order->setEmailSent(true);
        $order->save();
        Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
        Mage::app()->getResponse()->setRedirect(Mage::getUrl('checkout/onepage/success'));
    }

    public function processFail($order, $session, $message, $comment)
    {
        /**
         * Actualizamos al nuevo estado del pedido (el nuevo estado
         * se configura en el backend de la extension paytpv)
         */
        $state = $this->getConfigData('error_status');
        if ($state == Mage_Sales_Model_Order::STATE_CANCELED)
            $order->cancel();
        else
            $order->setState($state, $state, $comment, true);

        $order->save();
        $order->sendOrderUpdateEmail(true, $message);

        $session->addError($message);
        Mage::app()->getResponse()->setRedirect(Mage::getUrl('sales/order/reorder', array('order_id' => $order->getId())));
    }

    public function getOrderPlaceRedirectUrl()
    {
        if(3==$this->getConfigData('operativa'))
            return;

        $it = $this->getConfigData('integracion');
        switch ($it) {
            case self::IT_OFFSITE:
                return Mage::getUrl('paytpvcom/standard/redirect');
            case self::IT_IFRAME:
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

    private function infoUser($DS_IDUSER, $DS_TOKEN_USER)
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
        $DS_MERCHANT_ORDER = $order->getId();
        $DS_MERCHANT_CURRENCY = $order->getOrderCurrencyCode();
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_MERCHANT_AMOUNT . $DS_MERCHANT_ORDER . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
        $DS_MERCHANT_PRODUCTDESCRIPTION = ''; /*@TODO: Set description and owner*/
        $DS_MERCHANT_OWNER = '';
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

    private function addUser($payment_data, $original_ip = '')
    {
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_PAN = $payment_data['cc_number'];
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

    public function getRecurringProfileSetRedirectUrl()
    {
        return Mage::getUrl('paytpvcom/standard/recurringredirect');
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
            case "ca_ES":
                return "ca";
        }
        return "es";
    }

    public function getCCFromQuote()
    {
        $session = Mage::getSingleton('checkout/session');
        $quote_id = $session->getQuoteId();
        $quote = Mage::getModel('sales/quote')->load($quote_id);
        $customer = Mage::getModel('customer/customer')->load($quote->getCustomerId());
        $cc = $customer->getPaytpvCc();
        if ($cc)
            return $cc;
        $DS_IDUSER = $customer->getPaytpvIduser();
        if (!$DS_IDUSER)
            return false;

        $DS_TOKEN_USER = $customer->getPaytpvTokenuser();
        $client = new Zend_Soap_Client("https://www.paytpv.com/gateway/xml_bankstore.php?wsdl",
            array('soap_version' => SOAP_1_1, 'encoding' => 'UTF-8'));
        $DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $this->getConfigData('pass'));
        $DS_ORIGINAL_IP = Mage::helper('core/http')->getRemoteAddr(true);
        $res = $client->info_user(
            $DS_MERCHANT_MERCHANTCODE, $DS_MERCHANT_TERMINAL, $DS_IDUSER, $DS_TOKEN_USER, $DS_MERCHANT_MERCHANTSIGNATURE, $DS_ORIGINAL_IP
        );
        if ($res['DS_MERCHANT_PAN']) {
            $customer->setPaytpvCc($res['DS_MERCHANT_PAN']);
            $customer->save();
        }
        return $res['DS_MERCHANT_PAN'];
    }

    public function getStandardCheckoutFormFields()
    {
        $order_id = $this->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);

        $convertor = Mage::getModel('sales/convert_order');
        $invoice = $convertor->toInvoice($order);
        $currency = $order->getOrderCurrencyCode();
        $amount = round($order->getTotalDue() * 100);
        if ($currency == 'JPY')
            $amount = round($order->getTotalDue());


        $client = $this->getConfigData('client');
        $user = $this->getConfigData('user');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $pagina = Mage::app()->getWebsite()->getName();
        $language_settings = strtolower(Mage::app()->getStore()->getCode());

        if ($language_settings == "default") {
            $language = "ES";
        } else {
            $language = "EN";
        }

        $operation = "1";

        $signature = md5($client . $user . $terminal . $operation . $order_id . $amount . $currency . md5($pass));

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

    public function getStandardAddUserFields()
    {
        $session = Mage::getSingleton('checkout/session');
        $quote_id = $session->getQuoteId();
        $client = $this->getConfigData('client');
        $pass = $this->getConfigData('pass');
        $terminal = $this->getConfigData('terminal');

        $pagina = Mage::app()->getWebsite()->getName();
        $language_settings = strtolower(Mage::app()->getStore()->getCode());

        if ($language_settings == "default") {
            $language = "ES";
        } else {
            $language = "EN";
        }

        $operation = "107";

        $signature = md5($client . $terminal . $operation . $quote_id . md5($pass));

        $sArr = array
        (
            'MERCHANT_MERCHANTCODE' => $client,
            'MERCHANT_TERMINAL' => $terminal,
            'OPERATION' => $operation,
            'LANGUAGE' => $language,
            'MERCHANT_MERCHANTSIGNATURE' => $signature,
            'MERCHANT_ORDER' => $quote_id,
            'URLOK' => Mage::getUrl('paytpvcom/standard/adduserok'),
            'URLKO' => Mage::getUrl('paytpvcom/standard/addusernok'),
            '3DSECURE' => '1'
        );
        //
        // Make into request data
        //
        return http_build_query($sArr);
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
        return "https://secure.paytpv.com/gateway/bnkgateway.php";
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
            $errors[] = Mage::helper('paypal')->__('Subscriber name is too long.');
        }
        $refId = $profile->getInternalReferenceId(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[ a-z\d\s ]+$/i', $refId)
            $errors[] = Mage::helper('paypal')->__('Merchant reference ID format is not supported.');
        }
        $scheduleDescr = $profile->getScheduleDescription(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[ a-z\d\s ]+$/i', $scheduleDescr)
            $errors[] = Mage::helper('paypal')->__('Schedule description is too long.');
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
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo
    )
    {
        /* 	$client = new Zend_Soap_Client("https://www.paytpv.com/gateway/xml_bankstore.php?wsdl",
          array('compression







          ' => SOAP_COMPRESSION_ACCEPT));
          exit();
         */
        $profile->setReferenceId(time());
        $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_PENDING);
    }

    /**
     * Fetch RP details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result)
    {
        $api = $this->getApi();
        $api->setRecurringProfileId($referenceId)
            ->callGetRecurringPaymentsProfileDetails($result);
    }

    /**
     * Update RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {

    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile)
    {
        $api = $this->getApi();
        $api->callManageRecurringPaymentsProfileStatus($profile->getNewState(), $profile->getState());
    }

    public function canGetRecurringProfileDetails()
    {

    }

    /**
     * API instance getter
     * Sets current store id to current config instance and passes it to API
     *
     * @return Mage_PayTpvCom_Model_Api
     */
    public function getApi()
    {
        if (null === $this->_api) {
            $this->_api = Mage::getModel($this->_apiType);
        }
        return $this->_api;
    }

    /**
     * Destroy existing Api object
     *
     * @return Mage_PayTpvCom_Model_Standard
     */
    public function resetApi()
    {
        $this->_api = null;
        return $this;
    }

    public function getErrorDesc($code)
    {
        $paytpv_error_codes = array(
            '1' => 'Error',
            '100' => 'Tarjeta caducada',
            '101' => 'Tarjeta en lista negra',
            '102' => 'Operación no permitida para el tipo de tarjeta',
            '103' => 'Por favor, contacte con el banco emisor',
            '104' => 'Error inesperado',
            '105' => 'Crédito insuficiente para realizar el cargo',
            '106' => 'Tarjeta no dada de alta o no registrada por el banco emisor',
            '107' => 'Error de formato en los datos capturados. CodValid',
            '108' => 'Error en el número de la tarjeta',
            '109' => 'Error en FechaCaducidad',
            '110' => 'Error en los datos',
            '111' => 'Bloque CVC2 incorrecto',
            '112' => 'Por favor, contacte con el banco emisor',
            '113' => 'Tarjeta de crédito no válida',
            '114' => 'La tarjeta tiene restricciones de crédito',
            '115' => 'El emisor de la tarjeta no pudo identificar al propietario',
            '116' => 'Pago no permitido en operaciones fuera de línea',
            '118' => 'Tarjeta caducada. Por favor retenga físicamente la tarjeta',
            '119' => 'Tarjeta en lista negra. Por favor retenga físicamente la tarjeta',
            '120' => 'Tarjeta perdida o robada. Por favor retenga físicamente la tarjeta',
            '121' => 'Error en CVC2. Por favor retenga físicamente la tarjeta',
            '122' => 'Error en el proceso pre-transacción. Inténtelo más tarde',
            '123' => 'Operación denegada. Por favor retenga físicamente la tarjeta',
            '124' => 'Cierre con acuerdo',
            '125' => 'Cierre sin acuerdo',
            '126' => 'No es posible cerrar en este momento',
            '127' => 'Parámetro no válido',
            '128' => 'Las transacciones no fueron finalizadas',
            '129' => 'Referencia interna duplicada',
            '130' => 'Operación anterior no encontrada. No se pudo ejecutar la devolución',
            '131' => 'Preautorización caducada',
            '132' => 'Operación no válida con la moneda actual',
            '133' => 'Error en formato del mensaje',
            '134' => 'Mensaje no reconocido por el sistema',
            '135' => 'Bloque CVC2 incorrecto',
            '137' => 'Tarjeta no válida',
            '138' => 'Error en mensaje de pasarela',
            '139' => 'Error en formato de pasarela',
            '140' => 'Tarjeta inexistente',
            '141' => 'Cantidad cero o no válida',
            '142' => 'Operación cancelada',
            '143' => 'Error de autenticación',
            '144' => 'Denegado debido al nivel de seguridad',
            '145' => 'Error en el mensaje PUC. Contacte con PAYTPV',
            '146' => 'Error del sistema',
            '147' => 'Transacción duplicada',
            '148' => 'Error de MAC',
            '149' => 'Liquidación rechazada',
            '150' => 'Fecha/hora del sistema no sincronizada',
            '151' => 'Fecha de caducidad no válida',
            '152' => 'No se pudo encontrar la preautorización',
            '153' => 'No se encontraron los datos solicitados',
            '154' => 'No se puede realizar la operación con la tarjeta de crédito proporcionada',
            '500' => 'Error inesperado',
            '501' => 'Error inesperado',
            '502' => 'Error inesperado',
            '504' => 'Transacción cancelada previamente',
            '505' => 'Transacción original denegada',
            '506' => 'Datos de confirmación no válidos',
            '507' => 'Error inesperado',
            '508' => 'Transacción aún en proceso',
            '509' => 'Error inesperado',
            '510' => 'No es posible la devolución',
            '511' => 'Error inesperado',
            '512' => 'No es posible contactar con el banco emisor. Inténtelo más tarde',
            '513' => 'Error inesperado',
            '514' => 'Error inesperado',
            '515' => 'Error inesperado',
            '516' => 'Error inesperado',
            '517' => 'Error inesperado',
            '518' => 'Error inesperado',
            '519' => 'Error inesperado',
            '520' => 'Error inesperado',
            '521' => 'Error inesperado',
            '522' => 'Error inesperado',
            '523' => 'Error inesperado',
            '524' => 'Error inesperado',
            '525' => 'Error inesperado',
            '526' => 'Error inesperado',
            '527' => 'Tipo de transacción desconocido',
            '528' => 'Error inesperado',
            '529' => 'Error inesperado',
            '530' => 'Error inesperado',
            '531' => 'Error inesperado',
            '532' => 'Error inesperado',
            '533' => 'Error inesperado',
            '534' => 'Error inesperado',
            '535' => 'Error inesperado',
            '536' => 'Error inesperado',
            '537' => 'Error inesperado',
            '538' => 'Operación no cancelable',
            '539' => 'Error inesperado',
            '540' => 'Error inesperado',
            '541' => 'Error inesperado',
            '542' => 'Error inesperado',
            '543' => 'Error inesperado',
            '544' => 'Error inesperado',
            '545' => 'Error inesperado',
            '546' => 'Error inesperado',
            '547' => 'Error inesperado',
            '548' => 'Error inesperado',
            '549' => 'Error inesperado',
            '550' => 'Error inesperado',
            '551' => 'Error inesperado',
            '552' => 'Error inesperado',
            '553' => 'Error inesperado',
            '554' => 'Error inesperado',
            '555' => 'No se pudo encontrar la operación previa',
            '556' => 'Inconsistencia de datos en la validación de la cancelación',
            '557' => 'El pago diferido no existe',
            '558' => 'Error inesperado',
            '559' => 'Error inesperado',
            '560' => 'Error inesperado',
            '561' => 'Error inesperado',
            '562' => 'La tarjeta no admite preautorizaciones',
            '563' => 'Inconsistencia de datos en confirmación',
            '564' => 'Error inesperado',
            '565' => 'Error inesperado',
            '567' => 'Operación de devolución no definida correctamente',
            '569' => 'Operación denegada',
            '1000' => 'Cuenta no encontrada. Revise su configuración',
            '1001' => 'Usuario no encontrado. Contacte con PAYTPV',
            '1002' => 'Error en respuesta de pasarela. Contacte con PAYTPV',
            '1003' => 'Firma no válida. Por favor, revise su configuración',
            '1004' => 'Acceso no permitido',
            '1005' => 'Formato de tarjeta de crédito no válido',
            '1006' => 'Error en el campo Código de Validación',
            '1007' => 'Error en el campo Fecha de Caducidad',
            '1008' => 'Referencia de preautorización no encontrada',
            '1009' => 'Datos de preautorización no encontrados',
            '1010' => 'No se pudo enviar la devolución. Por favor reinténtelo más tarde',
            '1011' => 'No se pudo conectar con el host',
            '1012' => 'No se pudo resolver el proxy',
            '1013' => 'No se pudo resolver el host',
            '1014' => 'Inicialización fallida',
            '1015' => 'No se ha encontrado el recurso HTTP',
            '1016' => 'El rango de opciones no es válido para la transferencia HTTP',
            '1017' => 'No se construyó correctamente el POST',
            '1018' => 'El nombre de usuario no se encuentra bien formateado',
            '1019' => 'Se agotó el tiempo de espera en la petición',
            '1020' => 'Sin memoria',
            '1021' => 'No se pudo conectar al servidor SSL',
            '1022' => 'Protocolo no soportado',
            '1023' => 'La URL dada no está bien formateada y no puede usarse',
            '1024' => 'El usuario en la URL se formateó de manera incorrecta',
            '1025' => 'No se pudo registrar ningún recurso disponible para completar la operación',
            '1026' => 'Referencia externa duplicada',
            '1027' => 'El total de las devoluciones no puede superar la operación original',
            '1028' => 'La cuenta no se encuentra activa. Contacte con PAYTPV',
            '1029' => 'La cuenta no se encuentra certificada. Contacte con PAYTPV',
            '1030' => 'El producto está marcado para eliminar y no puede ser utilizado',
            '1031' => 'Permisos insuficientes',
            '1032' => 'El producto no puede ser utilizado en el entorno de pruebas',
            '1033' => 'El producto no puede ser utilizado en el entorno de producción',
            '1034' => 'No ha sido posible enviar la petición de devolución',
            '1035' => 'Error en el campo IP de origen de la operación',
            '1036' => 'Error en formato XML',
            '1037' => 'El elemento raíz no es correcto',
            '1038' => 'Campo DS_MERCHANT_AMOUNT incorrecto',
            '1039' => 'Campo DS_MERCHANT_ORDER incorrecto',
            '1040' => 'Campo DS_MERCHANT_MERCHANTCODE incorrecto',
            '1041' => 'Campo DS_MERCHANT_CURRENCY incorrecto',
            '1042' => 'Campo DS_MERCHANT_PAN incorrecto',
            '1043' => 'Campo DS_MERCHANT_CVV2 incorrecto',
            '1044' => 'Campo DS_MERCHANT_TRANSACTIONTYPE incorrecto',
            '1045' => 'Campo DS_MERCHANT_TERMINAL incorrecto',
            '1046' => 'Campo DS_MERCHANT_EXPIRYDATE incorrecto',
            '1047' => 'Campo DS_MERCHANT_MERCHANTSIGNATURE incorrecto',
            '1048' => 'Campo DS_ORIGINAL_IP incorrecto',
            '1049' => 'No se encuentra el cliente',
            '1050' => 'La nueva cantidad a preautorizar no puede superar la cantidad de la preautorización original',
            '1099' => 'Error inesperado',
            '1100' => 'Limite diario por tarjeta excedido',
            '1103' => 'Error en el campo ACCOUNT',
            '1104' => 'Error en el campo USERCODE',
            '1105' => 'Error en el campo TERMINAL',
            '1106' => 'Error en el campo OPERATION',
            '1107' => 'Error en el campo REFERENCE',
            '1108' => 'Error en el campo AMOUNT',
            '1109' => 'Error en el campo CURRENCY',
            '1110' => 'Error en el campo SIGNATURE',
            '1120' => 'Operación no disponible',
            '1121' => 'No se encuentra el cliente',
            '1122' => 'Usuario no encontrado. Contacte con PAYTPV',
            '1123' => 'Firma no válida. Por favor, revise su configuración',
            '1124' => 'Operación no disponible con el usuario especificado',
            '1125' => 'Operación no válida con una moneda distinta del Euro',
            '1127' => 'Cantidad cero o no válida',
            '1128' => 'Conversión de la moneda actual no válida',
            '1129' => 'Cantidad no válida',
            '1130' => 'No se encuentra el producto',
            '1131' => 'Operación no válida con la moneda actual',
            '1132' => 'Operación no válida con una moneda distina del Euro',
            '1133' => 'Información del botón corrupta',
            '1134' => 'La subscripción no puede ser mayor de la fecha de caducidad de la tarjeta',
            '1135' => 'DS_EXECUTE no puede ser true si DS_SUBSCRIPTION_STARTDATE es diferente de hoy.',
            '1136' => 'Error en el campo PAYTPV_OPERATIONS_MERCHANTCODE',
            '1137' => 'PAYTPV_OPERATIONS_TERMINAL debe ser Array',
            '1138' => 'PAYTPV_OPERATIONS_OPERATIONS debe ser Array',
            '1139' => 'Error en el campo PAYTPV_OPERATIONS_SIGNATURE',
            '1140' => 'No se encuentra alguno de los PAYTPV_OPERATIONS_TERMINAL',
            '1141' => 'Error en el intervalo de fechas solicitado',
            '1142' => 'La solicitud no puede tener un intervalo mayor a 2 años',
            '1143' => 'El estado de la operación es incorrecto',
            '1144' => 'Error en los importes de la búsqueda',
            '1145' => 'El tipo de operación solicitado no existe',
            '1146' => 'Tipo de ordenación no reconocido',
            '1147' => 'PAYTPV_OPERATIONS_SORTORDER no válido',
            '1148' => 'Fecha de inicio de suscripción errónea',
            '1149' => 'Fecha de final de suscripción errónea',
            '1150' => 'Error en la periodicidad de la suscripción',
            '1151' => 'Falta el parámetro usuarioXML',
            '1152' => 'Falta el parámetro codigoCliente',
            '1153' => 'Falta el parámetro usuarios',
            '1154' => 'Falta el parámetro firma',
            '1155' => 'El parámetro usuarios no tiene el formato correcto',
            '1156' => 'Falta el parámetro type',
            '1157' => 'Falta el parámetro name',
            '1158' => 'Falta el parámetro surname',
            '1159' => 'Falta el parámetro email',
            '1160' => 'Falta el parámetro password',
            '1161' => 'Falta el parámetro language',
            '1162' => 'Falta el parámetro maxamount o su valor no puede ser 0',
            '1163' => 'Falta el parámetro multicurrency',
            '1165' => 'El parámetro permissions_specs no tiene el formato correcto',
            '1166' => 'El parámetro permissions_products no tiene el formato correcto',
            '1167' => 'El parámetro email no parece una dirección válida',
            '1168' => 'El parámetro password no tiene la fortaleza suficiente',
            '1169' => 'El valor del parámetro type no está admitido',
            '1170' => 'El valor del parámetro language no está admitido',
            '1171' => 'El formato del parámetro maxamount no está permitido',
            '1172' => 'El valor del parámetro multicurrency no está admitido',
            '1173' => 'El valor del parámetro permission_id - permissions_specs no está admitido',
            '1174' => 'No existe el usuario',
            '1175' => 'El usuario no tiene permisos para acceder al método altaUsario',
            '1176' => 'No se encuentra la cuenta de cliente',
            '1177' => 'No se pudo cargar el usuario de la cuenta',
            '1178' => 'La firma no es correcta',
            '1179' => 'No existen productos asociados a la cuenta',
            '1180' => 'El valor del parámetro product_id - permissions_products no está autorizado',
            '1181' => 'El valor del parámetro permission_id -permissions_products no está admitido',
            '1185' => 'Límite mínimo por operación no permitido',
            '1186' => 'Límite máximo por operación no permitido',
            '1187' => 'Límite máximo diario no permitido',
            '1188' => 'Límite máximo mensual no permitido',
            '1189' => 'Cantidad máxima por tarjeta / 24h. no permitida',
            '1190' => 'Cantidad máxima por tarjeta / 24h. / misma dirección IP no permitida',
            '1191' => 'Límite de transacciones por dirección IP /día (diferentes tarjetas) no permitido',
            '1192' => 'País no admitido (dirección IP del comercio)',
            '1193' => 'Tipo de tarjeta (crédito / débito) no admitido',
            '1194' => 'Marca de la tarjeta no admitida',
            '1195' => 'Categoría de la tarjeta no admitida',
            '1196' => 'Transacción desde país distinto al emisor de la tarjeta no admitida',
            '1197' => 'Operación denegada. Filtro país emisor de la tarjeta no admitido',
            '1200' => 'Operación denegada. Filtro misma tarjeta, distinto país en las últimas 48 horas',
            '1201' => 'Número de intentos consecutivos erróneos con la misma tarjeta excedidos',
            '1202' => 'Número de intentos fallidos (últimos 30 minutos) desde la misma dirección ip excedidos',
            '1203' => 'Las credenciales PayPal no son válidas o no están configuradas',
            '1204' => 'Recibido token incorrecto',
            '1205' => 'No ha sido posible realizar la operación',
            '1206' => 'providerID no disponible',
            '1207' => 'Falta el parámetro operaciones o no tiene el formato correcto',
            '1208' => 'Falta el parámetro paytpvMerchant',
            '1209' => 'Falta el parámetro merchatID',
            '1210' => 'Falta el parámetro terminalID',
            '1211' => 'Falta el parámetro tpvID',
            '1212' => 'Falta el parámetro operationType',
            '1213' => 'Falta el parámetro operationResult',
            '1214' => 'Falta el parámetro operationAmount',
            '1215' => 'Falta el parámetro operationCurrency',
            '1216' => 'Falta el parámetro operationDatetime',
            '1217' => 'Falta el parámetro originalAmount',
            '1218' => 'Falta el parámetro pan',
            '1219' => 'Falta el parámetro expiryDate',
            '1220' => 'Falta el parámetro reference',
            '1221' => 'Falta el parámetro signature',
            '1222' => 'Falta el parámetro originalIP o no tiene el formato correcto',
            '1223' => 'Falta el parámetro authCode o errorCode',
            '1224' => 'No se encuentra el producto de la operación',
            '1225' => 'El tipo de la operación no está admitido',
            '1226' => 'El resultado de la operación no está admitido',
            '1227' => 'La moneda de la operación no está admitida',
            '1228' => 'La fecha de la operación no tiene el formato correcto',
            '1229' => 'La firma no es correcta',
            '1230' => 'No se encuentra información de la cuenta asociada',
            '1231' => 'No se encuentra información del producto asociado',
            '1232' => 'No se encuentra información del usuario asociado',
            '1233' => 'El producto no está configurado como multimoneda',
            '1234' => 'La cantidad de la operación no tiene el formato correcto',
            '1235' => 'La cantidad original de la operación no tiene el formato correcto',
            '1236' => 'La tarjeta no tiene el formato correcto',
            '1237' => 'La fecha de caducidad de la tarjeta no tiene el formato correcto',
            '1238' => 'No puede inicializarse el servicio',
            '1239' => 'No puede inicializarse el servicio',
            '1240' => 'Método no implementado',
            '1241' => 'No puede inicializarse el servicio',
            '1242' => 'No puede finalizarse el servicio',
            '1243' => 'Falta el parámetro operationCode'
        );
        return $paytpv_error_codes[$code];
    }

}
