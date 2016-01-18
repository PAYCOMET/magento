<?php

class Mage_PayTpvCom_Block_Standard_Bankstoreiframe extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getModel( 'paytpvcom/standard' );
        $iframeUrl = '';

        $transaction_type = $standard->getConfigData('transaction_type');

        $operation = ($transaction_type == $standard::PREAUTHORIZATION)?3:1;

        $iframeUrl = $standard->getPayTpvBankStoreUrl()."?" .http_build_query( $standard->getBankStoreFormFields($operation) );

        $this->assign( "iframeUrl", $iframeUrl );
    }

}
