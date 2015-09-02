var $jq = jQuery.noConflict();

$jq(document).ready(function() {
    $jq("body").on("click",".paytpv .open_conditions",function(){
       conditions();
    });

    $jq("body").on("click","#payment_form_paytpvcom .open_conditions",function(){
       conditions();
    });

    
});


function conditions() {
    $jq(".open_conditions").fancybox({
        href: $jq("#conditions_url").val(),
                autoSize:false,
                type : 'ajax',
                'width':parseInt($jq(window).width() * 0.7)
        });
     
}

function checkCardWS(card){
    
    if ($jq(card).val()==0){
        $jq('#payment_form_paytpvcom_cc').show();
        $jq("#storingStep").show();
        $jq("#user_validation").hide();

    }else{
        $jq('#payment_form_paytpvcom_cc').hide();
        $jq("#storingStep").hide();
        $jq("#user_validation").show();
    }
}


document.observe("dom:loaded", function() {
    if($('userpwd') != undefined) {
   
        Element.observe('userpwd', 'click', function (e) {
            $('userpwd').stopObserving();
            return false;
        }, false);
    }
});