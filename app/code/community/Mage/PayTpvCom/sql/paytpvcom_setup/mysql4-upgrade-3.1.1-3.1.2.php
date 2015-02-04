<?php
$installer = $this;

$installer->startSetup();
$setup = Mage::getModel('customer/entity_setup', 'core_setup');
$setup->addAttribute('customer', 'paytpv_recall', array(
	'type' => 'int',
	'global' => 1,
	'visible' => 0,
	'required' => 0,
	'user_defined' => 0,
	'default' => '1',
	'visible_on_front' => 0
));

try{
	$tableorder = $this->getTable('sales/order');
	$installer->run("
	ALTER TABLE  $tableorder
	    ADD `paytpv_card_country_iso3` VARCHAR( 3 ) NULL DEFAULT NULL,
	    ADD `paytpv_card_type` VARCHAR( 32 ) NULL DEFAULT NULL,
	    ADD `paytpv_card_brand` VARCHAR( 32 ) NULL DEFAULT NULL ;
	");
}catch (exception $e){}

$installer->endSetup();
