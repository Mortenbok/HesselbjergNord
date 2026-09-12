<?php
/**
 * Udsendelse af mail til beboerne.
 *
 * Mailen sendes med PHP's mail(), som webhotellet stiller til rådighed — der
 * skal altså ikke sættes noget op, før den virker.
 *
 * VIGTIGT: hver modtager får sin egen mail. Beboerne må aldrig kunne se
 * hinandens adresser, og en fælles To- eller Cc-linje ville netop vise dem.
 */

require_once __DIR__ . '/sms.php'; // deler config.local.php

/** Afsenderadresse og -navn fra config.local.php, med fornuftige standarder. */
function mail_from(): array
{
    $config = sms_config() + [
        'mail_from' => 'bestyrelsen@hesselbjergnord.dk',
        'mail_from_name' => 'Grundejerforeningen Hesselbjerg Nord',
    ];

    return [$config['mail_from'], $config['mail_from_name']];
}

/**
 * Sender én mail til hver modtager.
 *
 * Returnerer ['ok' => antal sendte, 'failed' => [adresser der fejlede]].
 * En enkelt afvist adresse må ikke stoppe resten af udsendelsen, så hver
 * modtager behandles for sig.
 */
function mail_send(array $recipients, string $subject, string $body): array
{
    [$from, $fromName] = mail_from();

    if ($recipients === []) {
        return ['ok' => 0, 'failed' => [], 'error' => 'Ingen modtagere.'];
    }
    if (trim($subject) === '') {
        return ['ok' => 0, 'failed' => [], 'error' => 'Emnefeltet er tomt.'];
    }
    if (trim($body) === '') {
        return ['ok' => 0, 'failed' => [], 'error' => 'Beskeden er tom.'];
    }

    // mb_encode_mimeheader klarer æ, ø og å i emnefeltet.
    mb_internal_encoding('UTF-8');
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    $encodedName = mb_encode_mimeheader($fromName, 'UTF-8', 'B', "\r\n");

    $headers = implode("\r\n", [
        'From: ' . $encodedName . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: hesselbjergnord.dk',
        // Beder automatiske svar om ikke at svare hele foreningen.
        'Auto-Submitted: auto-generated',
    ]);

    // Nogle webhoteller kræver, at afsenderen også står i envelope-adressen,
    // ellers ryger mailen i spamfilteret.
    $params = '-f' . $from;

    $ok = 0;
    $failed = [];

    foreach ($recipients as $r) {
        $address = is_array($r) ? ($r['email'] ?? '') : (string)$r;

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $failed[] = $address;
            continue;
        }

        // Linjer over 998 tegn er ikke tilladt i en mail; wordwrap sikrer det.
        $text = wordwrap(str_replace("\r\n", "\n", $body), 78, "\r\n");

        if (@mail($address, $encodedSubject, $text, $headers, $params)) {
            $ok++;
        } else {
            $failed[] = $address;
        }
    }

    return ['ok' => $ok, 'failed' => $failed, 'error' => ''];
}
