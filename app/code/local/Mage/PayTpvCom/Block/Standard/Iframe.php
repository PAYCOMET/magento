<?php

class Mage_PayTpvCom_Block_Standard_Iframe extends Mage_Core_Block_Template {

	protected function _construct() {
		parent::_construct();
		$standard = Mage::getModel( 'paytpvcom/standard' );
		$iframeUrl = http_build_query( $standard->getStandardCheckoutFormFields() );
		$this->assign( "iframeUrl", $standard->getPayTpvIframeUrl()."?" . $iframeUrl );
	}

}
