<?php
class Mage_PayTpvCom_Model_System_Config_Source_Terminales
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('paytpvcom')->__('Secure')),
            array('value'=>1, 'label'=>Mage::helper('paytpvcom')->__('No Secure')),
            array('value'=>2, 'label'=>Mage::helper('paytpvcom')->__('Both'))
        );
    }
}
