<?php
/**
 * Afsendelse af SMS gennem SureSMS.
 *
 * Nøglerne ligger i includes/config.local.php, som holdes uden for git og
 * uden for FTP-overførslen. Uden dem er afsendelse slået fra, og modulet
 * siger det tydeligt i stedet for at fejle stille.
 *
 * SÅDAN HÆNGER DET SAMMEN
 *
 *   1. POST /json/token          henter et token med Client Id og Secret Id
 *   2. POST /json/api/Message    sender beskeden med tokenet i en Bearer-header
 *
 * SureSMS kalder det en "password grant": brugernavnet er Client Id'et, og
 * adgangskoden er Secret Id'et. Tokenet holder et døgn, men vi henter et
 * nyt for hver udsendelse — det er ét ekstra kald pr. hold på 25, og så
 * skal der ingen hemmeligheder gemmes på disken.
 *
 * Flere modtagere sendes i ÉT kald: numrene adskilles med semikolon. Derfor
 * er svaret enten ja eller nej for hele holdet, og det er sådan,
 * besked.php mærker modtagerne.
 *
 * API-beskrivelsen: https://apiv2.suresms.com/json/swagger/
 */

/** Tjenesten. basePath er /json — det er med i adresserne herunder. */
const SMS_TOKEN_URL = 'https://apiv2.suresms.com/json/token';
const SMS_SEND_URL = 'https://apiv2.suresms.com/json/api/Message';
const SMS_PRIS_URL = 'https://apiv2.suresms.com/json/api/Price/pris';
const SMS_TIMEOUT = 30;

/** Indstillinger fra config.local.php — tomme værdier, hvis filen mangler. */
function sms_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.local.php';
        $config = is_readable($file) ? (array)require $file : [];
        $config += [
            'suresms_client_id' => '',
            'suresms_secret_id' => '',
            'sms_sender' => 'Hesselbjerg',
            // Kun nødvendig på maskiner bag en TLS-inspicerende proxy, hvor
            // PHP's egen certifikatliste ikke kender proxyens rod. Står den
            // tom, bruges systemets egen liste — det rigtige på serveren.
            'sms_ca_bundle' => '',
        ];
    }

    return $config;
}

/** Er modulet klar til at sende? */
function sms_enabled(): bool
{
    $c = sms_config();

    return $c['suresms_client_id'] !== '' && $c['suresms_secret_id'] !== '';
}

/**
 * Gør et dansk nummer til det format, SureSMS vil have: 4512345678.
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

    if (strlen($digits) !== 10 || !str_starts_with($digits, '45')) {
        return null;
    }

    return $digits;
}

/**
 * Hvor lang er beskeden, og hvor mange SMS'er bliver det?
 *
 * GSM-alfabetet giver 160 tegn pr. SMS, men æøå tæller som ét tegn dér.
 * Tegn uden for alfabetet tvinger hele beskeden over i UCS-2, hvor der
 * kun er plads til 70 tegn.
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

/**
 * Et kald til SureSMS. Returnerer ['status' => int, 'krop' => string,
 * 'fejl' => string].
 */
function sms_http(string $url, array $ekstra): array
{
    $config = sms_config();

    $ch = curl_init($url);

    curl_setopt_array($ch, $ekstra + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => SMS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($config['sms_ca_bundle'] !== '' && is_readable($config['sms_ca_bundle'])) {
        curl_setopt($ch, CURLOPT_CAINFO, $config['sms_ca_bundle']);
    }

    $krop = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fejl = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $status,
        'krop' => $krop === false ? '' : (string)$krop,
        'fejl' => $krop === false ? $fejl : '',
    ];
}

/**
 * Henter et adgangstoken. Returnerer ['token' => string, 'fejl' => string].
 *
 * Tokenet gemmes ikke: det holder kun i det ene kald, og så ligger der
 * ingen hemmelighed i en fil eller en session bagefter.
 */
function sms_token(): array
{
    $config = sms_config();

    if (!sms_enabled()) {
        return ['token' => '', 'fejl' => 'Der er ikke sat et SureSMS-login op.'];
    }

    $svar = sms_http(SMS_TOKEN_URL, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'password',
            'username' => $config['suresms_client_id'],
            'password' => $config['suresms_secret_id'],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    if ($svar['fejl'] !== '') {
        return ['token' => '', 'fejl' => 'Forbindelsen til SureSMS fejlede: ' . $svar['fejl']];
    }

    $data = json_decode($svar['krop'], true);

    if ($svar['status'] !== 200 || empty($data['access_token'])) {
        // SureSMS svarer med error_description, når login er forkert.
        $hvorfor = $data['error_description'] ?? ($data['error'] ?? ('HTTP ' . $svar['status']));

        return ['token' => '', 'fejl' => 'SureSMS afviste loginnet: ' . $hvorfor];
    }

    return ['token' => (string)$data['access_token'], 'fejl' => ''];
}

/**
 * Sender beskeden til alle numrene i ét kald.
 *
 * Returnerer ['ok' => bool, 'error' => string, 'ids' => array]. Svaret
 * gælder hele holdet: SureSMS tager numrene som én besked.
 */
function sms_send(array $msisdns, string $text): array
{
    $config = sms_config();

    if (!sms_enabled()) {
        return ['ok' => false, 'error' => 'Der er ikke sat et SureSMS-login op.', 'ids' => []];
    }
    if ($msisdns === []) {
        return ['ok' => false, 'error' => 'Ingen modtagere.', 'ids' => []];
    }
    if (trim($text) === '') {
        return ['ok' => false, 'error' => 'Beskeden er tom.', 'ids' => []];
    }

    $token = sms_token();

    if ($token['fejl'] !== '') {
        return ['ok' => false, 'error' => $token['fejl'], 'ids' => []];
    }

    // MessageID skal være entydigt og højst 50 tegn. Det bruges til at slå
    // beskeden op bagefter på /api/MessageStatus/{id}.
    $messageId = 'hbn-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));

    $svar = sms_http(SMS_SEND_URL, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'MessageID' => $messageId,
            'MessageText' => $text,
            'ToPhoneNumbers' => implode(';', $msisdns),
            'SenderID' => $config['sms_sender'],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token['token'],
        ],
    ]);

    if ($svar['fejl'] !== '') {
        return ['ok' => false, 'error' => 'Forbindelsen til SureSMS fejlede: ' . $svar['fejl'], 'ids' => []];
    }

    if ($svar['status'] >= 200 && $svar['status'] < 300) {
        return ['ok' => true, 'error' => '', 'ids' => [$messageId]];
    }

    $data = json_decode($svar['krop'], true);
    $hvorfor = $data['Message'] ?? ($data['message'] ?? mb_substr($svar['krop'], 0, 200));

    return [
        'ok' => false,
        'error' => 'SureSMS svarede HTTP ' . $svar['status'] . ': ' . $hvorfor,
        'ids' => [],
    ];
}

/**
 * Spørger, hvad en udsendelse ville koste — uden at sende den.
 *
 * Bruges til at prøve opsætningen af, før der bliver sendt noget rigtigt.
 * Returnerer ['ok' => bool, 'pris' => float, 'valuta' => string,
 * 'fejl' => string].
 */
function sms_pris(array $msisdns, string $text): array
{
    $config = sms_config();
    $token = sms_token();

    if ($token['fejl'] !== '') {
        return ['ok' => false, 'pris' => 0.0, 'valuta' => '', 'fejl' => $token['fejl']];
    }

    $url = SMS_PRIS_URL . '?' . http_build_query([
        'messageID' => 'hbn-pris-' . bin2hex(random_bytes(4)),
        'messageText' => $text,
        'toPhoneNumbers' => implode(';', $msisdns),
        'senderID' => $config['sms_sender'],
    ]);

    $svar = sms_http($url, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['token']],
    ]);

    if ($svar['fejl'] !== '') {
        return ['ok' => false, 'pris' => 0.0, 'valuta' => '',
                'fejl' => 'Forbindelsen til SureSMS fejlede: ' . $svar['fejl']];
    }

    $data = json_decode($svar['krop'], true);

    if ($svar['status'] < 200 || $svar['status'] >= 300 || !is_array($data)) {
        $hvorfor = $data['Message'] ?? mb_substr($svar['krop'], 0, 200);

        return ['ok' => false, 'pris' => 0.0, 'valuta' => '',
                'fejl' => 'SureSMS svarede HTTP ' . $svar['status'] . ': ' . $hvorfor];
    }

    return [
        'ok' => true,
        'pris' => (float)($data['PriceMember'] ?? 0),
        'valuta' => (string)($data['Currency'] ?? ''),
        'fejl' => '',
    ];
}
