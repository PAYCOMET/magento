<?php

class Mage_PayTpvCom_Helper_Data extends Mage_Core_Helper_Abstract
{

	public function prepare3ds($data){
		$session = Mage::getSingleton('core/session');	
		foreach ($data as $k => $v){
			$session->setData( 'paytpv3ds_' . (string)$k, (string)$v );
		}
	}
}
