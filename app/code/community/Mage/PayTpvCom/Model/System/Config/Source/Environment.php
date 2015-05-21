<?php
class Mage_PayTpvCom_Model_System_Config_Source_Environment
{
    public function toOptionArray()
    {
        return array(
            array('value'=>0, 'label'=>Mage::helper('paytpvcom')->__('Real Mode')),
            array('value'=>1, 'label'=>Mage::helper('paytpvcom')->__('Test Mode'))
        );
    }

    public function toArray()
    {
        return array(
            0   => Mage::helper('paytpvcom')->__('Real Mode'),
            1   => Mage::helper('paytpvcom')->__('Test Mode')
        );
    }
}
