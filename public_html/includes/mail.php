<?php
/**
 * Udsendelse af mail til beboerne.
 *
 * Mailen afleveres hos udbyderens SMTP-server, når smtp_host er udfyldt i
 * includes/config.local.php. Er den tom, bruges PHP's mail() i stedet — men
 * mange webhoteller har mail() slået fra, og så kommer intet frem.
 *
 * VIGTIGT: hver modtager får sin egen mail. Beboerne må aldrig kunne se
 * hinandens adresser, og en fælles To- eller Cc-linje ville netop vise dem.
 */

require_once __DIR__ . '/sms.php';   // deler config.local.php
require_once __DIR__ . '/smtp.php';

/** Indstillinger med fornuftige standarder. */
function mail_config(): array
{
    return sms_config() + [
        'mail_from' => 'bestyrelsen@hesselbjergnord.dk',
        'mail_from_name' => 'Grundejerforeningen Hesselbjerg Nord',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_helo' => 'hesselbjergnord.dk',
    ];
}

/** Afsenderadresse og -navn. */
function mail_from(): array
{
    $c = mail_config();

    return [$c['mail_from'], $c['mail_from_name']];
}

/** Afleveres mailen gennem SMTP frem for PHP's mail()? */
function mail_uses_smtp(): bool
{
    $c = mail_config();

    return $c['smtp_host'] !== '' && $c['smtp_user'] !== '' && $c['smtp_pass'] !== '';
}

/** Brevhovedet, som er ens for alle modtagere. */
function mail_headers(): string
{
    [$from, $fromName] = mail_from();

    mb_internal_encoding('UTF-8');

    return implode("\r\n", [
        'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n") . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: hesselbjergnord.dk',
        'Auto-Submitted: auto-generated',
    ]);
}

/**
 * Sender én mail til hver modtager.
 *
 * Returnerer ['ok' => antal sendte, 'failed' => [adresser], 'error' => string].
 * En afvist adresse må ikke stoppe resten af udsendelsen.
 */
function mail_send(array $recipients, string $subject, string $body): array
{
    $config = mail_config();

    if ($recipients === []) {
        return ['ok' => 0, 'failed' => [], 'error' => 'Ingen modtagere.'];
    }
    if (trim($subject) === '') {
        return ['ok' => 0, 'failed' => [], 'error' => 'Emnefeltet er tomt.'];
    }
    if (trim($body) === '') {
        return ['ok' => 0, 'failed' => [], 'error' => 'Beskeden er tom.'];
    }

    mb_internal_encoding('UTF-8');
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $headers = mail_headers();
    $useSmtp = mail_uses_smtp();

    // Linjer i en mail må ikke være vilkårligt lange.
    $text = wordwrap(str_replace(["\r\n", "\r"], "\n", $body), 78, "\r\n");

    $ok = 0;
    $failed = [];
    $lastError = '';

    foreach ($recipients as $r) {
        $address = is_array($r) ? ($r['email'] ?? '') : (string)$r;

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $failed[] = $address;
            continue;
        }

        if ($useSmtp) {
            $result = smtp_deliver($config, $address, $encodedSubject, $text, $headers);
            if ($result['ok']) {
                $ok++;
            } else {
                $failed[] = $address;
                $lastError = $result['error'];
            }
        } elseif (@mail($address, $encodedSubject, $text, $headers, '-f' . $config['mail_from'])) {
            $ok++;
        } else {
            $failed[] = $address;
            $lastError = 'PHP mail() blev afvist af serveren.';
        }
    }

    return ['ok' => $ok, 'failed' => $failed, 'error' => $lastError];
}
