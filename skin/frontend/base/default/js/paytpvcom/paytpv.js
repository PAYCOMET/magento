var $jq = jQuery.noConflict();

$jq(document).ready(function() {
    $jq("body").on("click",".paytpv .open_conditions,#payment_form_paytpvcom .open_conditions,.paytpv_jet .open_conditions",function(){
       conditions();
    });


     $jq("body").on("click",".paytpv_jet #remember",function(){
        $jq.ajax({
            url: $jq("#actions_url").val(),
            type: "POST",
            data: {
                'action': 'saveTokenCard',
                'remember': $jq("#remember").is(':checked')?1:0,
                'order_id': $jq("#order_id").val(),
                'ajax': true
            }
        });
    });

});


function conditions(){
    $jq(".open_conditions").fancybox({
        href: $jq("#conditions_url").val(),
                autoSize:false,
                type : 'ajax',
                'width':parseInt($jq(window).width() * 0.7)
        });
     
}

function checkCardWS(card){
    if (!$jq(card).length || $jq(card).val()==0){

        $jq('#payment_form_paytpvcom_cc').show();
        $jq("#storingStep").show();
        $jq("#user_validation").hide();

    }else{
        $jq('#payment_form_paytpvcom_cc').hide();
        $jq("#storingStep").hide();
        $jq("#user_validation").show();
    }
}


function takingOff() {
    var x = new PAYTPV.Tokenizator();
    x.getToken(document.forms["paytpvPaymentForm"], boarding);
    return false;
};

function boarding(passenger) {
    boarding_error = 0;
    document.getElementById("paymentErrorMsg").innerHTML = "";
    if (passenger.errorID !== 0 || passenger.paytpvToken === "") {
        $jq(".paymentError").show();
        document.getElementById("paymentErrorMsg").innerHTML = passenger.errorText;
        boarding_error = 1;
    } else {
        
        var newInputField = document.createElement("input");

        newInputField.type = "hidden";
        newInputField.name = "paytpvToken";
        newInputField.value = passenger.paytpvToken;

        var paytpvPaymentForm = document.forms["paytpvPaymentForm"];
        paytpvPaymentForm.appendChild(newInputField);

        $jq("#paytpvToken").val(passenger.paytpvToken);
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