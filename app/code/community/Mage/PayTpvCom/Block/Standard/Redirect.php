<?php

class Mage_PayTpvCom_Block_Standard_Redirect extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $standard = Mage::getModel('paytpvcom/standard');
        $form = new Varien_Data_Form();
        $form->setAction($standard->getPayTpvUrl())
            ->setId('paytpv_standard_checkout')
            ->setName('PayTpv')
            ->setMethod('POST')
            ->setUseContainer(true);

        foreach ($standard->getStandardCheckoutFormFields() as $field=>$value) {
            $form->addField($field, 'hidden', array('name'=>$field, 'value'=>$value));
        }
        $this->setFormRedirect($form->toHtml());
    }
}
