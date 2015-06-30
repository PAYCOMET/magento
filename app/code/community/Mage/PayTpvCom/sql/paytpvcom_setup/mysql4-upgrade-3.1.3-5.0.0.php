<?php
$installer = $this;

$installer->startSetup();



// Eliminamos las variables de configuracion 
$connection = $installer->getConnection();


// Eliminamos los attributos de customer
$installer->removeAttribute('customer', 'paytpv_iduser');
$installer->removeAttribute('customer', 'paytpv_tokenuser');
$installer->removeAttribute('customer', 'paytpv_cc');
$installer->removeAttribute('customer', 'paytpv_recall');

try{


    if (version_compare(Mage::getVersion(), '1.6', '>=')) {
        // Creamos una tabla para las tarjetas tokenizadas de los usuarios
        if ($installer->getConnection()->isTableExists($installer->getTable('paytpvcom/customer')) != true) {
            $table = $installer->getConnection()
            ->newTable($installer->getTable('paytpvcom/customer'))
            ->addColumn('customer_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'identity'  => true,
                'unsigned'  => true,
                'nullable'  => false,
                'primary'   => true,
                ), 'customer id')
            ->addColumn('paytpv_iduser', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'unsigned'  => true,
                'nullable'  => false,
                ), 'paytpv iduser')
            ->addColumn('paytpv_tokenuser', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
                'nullable'  => false,
                ), 'tokenuser')
            ->addColumn('paytpv_cc', Varien_Db_Ddl_Table::TYPE_TEXT, 32, array(
                'nullable'  => false,
                ), 'paytpv cc')
            ->addColumn('paytpv_brand', Varien_Db_Ddl_Table::TYPE_TEXT, 32, array(
                'nullable'  => false,
                'default'   => '0',
                ), 'paytpv brand')
            ->addColumn('id_customer', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
                'unsigned'  => true,
                'nullable'  => false,
                ), 'Id customer')
            ->addColumn('date', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
                'nullable'  => true), 
            'Date')
            ->setComment('Paytpv customer');
            $installer->getConnection()->createTable($table);
        }
    }else{

        $installer->run("
        CREATE TABLE IF NOT EXISTS `{$installer->getTable('paytpvcom_customer')}` (
          `customer_id` int(10) unsigned NOT NULL auto_increment,
          `paytpv_iduser` int(10) unsigned NOT NULL,
          `paytpv_tokenuser` varchar(64) NOT NULL,
          `paytpv_cc` varchar(32) NOT NULL,
          `paytpv_brand` varchar(32) NOT NULL default '0',
          `id_customer` int(10) unsigned NOT NULL default '0',
          `date` datetime NULL default null,
          PRIMARY KEY (`customer_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }
}catch (exception $e){}

try{
    // Eliminanos columnas de order. Solo deben quedar paytpv_iduser,paytpv_tokenuser
    $tablequote = $this->getTable('sales/order');
    $installer->run("
    ALTER TABLE  $tablequote
        DROP  `paytpv_cc`,
        DROP  `paytpv_card_country_iso3`,
        DROP  `paytpv_card_type`,
        DROP  `paytpv_card_brand`;
    ");
}catch (exception $e){}

$installer->endSetup();