<?php
$installer = $this;

$installer->startSetup();
try{
    $setup = Mage::getModel('customer/entity_setup', 'core_setup');
    $setup->addAttribute('customer', 'paytpv_iduser', array(
        'type' => 'int',
        'global' => 1,
        'visible' => 0,
        'required' => 0,
        'user_defined' => 0,
        'default' => '0',
        'visible_on_front' => 0
    ));
}catch (exception $e){}
try{
    $setup->addAttribute('customer', 'paytpv_tokenuser', array(
        'type' => 'varchar',
        'global' => 1,
        'visible' => 0,
        'required' => 0,
        'user_defined' => 0,
        'default' => '0',
        'visible_on_front' => 0
    ));
}catch (exception $e){}
try{
    $setup->addAttribute('customer', 'paytpv_cc', array(
        'type' => 'varchar',
        'global' => 1,
        'visible' => 0,
        'required' => 0,
        'user_defined' => 0,
        'default' => '0',
        'visible_on_front' => 0
    ));
}catch (exception $e){}
try{
    /*
    if (version_compare(Mage::getVersion(), '1.6.0', '<=')) {
        $customer = Mage::getModel('customer/customer');
        $attrSetId = $customer->getResource()->getEntityType()->getDefaultAttributeSetId();
        $setup->addAttributeToSet('customer', $attrSetId, 'General', 'paytpv_iduser');
    }

    if (version_compare(Mage::getVersion(), '1.4.2', '>=')) {
        Mage::getSingleton('eav/config')
        ->getAttribute('customer', 'paytpv_iduser')
        ->setData('used_in_forms', array('adminhtml_customer','customer_account_create','customer_account_edit','checkout_register'))
        ->save();

    }
    */

    $tablequote = $this->getTable('sales/quote');
    $installer->run("
    ALTER TABLE  $tablequote
        ADD  `paytpv_iduser` INT NOT NULL ,
        ADD  `paytpv_tokenuser` VARCHAR( 64 ) NULL DEFAULT NULL ,
        ADD  `paytpv_cc` VARCHAR( 32 ) NULL DEFAULT NULL ;
    ");
}catch (exception $e){}


$installer->endSetup();
