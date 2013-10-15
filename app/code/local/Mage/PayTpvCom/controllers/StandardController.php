<?php

/**
*Servired Standard Checkout Controller
*
*/
class Mage_PayTpvCom_StandardController extends Mage_Core_Controller_Front_Action
    implements Mage_Payment_Model_Recurring_Profile_MethodInterface {
	//
	// Flag only used for callback
	protected $_callbackAction = false;

	protected function _expireAjax() {
		if (!Mage::getSingleton('checkout/session')->getQuote()->hasItems()) {
			$this->getResponse()->setHeader('HTTP/1.1','403 Session Expired');
			exit;
		}
	}

	/**
	 * Get singleton with servired strandard order transaction information
	 *
	 * @return PayTpvCom_Model_Standard
	 */
	public function getStandard() {
		return Mage::getSingleton('paytpvcom/standard');
	}

	public function recurringredirectAction(){

	}

	/**
	* When a customer chooses PayTpvCom on Checkout/Payment page
	*
	*/
	public function redirectAction()
	{
	    $session = Mage::getSingleton('checkout/session');
	    if($session->getLastOrderId())
	    {
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
	    if($session->getLastOrderId())
	    {
			$session->setPayTpvComStandardQuoteId($session->getQuoteId());
			$this->loadLayout();
            $this->renderLayout();
	    }
		return;
	}
	
	/**
	 * Comunicación del resultado desde paytpv.com
	 */
	public function resultAction()
	{
		$params =   $this->getRequest()->getParams();
		$model = Mage::getModel('paytpvcom/standard');
		$state  =   $model->getConfigData('error_status');

		$message  =  '';
		$firmaValida = false;
		if(count($params) > 0)
		{
			if($params['h'] == md5($model->getConfigData('user').$params['r'].$model->getConfigData('pass').$params["ret"]))
				$firmaValida = true;

			if($firmaValida  && $params['ret'] != "0")
			{
				$errnum = $params['ret'];
				$message = "No se pudo completar el cobro con &eacute;xito (c&oacute;digo ".$errnum.").";
				$message = Mage::helper('payment')->__($message);
				$comment = Mage::helper('payment')->__('Pedido cancelado desde paytpv.com con error #%s - %s', $errnum, $message);
			}
		}

		if(!$message) // Informacion devuelta no valida
		{
			$message = "Se produjo un error durante el proceso de compra (c&oacute;digo -1).";
			$errnum = -1;
			$message = Mage::helper('payment')->__($message);
			$comment = Mage::helper('payment')->__('Pedido cancelado con error #%s - %s', $errnum, $message);
		}

        $session = Mage::getSingleton('checkout/session');
		$order = Mage::getModel('sales/order')->load($session->getLastOrderId());

		$order->setState($state,$state,$comment,true);
		$order->save();

		$order->sendOrderUpdateEmail(true,$message);

		$session->addError($message);
		$this->_redirect('checkout/cart');

		return;
	}

    /**
     * Página a la que vuelvge el usuario
     */
    public function  reciboAction()
    {
		$model = Mage::getModel('paytpvcom/standard');
		$orderStatus = $model->getConfigData('order_status');
        	$session = Mage::getSingleton('checkout/session');

		$order = Mage::getModel('sales/order');
		$order->load(Mage::getSingleton('checkout/session')->getLastOrderId());

//		$session->addError(Mage::helper('payment')->__('Pago no realizado : %s',$session->getPayTpvComStandardQuoteId()));

        	$session->setQuoteId($session->getPayTpvComStandardQuoteId());
        	$params = $this->getRequest()->getParams();

		$firmaValida = false;
		$pagoOK = true;

        	if(count($params) > 0)
		{
			if($params['h'] == md5($model->getConfigData('user').$params['r'].$model->getConfigData('pass').$params["ret"]))
				$firmaValida = true;

            		if ($firmaValida && $params['ret'] == 0)
			{
				$session->unsErrorMessage();
				$session->addSuccess(Mage::helper('payment')->__('Pago realizado con &eacute;xito'));

				$order->setState($orderStatus, $orderStatus, $comment, true);
				$order->save();
            		}
			else
			{
				$session->addError(Mage::helper('payment')->__('Pago no realizado: %s', utf8_encode("No se pudo completar el cobro con &eacute;xito (c&oacute;digo ".$params["ret"].").")));
			}
        	}
		else
		{
			//$session->addError(Mage::helper('payment')->__('Pago no realizado: %s', utf8_encode("Se produjo un error durante el proceso de compra (c&oacute;digo -1).")));
			$session->unsErrorMessage();
			$session->addSuccess(Mage::helper('payment')->__('Pago realizado con &eacute;xito'));

			$order->setState($orderStatus, $orderStatus, $comment, true);
			$order->sendNewOrderEmail();
			$order->setEmailSent(true);
			$order->save();
		}

		Mage::getSingleton('checkout/session')->getQuote()->setIsActive(true)->save();
		$this->_redirect('checkout/cart');
    }

    /*RECURRING PROFILES*/
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
     * @param Mage_Payment_Model_Info $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile,
        Mage_Payment_Model_Info $paymentInfo
    ) {
        $token = $paymentInfo->
            getAdditionalInformation(Mage_Paypal_Model_Express_Checkout::PAYMENT_INFO_TRANSPORT_TOKEN);
        $profile->setToken($token);
        $this->getStandard()->submitRecurringProfile($profile, $paymentInfo);
    }

    /**
     * Fetch RP details
     *
     * @param string $referenceId
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

    public function updateRecurringProfileStatus(\Mage_Payment_Model_Recurring_Profile $profile) {

    }
}
