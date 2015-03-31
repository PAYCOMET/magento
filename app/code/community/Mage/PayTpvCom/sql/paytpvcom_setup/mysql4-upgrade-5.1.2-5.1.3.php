<?php

$installer = $this;

$installer->startSetup();

// Limpieza de columnas sales/order.
try{
    // Eliminanos columna paytpv_cc
    $tablequote = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tablequote
        DROP  `paytpv_cc`;
    ");
}catch (exception $e){}

try{
    // Eliminanos columna paytpv_card_country_iso3
    $tablequote = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tablequote
        DROP  `paytpv_card_country_iso3`;
    ");
}catch (exception $e){}

try{
    // Eliminanos columna paytpv_card_type
    $tablequote = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tablequote
        DROP  `paytpv_card_type`;
    ");
}catch (exception $e){}

try{
     // Eliminanos columna paytpv_card_brand
    $tablequote = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tablequote
        DROP  `paytpv_card_brand`;
    ");
}catch (exception $e){}


if (version_compare(Mage::getVersion(), '1.6', '>=')) {
    // Card Brand
    $installer->getConnection()
        ->addColumn(
            $installer->getTable('sales/order'), 
            'paytpv_card_brand',  
            array(
                'type'      => Varien_Db_Ddl_Table::TYPE_TEXT,
                'length'    => 45,
                'nullable'  => true,
                'default'   => null,
                'comment'   => 'Card Brand'
            )
        );
    // BicCode
    $installer->getConnection()
        ->addColumn(
            $installer->getTable('sales/order'), 
            'paytpv_bic_code',  
            array(
                'type'      => Varien_Db_Ddl_Table::TYPE_TEXT,
                'length'    => 11,
                'nullable'  => true,
                'default'   => null,
                'comment'   => 'BicCode'
            )
        );
}else{

    // Add CardBrand & BicCode
    $tableorder = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tableorder
        ADD  `paytpv_card_brand` VARCHAR( 45 ) NULL DEFAULT NULL,
        ADD  `paytpv_bic_code` VARCHAR( 11 ) NULL DEFAULT NULL;
    ");
}

$installer->endSetup();