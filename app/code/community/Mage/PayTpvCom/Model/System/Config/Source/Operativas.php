<?php
class Mage_PayTpvCom_Model_System_Config_Source_Operativas
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('adminhtml')->__('TPV WEB')),
            array('value'=>3, 'label'=>Mage::helper('adminhtml')->__('BANK STORE'))
        );
    }
}
