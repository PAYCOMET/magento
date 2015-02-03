<?php

if (!version_compare(Mage::getVersion(), '1.7', '>=')) {
    
    class Mage_Paytpvcom_Model_Resource_Customer_Collection extends Varien_Data_Collection_Db
    {
        

        public function __construct()
        {
            parent::__construct(Mage::getSingleton('core/resource')->getConnection('core_read'));
            $this->_customerTable = Mage::getSingleton('core/resource')->getTableName('paytpvcom/customer');

            $this->_select->from(array('customer'=>$this->_customerTable));
            $this->setItemObjectClass(Mage::getConfig()->getModelClassName('paytpvcom/customer'));

        }

    }

}else{

    class Mage_Paytpvcom_Model_Resource_Customer_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
    {
        /**
         * Initialize connection
         */
        protected function _construct()
        {
            $this->_init('paytpvcom/customer');
        }


         /**
         * Filter customer collection by id_customer
         *
         * @param string $id_customer
         * @return Mage_Paytpvcom_Model_Resource_Customer_Collection
         */
        public function addIdCustomerFilter($id_customer)
        {
            $this->getSelect()->where('customer.id_customer = ?', $id_customer);
            return $this;
        }


    }

}