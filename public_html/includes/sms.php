<?php
/**
 * Afsendelse af SMS gennem GatewayAPI.
 *
 * Nøglen ligger i includes/config.local.php, som holdes uden for git.
 * Uden nøgle er afsendelse slået fra, og modulet siger det tydeligt i stedet
 * for at fejle stille.
 *
 * GatewayAPI: POST https://gatewayapi.com/rest/mtsms
 * Godkendelse er HTTP Basic med tokenet som brugernavn og tom adgangskode.
 */

const SMS_ENDPOINT = 'https://gatewayapi.com/rest/mtsms';

/** Indstillinger fra config.local.php — tomme værdier, hvis filen mangler. */
function sms_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.local.php';
        $config = is_readable($file) ? (array)require $file : [];
        $config += ['gatewayapi_token' => '', 'sms_sender' => 'Hesselbjerg'];
    }

    return $config;
}

/** Er modulet klar til at sende? */
function sms_enabled(): bool
{
    return sms_config()['gatewayapi_token'] !== '';
}

/**
 * Gør et dansk nummer til det format, GatewayAPI vil have: 4512345678.
 * Returnerer null, hvis nummeret ikke kan bruges.
 */
function sms_msisdn(string $phone): ?string
{
    $digits = preg_replace('/\D/', '', $phone);

    // 0045... skrives lige så ofte som +45...
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 8) {
        $digits = '45' . $digits;
    }

    // Kun danske numre: 45 + otte cifre.
    return preg_match('/^45\d{8}$/', $digits) ? $digits : null;
}

/**
 * Tegn og antal SMS'er.
 *
 * Danske bogstaver findes i GSM-7, men æ, ø og å fylder hver ét tegn, mens
 * fx {} fylder to. Indeholder teksten noget uden for GSM-7, skifter beskeden
 * til UCS-2, og så er grænsen 70 tegn i stedet for 160.
 */
function sms_length(string $text): array
{
    $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
         . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    $extended = "^{}\[~]|€";

    $units = 0;
    $unicode = false;

    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
        if (mb_strpos($gsm, $char) !== false) {
            $units++;
        } elseif (mb_strpos($extended, $char) !== false) {
            $units += 2;
        } else {
            $unicode = true;
            break;
        }
    }

    if ($unicode) {
        // UCS-2 tæller 16-bit enheder, så tegn uden for BMP (fx emoji)
        // fylder to. mb_strlen ville tælle dem som ét.
        $units = strlen(mb_convert_encoding($text, "UTF-16BE", "UTF-8")) / 2;
        $single = 70;
        $multi = 67;
    } else {
        $single = 160;
        $multi = 153;
    }

    $parts = $units <= $single ? 1 : (int)ceil($units / $multi);

    return ['units' => $units, 'parts' => max(1, $parts), 'unicode' => $unicode];
}

/**
 * Sender én besked til en liste af numre.
 *
 * Returnerer ['ok' => bool, 'error' => string, 'ids' => array]. GatewayAPI
 * tager hele listen i ét kald, så beskeden enten går af sted til alle eller
 * ingen — det er med vilje, så en halvsendt udsendelse ikke kan opstå.
 */
function sms_send(array $msisdns, string $text): array
{
    $config = sms_config();

    if (!sms_enabled()) {
        return ['ok' => false, 'error' => 'Der er ikke sat en GatewayAPI-nøgle op.', 'ids' => []];
    }
    if ($msisdns === []) {
        return ['ok' => false, 'error' => 'Ingen modtagere.', 'ids' => []];
    }
    if (trim($text) === '') {
        return ['ok' => false, 'error' => 'Beskeden er tom.', 'ids' => []];
    }

    $payload = [
        'sender' => $config['sms_sender'],
        'message' => $text,
        'recipients' => array_values(array_map(
            static fn(string $m): array => ['msisdn' => (int)$m],
            $msisdns
        )),
    ];

    $ch = curl_init(SMS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $config['gatewayapi_token'] . ':',
        CURLOPT_TIMEOUT => 30,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Forbindelsen til GatewayAPI fejlede: ' . $curlError, 'ids' => []];
    }

    $data = json_decode((string)$body, true);

    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'error' => '', 'ids' => $data['ids'] ?? []];
    }

    $message = is_array($data) ? ($data['message'] ?? $data['code'] ?? '') : '';

    return [
        'ok' => false,
        'error' => 'GatewayAPI svarede ' . $status . ($message !== '' ? ': ' . $message : ''),
        'ids' => [],
    ];
}
