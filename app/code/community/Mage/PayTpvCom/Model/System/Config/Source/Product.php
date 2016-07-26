<?php
class Mage_PayTpvCom_Model_System_Config_Source_Product
{
    public function toOptionArray($isMultiselect = false)
    {
		
        $options = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToFilter('status', 1)
           
            ->addAttributeToSort('name', 'ASC');
        
        foreach ($options as $product) {
            $items[] = array("value"=>$product->getId(), "label"=>$product->getName() . " (" . $product->getSku() . ")");
        }

        return $items;
    }
}
