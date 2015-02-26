<?php
class Mage_PayTpvCom_Model_System_Config_Source_Transactions
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('adminhtml')->__('Sale (Auth + Capture)')),
            array('value'=>1, 'label'=>Mage::helper('adminhtml')->__('Pre-Authorization'))
        );
    }
}
