<?php

if (!version_compare(Mage::getVersion(), '1.7', '>=')) {

	class Mage_Paytpvcom_Model_Resource_Customer extends Mage_Core_Model_Mysql4_Abstract
	{
	     protected function _construct()
	    {
	        $this->_init('paytpvcom/customer', 'customer_id');
	    }

	}

}else{

	class Mage_Paytpvcom_Model_Resource_Customer extends Mage_Core_Model_Resource_Db_Abstract
	{
	    /**
	     * Initialize connection
	     */
	    protected function _construct()
	    {
	        $this->_init('paytpvcom/customer', 'customer_id');
	    }
	}	


}