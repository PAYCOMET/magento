<?php

class Mage_PayTpvCom_Model_Observer
{
    public function submitAllAfter( $observer )
    {
//		$quote = $observer[ 'quote' ];
//		$model = Mage::getModel('paytpvcom/standard');
//		if($quote->getPayment()->getMethodInstance()->getCode()!=$model->getCode())
//			return;
//
//		$order = $observer[ 'order' ];
//		if ($order) {//No recurrente
//			$model->executePurchase()
//		} else { // Recurrente
//			$quote = $observer[ 'quote' ];
//			$redirectUrl = $quote->getPayment()->getRecurringProfileSetRedirectUrl();
//			Mage::getSingleton( 'checkout/session' )->setRedirectUrl( $redirectUrl );
//		}
    }

}
