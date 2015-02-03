<?php
class Mage_PayTpvCom_Block_Standard_Form extends Mage_Payment_Block_Form_Cc
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getSingleton('paytpvcom/standard');

        // Miramos a ver si tiene tarjetas tokenizadas
        $model = Mage::getModel('paytpvcom/standard');

        $Secure = ($model->isSecureTransaction())?1:0;

        $this->setCustomerCards($model->loadCustomerCards());

        // obtenemos el tipo de metodo de pedido seleccionado (guest, register, login_in)
        $method_checkout = Mage::getSingleton('checkout/type_onepage')->getQuote()->getCheckoutMethod();

        //print_r( Mage::getSingleton('checkout/type_onepage')->getQuote());
       
        $isRecurring = $model->isRecurring();

        $this->assign( "isRecurring", $isRecurring);
        $this->assign( "method_checkout", $method_checkout );
        $this->assign( "terminales", $model->getConfigData('terminales'));
        $this->assign( "Secure", $Secure);
        $this->assign( "commerce_password", $model->getConfigData('commerce_password'));
        $this->assign( "show_nameoncard", $model->getConfigData('show_nameoncard'));
        $this->assign( "show_cctypes", $model->getConfigData('show_cctypes'));


		$this->setTemplate($standard->getStandardFormTemplate());
    }
}