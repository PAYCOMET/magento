<?php
class Mage_PayTpvCom_Model_System_Config_Source_Operativas {
	public function toOptionArray(){
		return array(
            array('value'=>0, 'label'=>Mage::helper('adminhtml')->__('Offsite')),
            array('value'=>1, 'label'=>Mage::helper('adminhtml')->__('Iframe')),
            array('value'=>1, 'label'=>Mage::helper('adminhtml')->__('BankStore'))
		);
	}
}
?>
