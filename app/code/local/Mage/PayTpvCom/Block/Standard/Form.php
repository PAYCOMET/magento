<?php
class Mage_PayTpvCom_Block_Standard_Form extends Mage_Payment_Block_Form {
	protected function _construct() {
		$this->setTemplate('paytpvcom/form.phtml');
		parent::_construct();
	}
}
?>
