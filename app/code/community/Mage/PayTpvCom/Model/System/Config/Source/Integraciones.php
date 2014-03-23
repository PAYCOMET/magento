<?php
class Mage_PayTpvCom_Model_System_Config_Source_Integraciones
{
    public function toOptionArray()
    {
        return [
            ['value'=>0, 'label'=>Mage::helper('adminhtml')->__('OFFSITE')],
            ['value'=>1, 'label'=>Mage::helper('adminhtml')->__('IFRAME')]
        ];
    }
}
