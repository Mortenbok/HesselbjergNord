<?php
/**
 * Afsendelse af SMS gennem SureSMS.
 *
 * Nøglen ligger i includes/config.local.php, som holdes uden for git og
 * uden for FTP-overførslen. Uden den er afsendelse slået fra, og modulet
 * siger det tydeligt i stedet for at fejle stille.
 *
 * SÅDAN HÆNGER DET SAMMEN
 *
 *   1. POST /oauth2/api/Account/Gettoken   bytter API-nøglen til et token
 *   2. POST /oauth2/api/Message/Send       sender ÉN besked med tokenet
 *
 * API-nøglen laves på https://app.suresms.com/UserApi/Index. Ved login
 * skrives den faste tekst "+apikey" som "telefonnummer" og selve nøglen
 * som adgangskode — det er SureSMS' egen opskrift, ikke et gæt.
 *
 * Der sendes ét kald pr. modtager. SureSMS kan tage flere ad gangen, men
 * så får man ét samlet svar, og vi kan ikke se, hvem der faldt fra. Ét
 * kald pr. modtager koster lidt mere tid og giver til gengæld et rigtigt
 * svar pr. person — og det er dét, køen i besked.php bygger på.
 *
 * API-beskrivelsen: https://api.suresms.com/oauth2/swagger/docs/v1
 */

const SMS_TOKEN_URL = 'https://api.suresms.com/oauth2/api/Account/Gettoken';
const SMS_SEND_URL = 'https://api.suresms.com/oauth2/api/Message/Send';
const SMS_TIMEOUT = 30;

/** Den faste tekst, SureSMS vil have som "telefonnummer", når man bruger en API-nøgle. */
const SMS_APIKEY_BRUGER = '+apikey';

/** Indstillinger fra config.local.php — tomme værdier, hvis filen mangler. */
function sms_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.local.php';
        $config = is_readable($file) ? (array)require $file : [];
        $config += [
            'suresms_apikey' => '',
            // Godkendt som Sender-ID hos SureSMS 16. 11. 2026. Sættes
            // der et navn, der IKKE er godkendt, tager SureSMS imod
            // beskeden og smider den væk bagefter med "Unauthorized
            // SenderID" — og det ses kun i udbakken. Tom streng
            // betyder, at beskeden kommer fra kontoens eget nummer.
            'sms_sender' => 'Hesselbjerg',
            // Kun nødvendig på maskiner uden en certifikatliste i PHP — fx
            // en arbejdscomputer. Står den tom, bruges systemets egen, og
            // det er det rigtige på serveren.
            'sms_ca_bundle' => '',
        ];
    }

    return $config;
}

/** Er modulet klar til at sende? */
function sms_enabled(): bool
{
    return sms_config()['suresms_apikey'] !== '';
}

/**
 * Gør et dansk nummer til 4512345678.
 * Returnerer null, hvis nummeret ikke kan bruges.
 */
function sms_msisdn(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';

    // 0045... skrives lige så ofte som +45...
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    // Otte cifre uden landekode er et dansk nummer.
    if (strlen($digits) === 8) {
        $digits = '45' . $digits;
    }

    // Kun danske numre: 45 + otte cifre.
    return preg_match('/^45\d{8}$/', $digits) ? $digits : null;
}

/**
 * Hvor lang er beskeden, og hvor mange SMS'er bliver det?
 *
 * GSM-alfabetet giver 160 tegn pr. SMS, og æøå er med i alfabetet. Tegn
 * uden for det tvinger hele beskeden over i UCS-2, hvor der kun er 70.
 */
function sms_length(string $text): array
{
    $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
         . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    $udvidet = "^{}\\[~]|€";

    $unicode = false;
    $units = 0;

    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tegn) {
        if (mb_strpos($gsm, $tegn) !== false) {
            $units++;
        } elseif (mb_strpos($udvidet, $tegn) !== false) {
            $units += 2;        // escape-tegn + selve tegnet
        } else {
            $unicode = true;
            break;
        }
    }

    if ($unicode) {
        // UCS-2 tæller i 16-bits enheder: tegn uden for det basale plan —
        // emoji, for eksempel — fylder to. mb_strlen ville tælle dem som ét
        // og dermed love færre SMS'er, end der bliver sendt.
        $units = strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) / 2;
        $pr = $units > 70 ? 67 : 70;
    } else {
        $pr = $units > 160 ? 153 : 160;
    }

    return [
        'units' => $units,
        'unicode' => $unicode,
        'parts' => $units === 0 ? 0 : (int)ceil($units / $pr),
    ];
}

/** Et JSON-kald til SureSMS. */
function sms_http(string $url, array $krop, string $token = ''): array
{
    $config = sms_config();
    $headere = ['Content-Type: application/json'];

    if ($token !== '') {
        $headere[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);

    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SMS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($krop, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headere,
    ];

    if ($config['sms_ca_bundle'] !== '' && is_readable($config['sms_ca_bundle'])) {
        $opt[CURLOPT_CAINFO] = $config['sms_ca_bundle'];
    }

    curl_setopt_array($ch, $opt);

    $svar = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fejl = curl_error($ch);
    curl_close($ch);

    if ($svar === false) {
        return ['ok' => false, 'data' => [], 'fejl' => 'Forbindelsen til SureSMS fejlede: ' . $fejl];
    }

    $data = json_decode((string)$svar, true);

    if (!is_array($data)) {
        return ['ok' => false, 'data' => [],
                'fejl' => 'SureSMS svarede HTTP ' . $status . ' med noget, der ikke er JSON.'];
    }

    // SureSMS svarer HTTP 200 med success=false, når noget går galt — så
    // statuskoden alene er ikke nok til at se, om det gik godt.
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'data' => $data,
                'fejl' => 'SureSMS svarede HTTP ' . $status . ': '
                    . ($data['message'] ?? $data['Message'] ?? '')];
    }

    return ['ok' => true, 'data' => $data, 'fejl' => ''];
}

/**
 * Bytter API-nøglen til et adgangstoken.
 *
 * Tokenet gemmes ikke: det bruges i den ene udsendelse og er væk igen, så
 * der ligger ingen hemmelighed i en fil eller en session bagefter.
 */
function sms_token(): array
{
    $config = sms_config();

    if (!sms_enabled()) {
        return ['token' => '', 'fejl' => 'Der er ikke lagt en SureSMS API-nøgle ind.'];
    }

    $svar = sms_http(SMS_TOKEN_URL, [
        'accountPhoneNumber' => SMS_APIKEY_BRUGER,
        'accountPassword' => $config['suresms_apikey'],
    ]);

    if (!$svar['ok']) {
        return ['token' => '', 'fejl' => $svar['fejl']];
    }

    $resultat = $svar['data']['result'] ?? [];

    if (empty($resultat['token'])) {
        $hvorfor = $resultat['statusMessage'] ?? ($svar['data']['message'] ?? 'ukendt årsag');

        return ['token' => '', 'fejl' => 'SureSMS afviste nøglen: ' . $hvorfor];
    }

    return ['token' => (string)$resultat['token'], 'fejl' => ''];
}

/**
 * Sender beskeden til hver af modtagerne.
 *
 * Returnerer ['ok' => antal sendt, 'failed' => [numre der fejlede],
 * 'error' => sidste fejltekst]. Samme form som mail_send(), så besked.php
 * kan behandle de to kanaler ens.
 */
function sms_send(array $msisdns, string $text): array
{
    $config = sms_config();

    if (!sms_enabled()) {
        return ['ok' => 0, 'failed' => $msisdns, 'error' => 'Der er ikke lagt en SureSMS API-nøgle ind.'];
    }
    if ($msisdns === []) {
        return ['ok' => 0, 'failed' => [], 'error' => 'Ingen modtagere.'];
    }
    if (trim($text) === '') {
        return ['ok' => 0, 'failed' => $msisdns, 'error' => 'Beskeden er tom.'];
    }

    $token = sms_token();

    if ($token['fejl'] !== '') {
        return ['ok' => 0, 'failed' => $msisdns, 'error' => $token['fejl']];
    }

    $ok = 0;
    $fejlede = [];
    $sidsteFejl = '';

    foreach ($msisdns as $nummer) {
        // Landekoden står for sig i SureSMS' felter.
        $landekode = substr($nummer, 0, 2);
        $lokalt = substr($nummer, 2);

        $krop = [
            'countrycode' => $landekode,
            'toPhonenumber' => $lokalt,
            'messageText' => $text,
            'messageID' => 'hbn-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
        ];

        // Et afsendernavn skal være godkendt under Sender-ID på kontoen.
        // Er det ikke, tager SureSMS imod beskeden og smider den væk
        // bagefter med "Unauthorized SenderID" — den fejl ses kun i
        // udbakken. Står sms_sender tom, bruges kontoens eget nummer, og
        // så kommer beskeden frem.
        if ($config['sms_sender'] !== '') {
            $krop['isSentFrom'] = $config['sms_sender'];
        }

        $svar = sms_http(SMS_SEND_URL, $krop, $token['token']);

        $gik = $svar['ok'] && !empty($svar['data']['success']);

        if ($gik) {
            $ok++;
            continue;
        }

        $fejlede[] = $nummer;
        $sidsteFejl = $svar['fejl'] !== ''
            ? $svar['fejl']
            : ($svar['data']['message'] ?? 'SureSMS afviste beskeden.');
    }

    return ['ok' => $ok, 'failed' => $fejlede, 'error' => $sidsteFejl];
}

/**
 * Henter oplysninger om kontoen bag nøglen.
 *
 * Bruges af tools/sms/proev-sms.php til at vise, at nøglen virker, uden
 * at sende noget.
 */
function sms_konto(): array
{
    $token = sms_token();

    if ($token['fejl'] !== '') {
        return ['ok' => false, 'data' => [], 'fejl' => $token['fejl']];
    }

    $config = sms_config();
    $ch = curl_init('https://api.suresms.com/oauth2/api/Account/Me');

    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SMS_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['token']],
    ];

    if ($config['sms_ca_bundle'] !== '' && is_readable($config['sms_ca_bundle'])) {
        $opt[CURLOPT_CAINFO] = $config['sms_ca_bundle'];
    }

    curl_setopt_array($ch, $opt);
    $svar = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$svar, true);

    if ($status !== 200 || !is_array($data)) {
        return ['ok' => false, 'data' => [], 'fejl' => 'Kontoopslaget svarede HTTP ' . $status . '.'];
    }

    return ['ok' => true, 'data' => $data['result'] ?? $data, 'fejl' => ''];
}

/**
 * Hvad skete der med de beskeder, der er sendt i dag?
 *
 * SureSMS tager imod en besked og svarer ja med det samme — også når den
 * bagefter bliver smidt væk, fx fordi afsendernavnet ikke er godkendt.
 * Den besked står KUN i udbakken. Derfor kan man ikke stole på svaret fra
 * selve afsendelsen alene.
 *
 * Returnerer ['ok' => bool, 'beskeder' => [...], 'fejl' => string].
 */
function sms_udbakke(string $fraDato = '', string $tilDato = ''): array
{
    $token = sms_token();

    if ($token['fejl'] !== '') {
        return ['ok' => false, 'beskeder' => [], 'fejl' => $token['fejl']];
    }

    $config = sms_config();

    // Datoerne skal være Y-m-d. Dagen i dag kan ligge uden for serverens
    // døgn, så der tages en dag i hver ende.
    $url = 'https://api.suresms.com/oauth2/api/Message/Outbox?' . http_build_query([
        'FromDate' => $fraDato !== '' ? $fraDato : date('Y-m-d', strtotime('-1 day')),
        'ToDate' => $tilDato !== '' ? $tilDato : date('Y-m-d', strtotime('+1 day')),
    ]);

    $ch = curl_init($url);

    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SMS_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['token']],
    ];

    if ($config['sms_ca_bundle'] !== '' && is_readable($config['sms_ca_bundle'])) {
        $opt[CURLOPT_CAINFO] = $config['sms_ca_bundle'];
    }

    curl_setopt_array($ch, $opt);
    $svar = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$svar, true);

    if ($status !== 200 || !is_array($data)) {
        return ['ok' => false, 'beskeder' => [], 'fejl' => 'Udbakken svarede HTTP ' . $status . '.'];
    }

    return ['ok' => true, 'beskeder' => (array)($data['result'] ?? []), 'fejl' => ''];
}
