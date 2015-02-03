var $jq = jQuery.noConflict();

$jq(document).ready(function() {
    $jq("body").on("change","#payment_paytpvcom #payment_paytpvcom_terminales",function(){
       checkterminales();
    });


    $jq("body").on("click","#payment_paytpvcom #payment_paytpvcom_secure_first",function(){
       check3dfirst();
    });

});

function check3dfirst(){
    // Si solo tiene terminal seguro la primera compra va por seguro
    if($jq("#payment_paytpvcom_terminales").val() == 0 && $jq("#payment_paytpvcom_secure_first").val()==0){
        alert("Si solo dispone de un terminal seguro los pagos van siempre por seguro");
        $jq("#payment_paytpvcom_secure_first").val(1);
    }
    // Si solo tiene terminal No Seguro la primera compra va por seguro
    if($jq("#payment_paytpvcom_terminales").val() == 1 && $jq("#payment_paytpvcom_secure_first").val()==1){
        alert("Si solo tiene un terminal NO seguro los pagos van siempre por NO seguro");
        $jq("#payment_paytpvcom_secure_first").val(0);
    }
}


function checkterminales(){
    // Si solo tiene terminal seguro o tiene los dos la primera compra va por seguro
    // Seguro
    switch ($jq("#payment_paytpvcom_terminales").val()){
        case "0": // SEGURO
            $jq("#payment_paytpvcom_secure_first").val(1);
            break;
        case "1": // NO SEGURO
            $jq("#payment_paytpvcom_secure_first").val(0);
            break;
        case "2": // AMBOS
            //$jq("#payment_paytpvcom_secure_first").val(1);
            break;
    }
}