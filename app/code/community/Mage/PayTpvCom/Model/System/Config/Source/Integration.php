<?php
class Mage_PayTpvCom_Model_System_Config_Source_Integration
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('paytpvcom')->__('BankStore IFRAME/XML')),
            array('value'=>1, 'label'=>Mage::helper('paytpvcom')->__('BankStore JET/XML'))
        );
    }
}
