<?php
class Mage_PayTpvCom_Model_System_Config_Source_SessionTime
{
    public function toOptionArray()
    {
    	$arrTiempos = array(0=>"000mm (00:00hh)",5=>"005mm (00:05hh)",10=>"010mm (00:10hh)",15=>"015mm (00:15hh)",20=>"020mm (00:20hh)",30=>"030mm (00:30hh)",45=>"040mm (00:45hh)",60=>"060mm (01:00hh)",90=>"090mm (01:30hh)",120=>"120mm (02:00hh)",180=>"180mm (03:00hh)",240=>"240mm (04:00hh)",300=>"300mm (05:00hh)",360=>">=6:00hh");
        foreach ($arrTiempos as $i=>$val){
            $arr[$i] = array('value'=>$i,'label'=>$val);
        }
        return $arr;
    }
}
