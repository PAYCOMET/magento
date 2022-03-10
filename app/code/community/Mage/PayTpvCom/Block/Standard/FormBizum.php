<?php
class Mage_PayTpvCom_Block_Standard_FormBizum extends Mage_Core_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
		$this->setTemplate('paytpvcom/form_bizum.phtml');
    }
}