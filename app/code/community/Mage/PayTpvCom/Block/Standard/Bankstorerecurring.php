<?php
class Mage_PayTpvCom_Block_Standard_Bankstorerecurring extends Mage_Core_Block_Template
{
    protected function _construct()
    {

        parent::_construct();
        $standard = Mage::getModel('paytpvcom/standard');

        $session= Mage::getSingleton('core/session');
        $arrDatos = array();
        foreach ($session->getData() as $k => $value){
            $key = $standard->_getValidParamKey($k);
            if ($key){
                $session->unsetData($k);
            }
            $arrDatos[$key] = $value;
        }
        
       
        $session = Mage::getSingleton('checkout/session');

        Mage::getSingleton('adminhtml/session_quote')->clear();

        $iframeUrl = $standard->getPayTpvBankStoreUrl()."?" .http_build_query( $standard->getBankStorerecurringTokenFormFields($arrDatos) );
        
        $this->assign( "iframeUrl", $iframeUrl );

    }
}
