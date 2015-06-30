<?php

/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @category    Mage
 * @package     Mage_PayTpvCom
 * @copyright   Copyright (c) 2012 PayTPV S.L. Inc. (http://www.magentocommerce.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Mage_PayTpvCom_Model_Api extends Varien_Object
{

    private $_client = null;

    private $_recurringProfile = null;

    private $_payment = null;

    private $_merchanttransid = null;

   
    public function setRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile){
        $this->_recurringProfile = $profile;
    }

    public function setPayment($payment){
        $this->_payment = $payment;
    }

    private function getClient()
    {
        if (null == $this->_client)
            $this->_client = new Zend_Soap_Client('https://secure.paytpv.com/gateway/xml_bankstore.php?wsdl');
        $this->_client->setSoapVersion(SOAP_1_1);
        return $this->_client;
    }


    public function callManageRecurringPaymentsProfileStatus($profile,$action)
    {
        $newState = $profile->getNewState();
        $currentState = $profile->getState();

        $model = Mage::getModel('paytpvcom/standard');
        $paytpv_tokenuser = substr($profile->getReferenceId(),2);


        $profileAdditionalInfo = $profile->getAdditionalInfo();
        $paytpv_iduser = $profileAdditionalInfo["paytpv_iduser"];
        $paytpv_iduser = explode("paytpv_iduser_",$paytpv_iduser);
        // Antiguo dato en additiona info (no va el texto paytpv_iduser_)
        if (sizeof($paytpv_iduser)==1){
            $paytpv_iduser = $paytpv_iduser[0];
        }else{
            $paytpv_iduser = $paytpv_iduser[1];
            $paytpv_iduser = str_replace("_","",$paytpv_iduser);
        }

        $res = $this->removeSuscription( $paytpv_iduser, $paytpv_tokenuser);
        
        
        if ((int)$res['DS_ERROR_ID'] == 0){

            $mail = Mage::getModel('core/email');
            $mail->setToName(Mage::getStoreConfig('trans_email/ident_sales/name'));
            $mail->setToEmail(Mage::getStoreConfig('trans_email/ident_sales/email'));
            $mail->setBody('Estado actual ' . $currentState . ' -> nuevo estado: ' . $newState);
            $mail->setSubject('Actualización de estado de la suscripción');
            $mail->setFromEmail(Mage::getStoreConfig('trans_email/ident_contact/name'));
            $mail->setFromName(Mage::getStoreConfig('trans_email/ident_contact/email'));
            $mail->setType('html'); // YOu can use Html or text as Mail format
            try {
                //$mail->send();
                Mage::getSingleton('core/session')->addSuccess('Your request has been sent');
            } catch (Exception $e) {
                Mage::getSingleton('core/session')->addError('Unable to send.');
            }
        }
        return $res;
    }

    /**
     * CreateRecurringPaymentsProfile call
     */
    public function callCreateRecurringPaymentsProfile(){
        $model = Mage::getModel('paytpvcom/standard');
        if ($model->getConfigData('environment')!=1){
            $res = $this->addUser();
        // Test Mode
        }else{
            $res = $this->addUserTest();
        }
        return $res;
    }

    /**
     * CreateRecurringPaymentsProfile call
     */
    public function callCreateRecurringPaymentsSuscriptionToken($DS_IDUSER,$DS_TOKEN_USER){
        
        $res = $this->createSubscriptionToken($DS_IDUSER,$DS_TOKEN_USER);
        return $res;
    }

    protected function _isStartDateToday($profile){
        $start = Mage::getModel('core/date')->date('Y-m-d', strtotime($profile->getStartDatetime()));
        $now = Mage::getModel('core/date')->date('Y-m-d', time());
        
        return $start == $now;
    }

    protected function _calculateStartDate($profile){
        $start = Mage::getModel('core/date')->date('Y-m-d', strtotime($profile->getStartDatetime()));
        
        switch ($profile->getPeriodUnit()){
            case 'day':         $added = $profile->getPeriodFrequency() . ' day'; break;
            case 'week':        $added = $profile->getPeriodFrequency() . ' week'; break;
            case 'semi_month':  $added = ($profile->getPeriodFrequency()*2) . ' week'; break;
            case 'month':       $added = $profile->getPeriodFrequency() . ' month'; break;
            case 'year':        $added = $profile->getPeriodFrequency() . ' year'; break;
            default: Mage::throwException('Unable to calculate start date');
        }
        
        return Mage::getModel('core/date')->date('Y-m-d', strtotime($start . ' +' . $added));
    }

    public function getMerchantTransId($token){
        if ($this->_merchanttransid === null){
            $this->_merchanttransid = 'S-' . $token;
        }
        return $this->_merchanttransid;
    }

    private function addUser()
    {
        $model = Mage::getModel('paytpvcom/standard');

        $DS_MERCHANT_MERCHANTCODE = $model->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $model->getConfigData('terminal');
        $DS_MERCHANT_PAN = $this->_payment['cc_number'];
        $DS_MERCHANT_EXPIRYDATE = str_pad($this->_payment['cc_exp_month'], 2, "0", STR_PAD_LEFT) . substr($this->_payment['cc_exp_year'], 2, 2);
        $DS_MERCHANT_CVV2 = $this->_payment['cc_cid'];
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_MERCHANT_PAN . $DS_MERCHANT_CVV2 . $DS_MERCHANT_TERMINAL . $model->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];
        return $this->getClient()->add_user(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_MERCHANT_PAN,
            $DS_MERCHANT_EXPIRYDATE,
            $DS_MERCHANT_CVV2,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
    }


    private function addUserTest()
    {
        $model = Mage::getModel('paytpvcom/standard');

        // Test Mode
        // First 100.000 paytpv_iduser for Test_Mode
        if (in_array(trim($this->_payment['cc_number']),$model->_arrTestCard) && str_pad($this->_payment['cc_exp_month'], 2, "0", STR_PAD_LEFT)==$model->_TestCard_mm && substr($this->_payment['cc_exp_year'], 2, 2)==$model->_TestCard_yy && $this->_payment['cc_cid']==$model->_TestCard_merchan_cvc2){
            $model = Mage::getModel('paytpvcom/customer');
            $collection = $model->getCollection()
                ->addFilter("id_customer",Mage::getSingleton('customer/session')->getCustomer()->getId(),"and")
                ->addFieldToFilter('paytpv_iduser', array('lt' => 100000))
                ->setOrder('paytpv_iduser', 'DESC')
                ->getFirstItem()->getData();
            if (empty($collection) === true){
                $paytpv_iduser = 1;
            }else{
                $paytpv_iduser = $collection["paytpv_iduser"]+1;
            }
            $paytpv_tokenuser = "TESTTOKEN_".date("dmyHis");

            $res["DS_IDUSER"] = $paytpv_iduser;
            $res["DS_TOKEN_USER"] = $paytpv_tokenuser;
            $res["DS_ERROR_ID"] = 0;
        }else{
            $res["DS_ERROR_ID"] = 4001;
        }   
        return $res;

    }

    private function removeSuscription($idUser, $tokeUser)
    {
        
        $model = Mage::getModel('paytpvcom/standard');
        
        $DS_MERCHANT_MERCHANTCODE = $model->getConfigData('client');
        $DS_MERCHANT_TERMINAL = $model->getConfigData('terminal');
        $DS_IDUSER = $idUser;
        $DS_TOKEN_USER = $tokeUser;
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1( $DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $model->getConfigData('pass'));
        $DS_ORIGINAL_IP = $_SERVER['REMOTE_ADDR'];
        
        if ($DS_ORIGINAL_IP=="::1") $DS_ORIGINAL_IP = "127.0.0.1";

        return $this->getClient()->remove_subscription(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
    }


    

    private function createSubscriptionToken($DS_IDUSER,$DS_TOKEN_USER)
    {

        $model = Mage::getModel('paytpvcom/standard');

        $amount = $model->_formatAmount($model->getQuote()->getStore()->convertPrice($this->_recurringProfile->getInitAmount()));

        $DS_MERCHANT_MERCHANTCODE = $model->getConfigData('client');
        $freq = $this->_recurringProfile->getPeriodFrequency();
        switch ($this->_recurringProfile->getPeriodUnit()){
            case 'day':         $subs_periodicity = 1; break;
            case 'week':        $subs_periodicity = 7; break;
            case 'semi_month':  $subs_periodicity = 14; break;
            case 'month':       $subs_periodicity = 30; break;
            case 'year':        $subs_periodicity = 365; break;
        }

        $subs_cycles = 0;
        if ($this->_recurringProfile->getPeriodMaxCycles())
            $subs_cycles = $this->_recurringProfile->getPeriodMaxCycles();
       
        $DS_SUBSCRIPTION_STARTDATE = Mage::getModel('core/date')->date('Y-m-d', strtotime($this->_recurringProfile->getStartDatetime()));
        
        // Si es indefinido, ponemos como fecha tope la fecha + 5 años.
        if ($subs_cycles==0){
            $subscription_enddate = $DS_SUBSCRIPTION_STARTDATE("Y")+5 . "-" . $DS_SUBSCRIPTION_STARTDATE("m") . "-" . $DS_SUBSCRIPTION_STARTDATE("d");
        }else{
            // Dias suscripcion
            $dias_subscription = $subs_cycles * $subs_periodicity;
            //$subscription_enddate = date('Y-m-d', strtotime("+".$dias_subscription." days"));
            $subscription_enddate = Mage::getModel('core/date')->date('Y-m-d', strtotime($this->_recurringProfile->getStartDatetime(). " +".$dias_subscription." days"));
        }

        $DS_SUBSCRIPTION_ENDDATE = $subscription_enddate;
        $DS_SUBSCRIPTION_PERIODICITY = $subs_periodicity;

        $DS_SUBSCRIPTION_AMOUNT = round($amount * 100);
        $DS_SUBSCRIPTION_ORDER = $this->getMerchantTransId($DS_TOKEN_USER);
        $DS_SUBSCRIPTION_CURRENCY = Mage::app()->getStore()->getCurrentCurrencyCode();
        $DS_MERCHANT_TERMINAL = $model->getConfigData('terminal');
        $DS_MERCHANT_MERCHANTSIGNATURE = sha1($DS_MERCHANT_MERCHANTCODE . $DS_IDUSER . $DS_TOKEN_USER . $DS_MERCHANT_TERMINAL . $DS_SUBSCRIPTION_AMOUNT . $DS_SUBSCRIPTION_CURRENCY . $model->getConfigData('pass'));
        $DS_ORIGINAL_IP = $original_ip != '' ? $original_ip : $_SERVER['REMOTE_ADDR'];

       
             
        return $this->getClient()->create_subscription_token(
            $DS_MERCHANT_MERCHANTCODE,
            $DS_MERCHANT_TERMINAL,
            $DS_IDUSER,
            $DS_TOKEN_USER,
            $DS_SUBSCRIPTION_STARTDATE,
            $DS_SUBSCRIPTION_ENDDATE,
            $DS_SUBSCRIPTION_ORDER,
            $DS_SUBSCRIPTION_PERIODICITY,
            $DS_SUBSCRIPTION_AMOUNT,
            $DS_SUBSCRIPTION_CURRENCY,
            $DS_MERCHANT_MERCHANTSIGNATURE,
            $DS_ORIGINAL_IP
        );
    }


    
}
