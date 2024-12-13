<?php

//2023-05-18 Lisatud kirja avamisaeg KJ

require_once "client-conf.php";
require_once "WebLock.php";

//header("Content-Type: application/json; charset='utf-8'");
session_start();
require_once "config.php";

global $conn, $CLIENT;

function remove_prefix($text, $prefix) {
    if (0 === strpos($text, $prefix))
        $text = substr($text, strlen($prefix)) . '';
    return $text;
}

//$_REQUEST['regphone'] = 53945139;
//$_REQUEST['regphone'] = 53679407;
if(!isset($_REQUEST['regphone']))
    exit;
else
    $phoneNr = remove_prefix(remove_prefix(filter_var($_REQUEST['regphone'], FILTER_SANITIZE_NUMBER_INT), '372'), '+372');

$tokenCurl = curl_init();
curl_setopt_array($tokenCurl, [
    CURLOPT_URL => 'https://euapi.ttlock.com/oauth2/token',
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_POST => 1,
    CURLOPT_POSTFIELDS => "username=toidujagamiskapp@gmail.com&password=4f54e8545d668ac0107e62710dab6634&client_id=40302a7a319242f0a4590737b2f246a6&client_secret=6f6bbdc16e0a88ada2e36935494737a5",
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded'
    ]
]);
$response = curl_exec($tokenCurl);

if (curl_errno($tokenCurl)) {
    echo sprintf("Error: %s", curl_error($tokenCurl));

    curl_close($tokenCurl);
    exit;
}
curl_close($tokenCurl);

if (($result = json_decode($response, true)) === null) {
    echo $response;

    exit;
}

if (!isset($result['access_token'])) {
    echo "Missing access token";

    exit;
}
$token = $result['access_token'];


// Lisatud 07.04.2023 Apps Scriptist kasutaja andmete kirjutamine andmebaasi.
$sql = "insert into fudloop.reg (regfl,regname,reglastname,regmail,regphone,regusername,regtaker,regdonor,regcompany,regsmart,alias,psw,reglang) values ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13);";

$regfl = $_REQUEST["regfl"];
$regname = $_REQUEST["regname"];
$reglastname = $_REQUEST["reglastname"];

$regmail = $_REQUEST["regmail"];
if (!filter_var($regmail, FILTER_VALIDATE_EMAIL)) {
	die(json_encode(["Email not valid"]));
		 
}
								 

$regphone = $phoneNr;

										  

$regusername = "+372" . $regphone;
$regtaker = $_REQUEST["regtaker"];
$regdonor = $_REQUEST["regdonor"];
$regcompany = $_REQUEST["regcompany"];
$regsmart = $_REQUEST["regsmart"];
$alias = $_REQUEST["alias"];
$psw = $_REQUEST["psw"];
$reglang = $_REQUEST["reglang"];
											 
	  
		 

$res = pg_query_params($conn, $sql, [$regfl,$regname,$reglastname,$regmail,$regphone,$regusername,$regtaker,$regdonor,$regcompany,$regsmart,$alias,$psw,$reglang])   or die(json_encode([pg_last_error()]));
// Apps Scriptist kasutaja andmete kirjutamine andmebaasi. LÕPP. 



$res = pg_query_params($conn, "
    SELECT regname, reglastname, regphone, regfl, regmail, alias, vip
    FROM fudloop.reg 
    WHERE regphone = $1;
", [
    $phoneNr
]) or die(json_encode([pg_last_error()]));

$person = [];
if(($row = pg_fetch_assoc($res)) !== false) {
    $person['firstName'] = $row['regname'];
    $person['lastName'] = $row['reglastname'];
    $person['mobilePhone'] = $row['regphone'];
    $person['comments'] = $row['alias'];
    $person['email'] = $row['regmail'];
    $regfl = $row['regfl'];
    $vip = $row['vip'] == 'f' ? false : true;
} else
    exit;

//reg code
$res = pg_query_params($conn, "
    INSERT INTO fudloop.code_registrations(regmail, regphone, regfl, alias) VALUES ($1, $2, $3, $4)
        ON CONFLICT DO NOTHING RETURNING *;
", [
    $person['email'],
    $person['mobilePhone'],
    $regfl,
    $person['comments']
]) or die(json_encode([pg_last_error()]));

//update user
if(pg_num_rows($res) > 0) {
    while($row = pg_fetch_assoc($res)) {
        $keycode = $row["keycode"];
        pg_query_params($conn, "
            UPDATE fudloop.reg SET regsmart = FALSE, psw = $2 WHERE regphone = $1
        ", [
            $person['mobilePhone'],
            $keycode
        ]) or die(json_encode([pg_last_error()]));
    }
}

//code now exists, continue
$res = pg_query_params($conn, "
    SELECT * 
    FROM fudloop.code_registrations
    WHERE regphone = $1;
", [
    $person['mobilePhone']
]) or die(json_encode([pg_last_error()]));

$debug = true;
if(pg_num_rows($res) > 0) {
    if (($row = pg_fetch_assoc($res)) !== false) {
        $startTime = date('H') >= 9 ? strtotime('+2 seconds') : strtotime('today 9:00');


        $codeCurl = curl_init();
        curl_setopt_array($codeCurl, [
            CURLOPT_URL => 'https://euapi.ttlock.com/v3/keyboardPwd/add',
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => http_build_query([
                'clientId' => '40302a7a319242f0a4590737b2f246a6',
                'accessToken' => $token,
                'lockId' => '4518309',
                'keyboardPwd' => $row["keycode"],
                'keyboardPwdName' => $row["alias"],
                'startDate' => $startTime . "000",
                'endDate' => strtotime("+5 years") . "000",
                'addType' => 2,
                'date' => sprintf("%d000", time())
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $response = curl_exec($codeCurl);

        if (curl_errno($codeCurl)) {
            echo sprintf("Error: %s", curl_error($codeCurl));

            curl_close($codeCurl);
            exit;
        }
        curl_close($codeCurl);

        $start = new DateTime('now');
        $end = clone $start;
        $end->add(new DateInterval('P2M'));

        $weblock = WebLock::getInstance();
        $existing = $weblock->getUserByNameAndPhoneNr("{$person['firstName']} {$person['lastName']}", $person['mobilePhone']);
        if(!$existing) {
            $user = $weblock->addUser($person);
            $weblock->setGroup($user['id'], $vip ? $CLIENT['WebLock']['vip-group-id'] : $CLIENT['WebLock']['basic-group-id']);
            $weblock->addPin($user['id'], $row['keycode'], $end, $start);
        } else {
            $weblock->addPin($existing['id'], $row['keycode'], $end, $start);
        }

        mail($person['email'], "FudLoop toidujagamiskappide ligipääs uksekoodiga", "
            <html>
                <head>
                    <title></title>
                </head>
                <body>
                    <p>Tere!<br><br>
                    Tore, et soovid hakata kasutama FudLoopi toidujagamiskappe! Kapid asuvad Keskturul (Keldrimäe 9), Mustamäel (E. Vilde tee 71) ja Lasnamäel (Pallasti 54). Kapid on kasutajatele avatud iga päev 9.00-23.59.<br><br>
                    Sinu uksekood toidujagamiskapi kasutamiseks on:<br>
                    {$row['keycode']}#<br><br>
                    Kapiuksel on klahvistik, trüki sisse numbrid ja ukse avamiseks vali kindlasti ka märk #. Võib juhtuda, et avamiseks peab koodi mitu korda sisestama. Palun ära jaga oma uksekoodi kellegi teisega! Uksekood kustutatakse kui seda ei ole 1 kuu kasutatud.<br><br>
                    Toidujagamiskapi kasutajaks registreerudes nõustud kasutustingimustega (www.fudloop.ee/kasutustingimused). Pärast seda, kui oled esimest korda toidukapi ukse avanud, võid kappi kasutada viis päeva tasuta. Kui soovid kappi edasi kasutada, tuleks sul maksta kuutasu. Makselingi saadame sulle meiliaadressile. <br><br>
                    Meie toidujagamiskapi ja seal leiduva toidu kohta saad infot Facebooki grupist „Keskturu toidujagamiskapp“.<br><br>
                    Aitäh, et võitled toiduraiskamisega!<br><br>
                    FudLoop OÜ<br>
                    abi@fudloop.ee<br>
                    www.fudloop.ee<br><br>
                    ---<br><br>
                    Здравствуйте!<br><br>
                    Это отлично, что вы хотите начать пользоваться пункты раздачи еды FudLoop! Наш пункты - эти зеленые шкафы, которые находятся на Центральном рынке (Keldrimäe 9) Мустамяэ (E. Vilde tee 71). Шкафы раздачи еды (toidujagamiskapid) доступны к использованию с 9.00 до 23.59.<br><br>
                    Ваш код доступа для открытия пункта раздачи еды:<br>
                    {$row['keycode']}#<br><br>
                    На двери пункта раздачи еды есть клавиатура: наберите на ней код и не забудьте нажать #, чтобы открыть дверь. Пожалуйста, никому не сообщайте свой код доступа! Код будет удален, если он не использовался в течение 1 месяцев.<br><br>
                    Регистрируясь в качестве пользователя шкафом для раздачи еды (toidujagamiskapp) Fudloop, вы соглашаетесь с условиями использования (www.fudloop.ee/kasutustingimused). Вы можете бесплатно пользоваться шкафом раздачи еды (toidujagamiskapp) Fudloop в течении 5 дней после первого открытия шкафа. Если вы желаете и продолжить пользоваться шкафом раздачи еды (toidujagamiskapp) Fudloop, вы должны заплатить ежемесячную плату. Мы вышлем ссылку для оплаты на ваш адрес электронной почты. <br><br>
                    Инфо про пункт раздачи еды и продуктах в нем смотрите в Facebook-группе «Keskturu toidujagamiskapp».<br><br>
                    Спасибо, что помогаете беречь еду!<br><br>
                    FudLoop OÜ<br>
                    abi@fudloop.ee<br>
                    www.fudloop.ee
                    </p>
                </body>
            </html>
        ","MIME-Version: 1.0\r\nContent-type: text/html;charset=UTF-8\r\nBcc: info@fudloop.ee\r\nFrom: abi@fudloop.ee");

        echo $row['keycode'];
    }
}

