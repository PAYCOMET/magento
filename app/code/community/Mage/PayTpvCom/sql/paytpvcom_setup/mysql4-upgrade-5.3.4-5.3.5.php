<?php

$installer = $this;

$installer->startSetup();

$model = Mage::getSingleton('paytpvcom/standard');
$model->write_log();

$installer->endSetup();