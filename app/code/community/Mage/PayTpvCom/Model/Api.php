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
    public function callManageRecurringPaymentsProfileStatus($newState, $currentState)
    {
    $mail = Mage::getModel('core/email');
    $mail->setToName(Mage::getStoreConfig('trans_email/ident_sales/name'));
    $mail->setToEmail(Mage::getStoreConfig('trans_email/ident_sales/email'));
    $mail->setBody('Estado actual ' . $currentState . ' -> nuevo estado: ' . $newState);
    $mail->setSubject('Actualización de estado de la suscripción');
    $mail->setFromEmail(Mage::getStoreConfig('trans_email/ident_contact/name'));
    $mail->setFromName(Mage::getStoreConfig('trans_email/ident_contact/email'));
    $mail->setType('html'); // YOu can use Html or text as Mail format
    try {
        $mail->send();
        Mage::getSingleton('core/session')->addSuccess('Your request has been sent');
    } catch (Exception $e) {
        Mage::getSingleton('core/session')->addError('Unable to send.');
    }
    }
}
