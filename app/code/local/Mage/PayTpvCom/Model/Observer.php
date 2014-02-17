<?php

class Mage_PayTpvCom_Model_Observer {

	public function submitAllAfter( $observer ) {
		$observer;
		if ( !isset( $observer[ 'orders' ] ) ) {
			$quote = $observer[ 'quote' ];
			$redirectUrl = $quote->getPayment()->getRecurringProfileSetRedirectUrl();
			;
			Mage::getSingleton( 'checkout/session' )->setRedirectUrl( $redirectUrl );
		}
	}

}

