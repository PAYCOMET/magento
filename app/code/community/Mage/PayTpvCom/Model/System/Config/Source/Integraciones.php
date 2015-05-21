<?php
class Mage_PayTpvCom_Model_System_Config_Source_Integraciones
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('paytpvcom')->__('OFFSITE')),
            array('value'=>1, 'label'=>Mage::helper('paytpvcom')->__('IFRAME at he end')),
//            array('value'=>1, 'label'=>Mage::helper('adminhtml')->__('IFRAME at he end')),
//            array('value'=>2, 'label'=>Mage::helper('adminhtml')->__('IFRAME in payment method selection'))
        );
    }
}
