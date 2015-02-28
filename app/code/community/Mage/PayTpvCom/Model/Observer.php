<?php
class Mage_PayTpvCom_Model_Observer{
    /* @var Magento_Sales_Model_Order_Invoice */
    var $_invoice;


	public function saveOrderInfo($event)
    {
       
    }

    /**
     * This is fix for magento bug: in Mage_Checkout_Model_Type_Onepage::saveOrder it does not redirect to any url (3DS)
     * @param Varien_Event_Observer $observer
     */
    public function checkout_submit_all_after($observer){
        $session = Mage::getSingleton('checkout/session');
        $url = Mage::getModel('paytpvcom/standard')->getOrderPlaceRedirectUrl();
        if ($session->getLastRecurringProfileIds() && $url){
            $session->setRedirectUrl($url);
        }
    }


    /**
    * Mage::dispatchEvent($this->_eventPrefix.'_save_after', $this->_getEventData());
    * protected $_eventPrefix = 'sales_order';
    * protected $_eventObject = 'order';
    * event: sales_order_save_after
    */
    public function automaticallyInvoiceShipCompleteOrder($observer)
    {
       try {
            $payment_code = $observer->getEvent()->getInvoice()->getOrder()->getPayment()->getMethodInstance()->getCode();
            $standard = Mage::getModel('paytpvcom/standard');
            if ($payment_code==$standard->getCode() && $standard->getConfigData('sendmailinvoicecreation')){
                /* @var $order Magento_Sales_Model_Order_Invoice */
                $this->_invoice = $observer->getEvent()->getInvoice();
                $this->_invoice->sendEmail();
            }
       } catch (Mage_Core_Exception $e) {
           Mage::log("PAYTPV AutomaticallyInvoice Error: " . $e->getMessage());
       }

       return $this;
    }

}