<?php

$installer = $this;

$model = Mage::getSingleton('paytpvcom/standard');
$model->write_log();

$installer->endSetup();