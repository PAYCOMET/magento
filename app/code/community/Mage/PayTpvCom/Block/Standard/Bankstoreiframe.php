<?php

class Mage_PayTpvCom_Block_Standard_Bankstoreiframe extends Mage_Core_Block_Template
{
    protected function _construct()
    {

        
        parent::_construct();
        $standard = Mage::getModel( 'paytpvcom/standard' );
        $iframeUrl = '';

        $transaction_type = $standard->getConfigData('transaction_type');

        $operation = ($transaction_type == $standard::PREAUTHORIZATION)?3:1;

        $iframeUrl = $standard->getPayTpvBankStoreUrl()."?" .http_build_query( $standard->getBankStoreFormFields($operation) );

        $order_id = $standard->getCheckout()->getLastRealOrderId();
        $order = Mage::getModel('sales/order');
        $order->loadByIncrementId($order_id);

        $amount = $currency='';
        $total_amount = number_format($order->getGrandTotal(),2);

        // If Status not PENDING OR PAYMENT_REVIEW go to HOME.
        $order_status = $order->getStatus();
        if ($order_status!= 'pending' && $order_status != Mage_Sales_Model_Order::STATE_PENDING_PAYMENT && $order_status != Mage_Sales_Model_Order::STATE_PAYMENT_REVIEW){
            Mage::app()->getResponse()->setRedirect(Mage::getBaseUrl());
            return;
        }

        $currency_code = $order->getOrderCurrencyCode();

        $currency_symbol = Mage::app()->getLocale()->currency( $currency_code )->getSymbol();

        $base_amount = number_format($order->getBaseGrandTotal(),2);

        if ($total_amount!=$base_amount){
            $base_currency_symbol = Mage::app()->getLocale()->currency( $order->getBaseCurrencyCode() )->getSymbol();
            $base_amount = " (" . $base_amount . $base_currency_symbol . ")";
        }else{
            $base_amount = "";
        }

        
        $this->assign( "iframeUrl", $iframeUrl );
        $this->assign( "remember", ($order->getPaytpvSavecard())?"checked":"");
        $this->assign( "order_id", $order_id );
        $this->assign( "total_amount", $total_amount );
        $this->assign( "currency_symbol", $currency_symbol );
        $this->assign( "base_amount", $base_amount );


        $paytpvfullscreen = $standard->getConfigData('paytpvfullscreen');

       
        if ($paytpvfullscreen==1)
            Mage::app()->getResponse()->setRedirect($iframeUrl);

    }

}
