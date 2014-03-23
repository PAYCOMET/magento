<?php
class Mage_PayTpvCom_Block_Standard_Form extends Mage_Payment_Block_Form_Cc
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getSingleton('paytpvcom/standard');
        $this->setPaytpvCc(Mage::getSingleton('customer/session')->getCustomer()->getPaytpvCc());
        switch ($standard->getConfigData( 'operativa' )) {
            case Mage_PayTpvCom_Model_Standard::OP_BANKSTORE;
                $this->setTemplate('paytpvcom/form_bankstore_ws.phtml');
                break;
            default:
                $this->setTemplate('paytpvcom/form.phtml');
        }
    }
}