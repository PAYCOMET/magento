<?php
class Mage_PayTpvCom_Block_Standard_Bankstore extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();

        $standard = Mage::getModel('paytpvcom/standard');

        $session = Mage::getSingleton('checkout/session');     

        $order_id = $session->getLastOrderId();
        $order = Mage::getModel('sales/order');
        $order = $order->load($order_id);

        $operation = 109;

        Mage::getSingleton('adminhtml/session_quote')->clear();

        Mage::register('current_order', $order);

        $iframeUrl = $standard->getPayTpvBankStoreUrl()."?" .http_build_query( $standard->getBankStoreTokenFormFields($operation) );
        
        $this->assign( "iframeUrl", $iframeUrl );

    }
}
