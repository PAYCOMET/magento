<?php

class Mage_PayTpvCom_Block_Standard_Bankstorebizum extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        
        parent::_construct();
        $standard = Mage::getModel( 'paytpvcom/standardbizum' );
        
        // Obtenemos la URL de pago Bizum
        $url = $standard->getBizumUrl();

        Mage::app()->getResponse()->setRedirect($url);

    }

}
