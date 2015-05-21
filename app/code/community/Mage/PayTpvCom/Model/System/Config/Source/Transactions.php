<?php
class Mage_PayTpvCom_Model_System_Config_Source_Transactions
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('paytpvcom')->__('Sale (Authorize + Capture)')),
            array('value'=>1, 'label'=>Mage::helper('paytpvcom')->__('Pre-Authorization'))
        );
    }
}
