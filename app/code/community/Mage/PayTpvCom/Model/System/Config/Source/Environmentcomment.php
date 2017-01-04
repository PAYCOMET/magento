<?php
class Mage_PayTpvCom_Model_System_Config_Source_EnvironmentComment extends Mage_Core_Model_Config_Data
{
    public function getCommentText(Mage_Core_Model_Config_Element $element, $currentValue)
    {
    	
        $result = "<p id='dynamic_comment'></p>";
        $result .= "<script type='text/javascript'>
            function render_comment()
            {
               
                var field_value = $('payment_module_settings_environment').getValue();
                var comment = $('dynamic_comment');
                    switch (field_value)
                    {
                        case '0':
                        	comment.innerHTML = '';
                            comment.setStyle({display: 'none'});
                            break;
                            
                        case '1':
                       		comment.setStyle({display: 'block'});
                            comment.innerHTML = '".__('<b>Attention: This environment does not make calls to PAYTPV systems. If you have Sandbox account in PAYTPV use Real Mode</b><br/>')."' + '".__('Test Mode Credit Cards (MASTERCARD): 5325298401138208 / 5392661198415436 / 5534958931200656.<br>Expiration Date: Month: 5 / Year: 2020<br>CVC2: 123 / 3DSecure: 1234')."';
                            
                            break;
                            
                    }
            }
 
            function init_comment()
            {
                render_comment();
                $('payment_module_settings_environment').observe('change', function(){
                    render_comment();
                });
            }
            document.observe('dom:loaded', function(){init_comment();});
            </script>";
 
        return $result;
    }
}
