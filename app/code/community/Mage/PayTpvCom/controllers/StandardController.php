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
        if (isset($params['ExtendedSignature'])) {//Notificación BANK STORE
            if ($params['ExtendedSignature'] == md5(
                    $model->getConfigData('client').
                    $model->getConfigData('terminal').
                    $params['TransactionType'].
                    $params['Order'].
                    $params['Amount'].
                    $params['Currency'].
                    md5($model->getConfigData('pass')).
                    $params['BankDateTime'].
                    $params['Response']
                    )
            )
                $firmaValida = true;
            $order->loadByIncrementId($params['Order']);
            if ($firmaValida && $params['Response'] == 'OK') {
                $model->processSuccess($order,null,$params);
            } else {
                $this->cancelAction();
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

    public function updateRecurringProfileStatus(\Mage_Payment_Model_Recurring_Profile $profile)
    {

    }

}
