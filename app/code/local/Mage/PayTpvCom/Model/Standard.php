<?php
/**
* Our test CC module adapter
*/

class Mage_PayTpvCom_Model_Standard extends Mage_Payment_Model_Method_Abstract
    implements Mage_Payment_Model_Recurring_Profile_MethodInterface
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

    protected   $_code = 'paytpvcom';

    protected   $_formBlockType = 'paytpvcom/standard_form';

    protected   $_allowCurrencyCode = array('EUR');

    /**
    * Get paytpv.com session namespace
    *
    * @return paytpv.com_Model_Session
    */
    public function getSession() {
      return  Mage::getSingleton('paytpvcom/session');
    }

    /**
    * Get checkout session namespace
    *
    * @return Checkout_Model_Session
    */
    public function getCheckout() {
        return  Mage::getSingleton('checkout/session');
    }

    /**
    * Get current quote
    *
    * @return Mage_Sales_Model_Quote
    */
    public function getQuote() {
        return  $this->getCheckout()->getQuote();
    }

    /**
    * Using internal pages for input payment data
    *
    * @return bool
    */
    public function canUseInternal() {
        return  false;
    }

    /**
    * Using for multiple shipping address
    *
    * @return bool
    */
    public function canUseForMultishipping() {
        return  true;
    }

    public function createFormBlock($name) {
        $block  = $this->getLayout()->createBlock('paytpvcom/form', $name);
		$block->setMethod('paytpvcom')
            ->setPayment($this->getPayment())
            ->setTemplate('paytpvcom/form.phtml');

        return  $block;
    }

    /*validate the currency code is avaialable to use for paytpvcom or not*/
    public function validate() {
        parent::validate();
        $currency_code = $this->getQuote()->getBaseCurrencyCode();
        if(!in_array($currency_code,$this->_allowCurrencyCode)) {
            Mage::throwException(Mage::helper('payment')->__('El codigo de moneda seleccionado (%s) no es compatible con paytpv.com',$currency_code));
        }
        return  $this;
    }

    public function onOrderValidate(Mage_Sales_Model_Order_Payment $payment) {
        return  $this;
    }

    public function onInvoiceCreate(Mage_Sales_Model_Invoice_Payment $payment) {
    }

    public function canCapture() {
        return true;
    }

    public function getOrderPlaceRedirectUrl() {
		$op = $this->getConfigData('operativa');
		switch ($op){
			case 0:
				return Mage::getUrl('paytpvcom/standard/redirect');
			case 1:
				return Mage::getUrl('paytpvcom/standard/iframe');
		}
		return null;
    }

    public function getRecurringProfileSetRedirectUrl() {
      return Mage::getUrl('paytpvcom/standard/recurringredirect');
    }

    function calcLanguage($lan) {
        $res = "";
        switch($lan) {
            case "es_ES": return "es";
            case "fr_FR": return "fr";
            case "en_GB": return "en";
            case "en_US": return "en";
            case "ca_ES": return "ca";
        }
        return "es";
    }

    public function getStandardCheckoutFormFields()
	{
		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($this->getCheckout()->getLastRealOrderId());

		$convertor = Mage::getModel('sales/convert_order');
		$invoice = $convertor->toInvoice($order);

		$amount = $order->getTotalDue() * 100;
		$ord = $this->getCheckout()->getLastRealOrderId();
		$currency = "EUR";//strtoupper($this->convertTopaytpvcomCurrency($order->getOrderCurrency()));

		$client = $this->getConfigData('client');
		$user = $this->getConfigData('user');
		$pass = $this->getConfigData('pass');
		$terminal = $this->getConfigData('terminal');

 	        $pagina = Mage::app()->getWebsite()->getName();
		$language_settings = Mage::app()->getStore()->getCode();
		$language_settings = strtolower($language_settings);

		if ( $language_settings == "default" ) {
			$language = "ES";
		} else {
			$language = "EN";
		}

		$operation = "1";

		$signature = md5($client.$user.$terminal."1".$ord.$amount.$currency.md5($pass));

        $sArr = array
		(
			'ACCOUNT' => $client,
			'USERCODE' => $user,
			'TERMINAL' => $terminal,
			'OPERATION' => $operation,
			'REFERENCE' => $ord,
            'AMOUNT' => round($order->getTotalDue() * 100),    // convert to minor units
			'CURRENCY' => $currency,
			'SIGNATURE' => $signature,
			'CONCEPT' => '',
			'LANGUAGE' => $language,
			'URLOK' => Mage::getUrl('paytpvcom/standard/recibo'),
			'URLKO' => Mage::getUrl('paytpvcom/standard/result')
		);
        //
        // Make into request data
        //
        $sReq = '';
        $rArr = array();
        foreach ($sArr as $k=>$v) {
            /* replacing & char with and. otherwise it will break the post */
            $value =  str_replace("&","and",$v);
            $rArr[$k] =  $value;
            $sReq .= '&'.$k.'='.$value;
        }

        return $rArr;
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
    /*RECURRING PROFILES*/
    /**
     * Validate RP data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @throws Mage_Core_Exception
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        $errors = array();
        if (strlen($profile->getSubscriberName()) > 32) { // up to 32 single-byte chars
            $errors[] = Mage::helper('paypal')->__('Subscriber name is too long.');
        }
        $refId = $profile->getInternalReferenceId(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[a-z\d\s]+$/i', $refId)
            $errors[] = Mage::helper('paypal')->__('Merchant reference ID format is not supported.');
        }
        $scheduleDescr = $profile->getScheduleDescription(); // up to 127 single-byte alphanumeric
        if (strlen($refId) > 127) { //  || !preg_match('/^[a-z\d\s]+$/i', $scheduleDescr)
            $errors[] = Mage::helper('paypal')->__('Schedule description is too long.');
        }
        if ($errors) {
            Mage::throwException(implode(' ', $errors));
        }
    }

    /**
     * Submit RP to the gateway
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info $paymentInfo
     * @throws Mage_Core_Exception
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile,
        Mage_Payment_Model_Info $paymentInfo
    ) {
/*	$client = new Zend_Soap_Client("https://www.paytpv.com/gateway/xml_bankstore.php?wsdl",
	    array('compression' => SOAP_COMPRESSION_ACCEPT));
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
            ->callGetRecurringPaymentsProfileDetails($result)
        ;
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
        $api->callManageRecurringPaymentsProfileStatus($profile->getNewState(),$profile->getState());
    }

    public function canGetRecurringProfileDetails() {

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

 }
 ?>
