<?php
class Mage_PayTpvCom_Model_System_Config_Source_Scoring
{
    public function toOptionArray()
    {
    	
        for ($i=0;$i<101;$i++){
            $arr[$i] = array('value'=>$i,'label'=>$i);
        }
        return $arr;
    }
}
