<?php
class Mage_PayTpvCom_Block_Standard_Tarjetas extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();

        // Miramos a ver si tiene tarjetas tokenizadas
        $model = Mage::getModel('paytpvcom/standard');
        
        $this->setCustomerCards($model->loadCustomerCards());

        $operation = 107;

        $iframeUrl = $model->getPayTpvBankStoreUrl()."?" .http_build_query( $model->getBankStoreFormFields($operation) );
        $this->assign( "iframeUrl", $iframeUrl );
        $this->assign( "show_nameoncard", $model->getConfigData('show_nameoncard'));
        $this->setTemplate("paytpvcom/tarjetas.phtml");

    }
}
