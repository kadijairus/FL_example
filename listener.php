<?php
header("Content-Type: application/json; charset='utf-8'");

require_once "configwithfunctions.php";
require_once "WebLock.php";

global $conn, $CLIENT;

$res = pg_query($conn, "
    create table if not exists fudloop.payments (
        oid serial primary key,
        ts timestamptz default now(),
        account_name	varchar, 
        order_reference	varchar,
        email	varchar,
        customer_ip	varchar,
        customer_url	varchar,
        payment_created_at	timestamptz,
        initial_amount	numeric,
        standing_amount	numeric,
        payment_reference	varchar,
        payment_link	varchar,
        api_username	varchar,
        stan	varchar,
        fraud_score	varchar,
        payment_state	varchar,
        payment_method	varchar,
        ob_details__debtor_iban	varchar,
        ob_details__creditor_iban	varchar,
        ob_details__ob_payment_reference	varchar,
        ob_details__ob_payment_state	varchar,
        transaction_time	timestamptz,
        linkpay_customer_data__customer_name	varchar,
        acquiring_completed_at	timestamptz
    );
");
$res = pg_query($conn, "create index fudloop_payments_payment_id where not exists on fudloop.payments(payment_id);");
if (isset($_GET["vaata"])) {
    $res = pg_query($conn, "select * from fudloop.listenlog order by ts desc limit 10");
    $out = [];

    while ($row = pg_fetch_assoc($res)) {
        $out[] = $row;
    }

    die(json_encode($out));
} else {
    $poststring = file_get_contents('php://input');
    $getstring = $_SERVER["QUERY_STRING"];
    $payment = "";
    if (isset($_GET["payment_reference"])) {
        $auth = base64_encode("4a69384b7abaf5c4:45bbcef0b617ca2e6b6a3761b85a5f90");
// testkonto	$auth = base64_encode("8aeaf4b021c771ef:02a8656b8137cf3682a665878dfcf4a6");
        $context = stream_context_create([
            "http" => [
                "header" => "Authorization: Basic $auth"
            ]
        ]);
        $payment_reference = $_GET["payment_reference"];
        $url = "https://pay.every-pay.eu/api/v4/payments/" . $payment_reference . "?api_username=4a69384b7abaf5c4";
// testkonto $url = "https://igw-demo.every-pay.com/api/v3/payments/".$payment_reference."?api_username=8aeaf4b021c771ef";
        $payment = file_get_contents($url, false, $context);
    }

//Json lahti 23.07
    $paymentjson = json_decode($payment, true);
    $params = [$_SERVER["REMOTE_ADDR"], $getstring, $poststring, $payment, $paymentjson["standing_amount"], $paymentjson["payment_state"], $paymentjson["warnings"], $paymentjson["order_reference"]];
    $sql = "insert into fudloop.payments (account_name,order_reference,email,customer_ip,customer_url,payment_created_at,initial_amount,standing_amount,payment_reference,payment_link,api_username,stan,fraud_score,payment_state,payment_method,
ob_details__debtor_iban,ob_details__creditor_iban,
ob_details__ob_payment_reference,ob_details__ob_payment_state
,transaction_time,linkpay_customer_data__customer_name,acquiring_completed_at) values ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22)";
    $paymentjson_params = [];
    $paymentjson_params[] = $paymentjson["account_name"];
    $paymentjson_params[] = $paymentjson["order_reference"];
    $paymentjson_params[] = $paymentjson["email"];
    $paymentjson_params[] = $paymentjson["customer_ip"];
    $paymentjson_params[] = $paymentjson["customer_url"];
    $paymentjson_params[] = $paymentjson["payment_created_at"];
    $paymentjson_params[] = $paymentjson["initial_amount"];
    $paymentjson_params[] = $paymentjson["standing_amount"];
    $paymentjson_params[] = $paymentjson["payment_reference"];
    $paymentjson_params[] = $paymentjson["payment_link"];
    $paymentjson_params[] = $paymentjson["api_username"];
    $paymentjson_params[] = $paymentjson["stan"];
    $paymentjson_params[] = $paymentjson["fraud_score"];
    $paymentjson_params[] = $paymentjson["payment_state"];
    $paymentjson_params[] = $paymentjson["payment_method"];
    $paymentjson_params[] = $paymentjson["ob_details"]["debtor_iban"];
    $paymentjson_params[] = $paymentjson["ob_details"]["creditor_iban"];
    $paymentjson_params[] = $paymentjson["ob_details"]["ob_payment_reference"];
    $paymentjson_params[] = $paymentjson["ob_details"]["ob_payment_state"];
    $paymentjson_params[] = $paymentjson["transaction_time"];
    $paymentjson_params[] = $paymentjson["linkpay_customer_data"]["customer_name"];
    $paymentjson_params[] = $paymentjson["acquiring_completed_at"];

    $result = pg_query_params($conn, $sql, $paymentjson_params) or die(json_encode([pg_last_error(), $paymentjson_params]));
    $enddate = "None";
//kasutajate tabeli täiendus 23.07.
    if (isset($paymentjson["order_reference"])) {
        if ($paymentjson["standing_amount"] == 10) {
            $sql = "UPDATE fudloop.reg SET enddate = greatest(enddate,now()) + interval '1 months' WHERE regphone = $1 and not exists (select 1 from fudloop.payments where payment_reference=$2 and acquiring_completed_at is not null offset 1)  and exists (select 1 from fudloop.payments where payment_reference=$2 and acquiring_completed_at is not null) returning to_char(enddate,'dd.mm.yyyy'),regmail, regsmart";
            $res = pg_query_params($conn, $sql, [$paymentjson["order_reference"], $paymentjson["payment_reference"]]);
            if (pg_num_rows($res) > 0) {
                $enddate = pg_fetch_result($res, 0, 0);
                $regmail = pg_fetch_result($res, 0, 1);
                $regsmart = pg_fetch_result($res, 0, 2);
            }
        }
    }
    if ($enddate != "None") {
        $message = "
			<html>
			<head>
			<title></title>
			</head>
			<body>
			<p>Tere<br><br>Aitäh, et otsustasid FudLoopi toidujagamiskappi edasi kasutada! Sinu kuutasu makse on laekunud ja kasutajatunnus kehtib kuni " . $enddate .
            ".<br><br>Aitäh, et aitad meil toitu päästa!<br> 
			FudLoop OÜ</p>
			---<br><br>
			<p>Здравствуйте!<br><br>Благодарим вас за то, что продолжаете использовать шкаф для раздачи еды (toidujagamiskapp) Fudloop! Ваш ежемесячный платеж получен, и идентификатор пользователя действителен до " . $enddate .
            ".<br><br>Спасибо, что помогаете нам спасать еду!<br> 
			FudLoop OÜ</p>
			</body>
			</html>
			";
        $to = $regmail;
        //		$to = "kadijairus@gmail.com";
        $subject = "FudLoopi toidujagamiskapi kasutajatunnus kehtib kuni " . $enddate;
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        //		$headers .= "Bcc: kadijairus@gmail.com" . "\r\n";
        $headers .= "From: abi@fudloop.ee";
        mail($to, $subject, $message, $headers);
//sulata eKey ja lisa uksekood kui ei ole. Lisatud 11.10.2022.
        $token = askApiToken();
        $regphone = $paymentjson["order_reference"];
        $out = [];
        if ($regsmart == 'f') {
            $sql = "SELECT * from fudloop.reg where regphone = $1 and psw not in (SELECT keyboardpwd from fudloop.passcodes);";
            $res = pg_query_params($conn, $sql, [$regphone]);
            if (pg_num_rows($res) > 0) {
                $out[] = 'uksekood taastatud ';
                $out[] = addCode($regphone, $token);
                $out[] = updatePasscodes($token);
            }
        }
//nüüd regsmart = 'f' kõigil kasutajatel - maha võetud if ($regsmart == 't' and (date('H') >= 9)) {
        if (date('H') >= 9) {
            $out[] = 'eKey sulatatud ';
            $out[] = unFreezeKey($regphone, $token);
        } else {
        }

        $start = new DateTime('now', new DateTimeZone("UTC"));
        $end = clone $start;
        $end->add(new DateInterval('P2M'));

        $res = pg_query_params($conn, "
            SELECT regname, reglastname, regphone, alias, regmail, regfl, vip, psw
            FROM fudloop.reg
            WHERE regphone = $1
        ", [$regphone]);

        $person = [];
        $vip = false;
        if(($row = pg_fetch_assoc($res)) !== false) {
            $person['firstName'] = $row['regname'];
            $person['lastName'] = $row['reglastname'];
            $person['mobilePhone'] = $row['regphone'];
            $person['comments'] = $row['alias'];
            $person['email'] = $row['regmail'];
            $regfl = $row['regfl'];
            $vip = $row['vip'] == 'f' ? false : true;
        }

        $weblock = WebLock::getInstance();
        $existing = $weblock->getUserByNameAndPhoneNr("{$person['firstName']} {$person['lastName']}", $person['mobilePhone']);
        if(!$existing) {
            $user = $weblock->addUser($person);
            $weblock->setGroup($user['id'], $vip ? $CLIENT['WebLock']['vip-group-id'] : $CLIENT['WebLock']['basic-group-id']);
            $weblock->addPin($user['id'], $row['psw'], $end, $start);
        } else {
            $weblock->updatePinExpiry((int)$existing['id'], (int)$existing['cards'][0]['id'], $end);
        }
    }
}
die();