<?php
/**
 * Lille SMTP-klient.
 *
 * Webhotellet tillader ikke altid PHP's mail(), og så skal mailen afleveres
 * direkte hos udbyderens SMTP-server i stedet. Her er kun det, der skal bruges:
 * EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO og DATA.
 *
 * Adgangskoden ligger i includes/config.local.php, som holdes uden for git.
 */

/** Læser ét svar fra serveren. Flere linjer starter med "250-" og slutter "250 ". */
function smtp_read($socket): string
{
    $out = '';

    while (($line = fgets($socket, 1024)) !== false) {
        $out .= $line;
        // Sidste linje har et mellemrum på plads fire: "250 OK".
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    return $out;
}

/** Sender én kommando og kræver, at svaret starter med en af de ventede koder. */
function smtp_cmd($socket, ?string $command, array $expect, string &$error): bool
{
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }

    $reply = smtp_read($socket);
    $code = substr($reply, 0, 3);

    if (!in_array($code, $expect, true)) {
        // Adgangskoden må aldrig havne i en fejlbesked.
        $shown = $command !== null && str_starts_with($command, 'AUTH') ? 'AUTH' : ($command ?? 'forbindelse');
        $error = trim($shown . ' → ' . trim($reply));
        return false;
    }

    return true;
}

/**
 * Afleverer én mail hos SMTP-serveren.
 *
 * Returnerer ['ok' => bool, 'error' => string].
 */
function smtp_deliver(array $config, string $to, string $subject, string $body, string $headers): array
{
    $host = $config['smtp_host'];
    $port = (int)$config['smtp_port'];
    $error = '';

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT
    );

    if ($socket === false) {
        return ['ok' => false, 'error' => 'Kunne ikke få forbindelse til ' . $host . ':' . $port . ' (' . $errstr . ')'];
    }

    stream_set_timeout($socket, 20);

    $hostname = $config['smtp_helo'] ?? 'hesselbjergnord.dk';

    $steps = static function () use ($socket, $hostname, $config, $to, $subject, $body, $headers, &$error): bool {
        if (!smtp_cmd($socket, null, ['220'], $error)) return false;
        if (!smtp_cmd($socket, 'EHLO ' . $hostname, ['250'], $error)) return false;

        // STARTTLS: alt efter dette punkt er krypteret.
        if (!smtp_cmd($socket, 'STARTTLS', ['220'], $error)) return false;

        $crypto = @stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if ($crypto !== true) {
            $error = 'STARTTLS kunne ikke gennemføres.';
            return false;
        }

        // EHLO skal gentages, når forbindelsen er krypteret.
        if (!smtp_cmd($socket, 'EHLO ' . $hostname, ['250'], $error)) return false;

        if (!smtp_cmd($socket, 'AUTH LOGIN', ['334'], $error)) return false;
        if (!smtp_cmd($socket, base64_encode($config['smtp_user']), ['334'], $error)) return false;
        if (!smtp_cmd($socket, base64_encode($config['smtp_pass']), ['235'], $error)) return false;

        if (!smtp_cmd($socket, 'MAIL FROM:<' . $config['mail_from'] . '>', ['250'], $error)) return false;
        if (!smtp_cmd($socket, 'RCPT TO:<' . $to . '>', ['250', '251'], $error)) return false;
        if (!smtp_cmd($socket, 'DATA', ['354'], $error)) return false;

        // En linje med kun et punktum afslutter brevet, så et punktum først
        // på en linje i teksten skal fordobles.
        $data = $headers . "\r\n"
              . 'To: ' . $to . "\r\n"
              . 'Subject: ' . $subject . "\r\n"
              . "\r\n"
              . preg_replace('/^\./m', '..', $body);

        fwrite($socket, $data . "\r\n.\r\n");

        return smtp_cmd($socket, null, ['250'], $error);
    };

    $ok = $steps();

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return ['ok' => $ok, 'error' => $error];
}
