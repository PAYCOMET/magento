<?php
class Mage_PayTpvCom_Block_Standard_Form extends Mage_Payment_Block_Form_Cc
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getSingleton('paytpvcom/standard');
		if(Mage::getSingleton('customer/session')->getCustomer()->getPaytpvRecall()){
			$this->setPaytpvCc(Mage::getSingleton('customer/session')->getCustomer()->getPaytpvCc());
		}
        $this->setTemplate($standard->getStandardFormTemplate());
    }
}