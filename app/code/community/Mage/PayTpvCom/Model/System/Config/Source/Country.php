<?php
class Mage_PayTpvCom_Model_System_Config_Source_Country
{
    public function toOptionArray($isMultiselect = false)
    {
    	
        $options = Mage::getResourceModel('directory/country_collection')
            ->loadData()
            ->toOptionArray($isMultiselect ? false : Mage::helper('adminhtml')->__('--Please Select--'));

        return $options;
    }
}
