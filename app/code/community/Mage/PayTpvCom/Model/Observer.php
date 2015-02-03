<?php
class Mage_PayTpvCom_Model_Observer{


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
}