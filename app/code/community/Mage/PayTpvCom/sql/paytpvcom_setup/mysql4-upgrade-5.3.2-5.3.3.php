<?php

$installer = $this;

$installer->startSetup();

$model = Mage::getSingleton('paytpvcom/standard');
$model->write_log();

try{
    if (version_compare(Mage::getVersion(), '1.6', '>=')) {
        // Card Brand
        $installer->getConnection()
            ->addColumn(
                $installer->getTable('sales/order'), 
                'paytpv_savecard',  
                array(
                    'type'      => Varien_Db_Ddl_Table::TYPE_SMALLINT,
                    'unsigned' => true,
                    'nullable'  => false,
                    'default'   => 0,
                    'comment'   => 'Save Card'
                )
            );
    }else{

        // Add CardBrand & BicCode
        $tableorder = $this->getTable('sales/order');
        $installer->run("
        ALTER TABLE  $tableorder
            ADD  `paytpv_savecard` smallint(5) unsigned NOT NULL DEFAULT '0' COMMENT 'Save Card';
        ");
    }
}catch (exception $e){}


$installer->endSetup();