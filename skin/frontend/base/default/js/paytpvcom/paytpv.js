var $jq = jQuery.noConflict();

$jq(document).ready(function() {
    $jq("body").on("click",".paytpv .open_conditions",function(){
       conditions();
    });


    $jq("body").on("click",".paytpv #subscribe",function(){
       checkSuscription();
    });

    $jq("body").on("click","#payment_form_paytpvcom #subscribe",function(){
       checkSuscriptionWS();
    });

    $jq("body").on("click","#payment_form_paytpvcom .open_conditions",function(){
       conditions();
    });

    checkSuscription();

});


function conditions() {
    $jq(".open_conditions").fancybox({
        href: $jq("#conditions_url").val(),
                autoSize:false,
                type : 'ajax',
                'width':parseInt($jq(window).width() * 0.7)
        });

     
}

function checkSuscription(){
   
    if ($jq("#subscribe").is(':checked')){
        $jq("#div_periodicity").show();
        $jq("#saved_cards").hide();
        $jq("#storingStep").hide();
        $jq(".paytpv_iframe").hide();
    }else{
        $jq("#div_periodicity").hide();
        $jq("#saved_cards").show();
        checkCard();
    }
}


function checkSuscriptionWS(){
   
    if ($jq("#subscribe").is(':checked')){
        $jq("#div_periodicity").show();
        $jq("#saved_cards").hide();
        $jq("#storingStep").hide();
        $jq('#payment_form_paytpvcom_cc').show();
    }else{
        $jq("#div_periodicity").hide();
        $jq("#saved_cards").show();
        checkCardWS($jq('#card'));
    }
}

function checkCard(){
    if ($jq("#card").val()=="0"){
        $jq("#storingStep").removeClass("hidden").show();
        $jq("#user_validation").hide();
    }else{
        $jq("#storingStep").hide();
        $jq("#user_validation").show();
    }
    $jq(".paytpv_iframe").hide();
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
    Element.observe('userpwd', 'click', function (e) {
        $('userpwd').stopObserving();
        return false;
    }, false);
});