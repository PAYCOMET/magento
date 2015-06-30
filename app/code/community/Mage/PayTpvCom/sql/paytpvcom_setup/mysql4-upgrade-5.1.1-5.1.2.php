<?php

$installer = $this;

$installer->startSetup();

try{
    if (version_compare(Mage::getVersion(), '1.6', '>=')) {
        // Add card_desc
        $installer->getConnection()
            ->addColumn(
                $installer->getTable('paytpvcom/customer'), 
                'card_desc',  
                array(
                    'type'      => Varien_Db_Ddl_Table::TYPE_TEXT,
                    'length'    => 32,
                    'nullable'  => true,
                    'default'   => null,
                    'comment'   => 'Card Description'
                )
            );
    }else{

        // Add card_desc
        $table = $this->getTable('paytpvcom_customer');
        $installer->run("
        ALTER TABLE  $table
            ADD  `card_desc` VARCHAR( 32 ) NULL DEFAULT NULL;");
    }
}catch (exception $e){}

$installer->endSetup();