<?php
class Mage_PayTpvCom_Block_Standard_Bankstore3dstest extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getModel('paytpvcom/standard');

        $session = Mage::getSingleton('checkout/session');     

        $order_id = $session->getLastOrderId();
        $order = Mage::getModel('sales/order');
        $order = $order->load($order_id);

        $transaction_type = $standard->getConfigData('transaction_type');

        $session= Mage::getSingleton('core/session');
        $arrDatos = array();
        foreach ($session->getData() as $k => $value){
            $key = $standard->_getValidParamKey($k);
            $arrDatos[$key] = $value;
        }
        

        $currency_symbol = Mage::app()->getLocale()->currency(Mage::app()->getStore()->getCurrentCurrencyCode())->getSymbol();

        
        Mage::getSingleton('adminhtml/session_quote')->clear();

        Mage::register('current_order', $order);
       
        
        $this->assign( "SHOP_NAME",Mage::app()->getStore()->getFrontendName());
        $this->assign( "FECHA",date("d/m/Y") );
        $this->assign( "HORA",date("H:i:s"));
        $this->assign( "URL_NOT",Mage::getUrl('paytpvcom/standard/callback'));
        $this->assign( "URL_KO",Mage::getUrl('paytpvcom/standard/cancel'));

        if ($_POST["TransactionType"]){

            $this->assign( "MERCHANT_AMOUNT_DECIMAL",number_format($_POST["Amount"]/100, 2, '.', '') );
            
            $this->assign( "TRANSACTION_TYPE",$_POST["TransactionType"]);
            $this->assign( "MERCHANT_ORDER",$_POST["Order"]);
            $this->assign( "MERCHANT_AMOUNT",$_POST["Amount"]);

            $this->assign( "MERCHANT_MERCHANTSIGNATURE",$_POST["ExtendedSignature"]);
            $this->assign( "ID_USER",$_POST["IdUser"]);
            $this->assign( "TOKEN_USER",$_POST["TokenUser"]);
            $this->assign( "CURRENCY_SYMBOL",$currency_symbol);
            $this->assign( "CURRENCY",$_POST["Currency"]);
            $this->assign( "MERCHAN_PAN",$_POST["cc_number"]);

        }else{

            $this->assign( "MERCHANT_AMOUNT_DECIMAL",number_format($_GET["MERCHANT_AMOUNT"]/100, 2, '.', '') );

            $this->assign( "TRANSACTION_TYPE",$_GET["OPERATION"]."_TEST");
            $this->assign( "MERCHANT_ORDER",$_GET["MERCHANT_ORDER"]);
            $this->assign( "MERCHANT_AMOUNT",$_GET["MERCHANT_AMOUNT"]);

            $this->assign( "MERCHANT_MERCHANTSIGNATURE",$_GET["MERCHANT_MERCHANTSIGNATURE"]);
            $this->assign( "ID_USER",$_GET["IDUSER"]);
            $this->assign( "TOKEN_USER",$_GET["TOKEN_USER"]);
            $this->assign( "CURRENCY_SYMBOL",$currency_symbol);
            $this->assign( "CURRENCY",$_GET["MERCHANT_CURRENCY"]);
            $this->assign( "MERCHAN_PAN",$arrDatos["CC_NUMBER"]);

        }


        $this->assign( "BASE_URL",Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB,array('_secure'=>true)));


        $this->assign( "TESTAUTHCODE",'TESTAUTHCODE_'.date("dmyHis"));

    }
}