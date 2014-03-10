<?php

/**
 * Our test CC module adapter
 */
class Mage_PayTpvCom_Model_Standard extends Mage_Payment_Model_Method_Abstract implements Mage_Payment_Model_Recurring_Profile_MethodInterface {

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
	private $_client = null;

	const OP_OFFSITE = 0;
	const OP_IFRAME = 1;
	const OP_BANKSTORE = 2;

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

	/**
	 * Using internal pages for input payment data
	 *
	 * @return bool
	 */
	public function canUseInternal()
	{
		return false;
	}

	/**
	 * Using for multiple shipping address
	 *
	 * @return bool
	 */
	public function canUseForMultishipping()
	{
		return true;
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
		if (!in_array($currency_code, $this->_allowCurrencyCode))
		{
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

	}

	public function canCapture()
	{
		return true;
	}

	public function getOrderPlaceRedirectUrl()
	{
		$op = $this->getConfigData('operativa');
		switch ($op)
		{
			case self::OP_OFFSITE:
				return Mage::getUrl('paytpvcom/standard/redirect');
			case self::OP_IFRAME_I:
				return Mage::getUrl('paytpvcom/standard/iframe');
			case self::OP_BANKSTORE://Execute Purchase
				if ($this->executePurchase())
					return Mage::getUrl('checkout/onepage/success');
				else
					return Mage::getUrl('paytpvcom/standard/cancel');
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

	public function infoUser($DS_IDUSER, $DS_TOKEN_USER)
	{
		$DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
		$DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
		$DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE.$DS_IDUSER.$DS_TOKEN_USER.$DS_MERCHANT_TERMINAL.$this->getConfigData('pass'));

		return $this->getClient()->info_user(
						$DS_MERCHANT_MERCHANTCODE, $DS_MERCHANT_TERMINAL, $DS_IDUSER, $DS_TOKEN_USER, $DS_MERCHANT_MERCHANTSIGNATURE, $_SERVER['REMOTE_ADDR']);
	}

	public function executePurchase($order, $customer)
	{
		$DS_MERCHANT_MERCHANTCODE = $this->getConfigData('client');
		$DS_MERCHANT_TERMINAL = $this->getConfigData('terminal');
		$DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE.$DS_IDUSER.$DS_TOKEN_USER.$DS_MERCHANT_TERMINAL.$this->getConfigData('pass'));
		return $this->getClient()->execute_purchase(
		);
	}

	public function getRecurringProfileSetRedirectUrl()
	{
		return Mage::getUrl('paytpvcom/standard/recurringredirect');
	}

	function calcLanguage($lan)
	{
		$res = "";
		switch ($lan)
		{
			case "es_ES": return "es";
			case "fr_FR": return "fr";
			case "en_GB": return "en";
			case "en_US": return "en";
			case "ca_ES": return "ca";
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
		$DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE.$DS_IDUSER.$DS_TOKEN_USER.$DS_MERCHANT_TERMINAL.$this->getConfigData('pass'));
		$DS_ORIGINAL_IP = Mage::helper('core/http')->getRemoteAddr(true);
		$res = $client->info_user(
				$DS_MERCHANT_MERCHANTCODE, $DS_MERCHANT_TERMINAL, $DS_IDUSER, $DS_TOKEN_USER, $DS_MERCHANT_MERCHANTSIGNATURE, $DS_ORIGINAL_IP
		);
		if ($res['DS_MERCHANT_PAN'])
		{
			$customer->setPaytpvCc($res['DS_MERCHANT_PAN']);
			$customer->save();
		}
		return $res['DS_MERCHANT_PAN'];
	}

	public function isApplicableToQuote($quote, $checksBitMask)
	{
		if (!parent::isApplicableToQuote($quote, $checksBitMask))
			return false;
		if(self::OP_BANKSTORE==$this->getConfigData('operativa') &&
		   (!$quote->getPaytpvIduser() || !$quote->getPaytpvTokenuser())
		)
			return false;
		return true;
	}

	public function getStandardCheckoutFormFields()
	{
		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($this->getCheckout()->getLastRealOrderId());

		$convertor = Mage::getModel('sales/convert_order');
		$invoice = $convertor->toInvoice($order);
		$currency = $order->getOrderCurrencyCode();
		$amount = round($order->getTotalDue() * 100);
		if ($currency == 'JPY')
			$amount = round($order->getTotalDue());

		$ord = $this->getCheckout()->getLastRealOrderId();

		$client = $this->getConfigData('client');
		$user = $this->getConfigData('user');
		$pass = $this->getConfigData('pass');
		$terminal = $this->getConfigData('terminal');

		$pagina = Mage::app()->getWebsite()->getName();
		$language_settings = strtolower(Mage::app()->getStore()->getCode());

		if ($language_settings == "default")
		{
			$language = "ES";
		} else
		{
			$language = "EN";
		}

		$operation = "1";

		$signature = md5($client.$user.$terminal.$operation.$ord.$amount.$currency.md5($pass));

		$sArr = array
			(
			'ACCOUNT' => $client,
			'USERCODE' => $user,
			'TERMINAL' => $terminal,
			'OPERATION' => $operation,
			'REFERENCE' => $ord,
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
		foreach ($sArr as $k => $v)
		{
			/* replacing & char with and. otherwise it will break the post */
			$value = str_replace("&", "and", $v);
			$rArr[$k] = $value;
			$sReq .= '&'.$k.'='.$value;
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

		if ($language_settings == "default")
		{
			$language = "ES";
		} else
		{
			$language = "EN";
		}

		$operation = "107";

		$signature = md5($client.$terminal.$operation.$quote_id.md5($pass));

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
	 * @param Mage_Payment_Model_Recurring_Profile $profile
	 * @throws Mage_Core_Exception
	 */
	public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
	{
		$errors = array();
		if (strlen($profile->getSubscriberName()) > 32)
		{ // up to 32 single-byte chars
			$errors[] = Mage::helper('paypal')->__('Subscriber name is too long.');
		}
		$refId = $profile->getInternalReferenceId(); // up to 127 single-byte alphanumeric
		if (strlen($refId) > 127)
		{ //  || !preg_match('/^[ a-z\d\s ]+$/i', $refId)
			$errors[] = Mage::helper('paypal')->__('Merchant reference ID format is not supported.');
		}
		$scheduleDescr = $profile->getScheduleDescription(); // up to 127 single-byte alphanumeric
		if (strlen($refId) > 127)
		{ //  || !preg_match('/^[ a-z\d\s ]+$/i', $scheduleDescr)
			$errors[] = Mage::helper('paypal')->__('Schedule description is too long.');
		}
		if ($errors)
		{
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
		if (null === $this->_api)
		{
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
