<?php
class Mage_PayTpvCom_Block_Standard_Form extends Mage_Payment_Block_Form {
	protected function _construct() {
		$standard = Mage::getSingleton('paytpvcom/standard');
		$op = $standard->getConfigData( 'operativa' );
		if($op==2){
			$this->setPaytpvCc(Mage::getSingleton('customer/session')->getCustomer()->getPaytpvCc());
			$this->setTemplate('paytpvcom/form_bankstore.phtml');
		}else
			$this->setTemplate('paytpvcom/form.phtml');
		parent::_construct();
	}
}
?>
