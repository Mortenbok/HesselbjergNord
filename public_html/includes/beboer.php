<?php
/**
 * Beboerkartoteket — foreningens register over, hvem der bor hvor.
 *
 * Kartoteket fyldes to steder fra:
 *   1. Bestyrelsen lægger regnearket op én gang på beboer-import.php.
 *   2. Derefter skriver ny-beboer.php nye beboere direkte herind.
 *
 * Når en ny beboer har udfyldt formularen, får vedkommende en mail med et
 * link. Linket fører til beboer-oplysninger.php, hvor resten af oplysningerne
 * udfyldes — hjemmeadresse, postnummer og by. Det er derfor kun det
 * allermest nødvendige, der skal skrives på selve tilmeldingssiden.
 *
 * INDEHOLDER PERSONOPLYSNINGER. Tabellen må kun ses af bestyrelsen, og
 * regnearket må ikke lægges i git — depotet er offentligt.
 */

require_once __DIR__ . '/adresser.php';

/** Hvor længe et link fra bekræftelsesmailen virker. */
const BEBOER_TOKEN_DAGE = 30;

/** Kun bestyrelsen må se og rette i kartoteket. */
function beboer_can_manage(?array $user): bool
{
    return is_array($user) && ($user['role'] ?? '') === 'bestyrelse';
}

/** Kort for htmlspecialchars. */
function beboer_e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Et nyt, uglemmeligt token til bekræftelseslinket. */
function beboer_nyt_token(): string
{
    return bin2hex(random_bytes(16));
}

/** Slår en beboer op på token fra mailen. */
function beboer_find_token(PDO $pdo, string $token): ?array
{
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?? '';

    if (strlen($token) !== 32) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM beboerkartotek
          WHERE token = :t AND token_udloeb > NOW()
          LIMIT 1'
    );
    $stmt->execute([':t' => $token]);
    $r = $stmt->fetch();

    return $r ?: null;
}

/**
 * Opretter en ny beboer fra tilmeldingsformularen.
 *
 * Returnerer ['ok' => bool, 'fejl' => string, 'id' => int, 'token' => string].
 */
function beboer_opret(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO beboerkartotek
            (type, sommerhusadresse, vej, nr, kontaktperson, mail, telefon,
             flyttet_ind, noter, kilde, status, token, token_udloeb, created_at)
         VALUES
            (:type, :adresse, :vej, :nr, :navn, :mail, :telefon,
             :flyttet_ind, :noter, :kilde, :status, :token, :udloeb, NOW())'
    );

    $token = beboer_nyt_token();

    $stmt->execute([
        ':type' => 'ejer',
        ':adresse' => $data['adresse'],
        ':vej' => $data['vej'],
        ':nr' => $data['nr'],
        ':navn' => $data['navn'],
        ':mail' => mb_strtolower($data['mail']),
        ':telefon' => $data['telefon'],
        ':flyttet_ind' => $data['flyttet_ind'] !== '' ? $data['flyttet_ind'] : null,
        ':noter' => $data['noter'],
        ':kilde' => 'ny-beboer',
        ':status' => 'afventer_oplysninger',
        ':token' => $token,
        ':udloeb' => date('Y-m-d H:i:s', strtotime('+' . BEBOER_TOKEN_DAGE . ' days')),
    ]);

    return ['ok' => true, 'fejl' => '', 'id' => (int)$pdo->lastInsertId(), 'token' => $token];
}

/** Gemmer de oplysninger, beboeren selv udfylder bagefter. */
function beboer_gem_oplysninger(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        'UPDATE beboerkartotek
            SET hjemmeadresse = :hjem, postnr = :postnr, `by` = :by, land = :land,
                mail2 = :mail2, telefon2 = :telefon2,
                status = :status, bekraeftet_at = NOW(), token = NULL, token_udloeb = NULL
          WHERE id = :id'
    );

    $stmt->execute([
        ':hjem' => $data['hjemmeadresse'],
        ':postnr' => $data['postnr'],
        ':by' => $data['by'],
        ':land' => $data['land'],
        ':mail2' => mb_strtolower($data['mail2']),
        ':telefon2' => $data['telefon2'],
        ':status' => 'aktiv',
        ':id' => $id,
    ]);
}

/** Adressen på det link, beboeren får i mailen. */
function beboer_link(string $token): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'hesselbjergnord.dk');
    $base .= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    return $base . '/beboer-oplysninger.php?t=' . $token;
}

/**
 * Sender bekræftelsesmailen.
 *
 * Returnerer true, hvis mailen kom af sted. Slår den fejl, må tilmeldingen
 * IKKE gå tabt — beboeren får linket på skærmen i stedet.
 */
function beboer_send_bekraeftelse(string $navn, string $mail, string $adresse, string $token): bool
{
    $link = beboer_link($token);

    $emne = 'Velkommen til Grundejerforeningen Hesselbjerg Nord';

    $tekst = "Hej " . $navn . "\n\n"
        . "Tak for din tilmelding. Vi har noteret dig på " . $adresse . ".\n\n"
        . "Der mangler kun ét skridt: vi skal bruge din hjemmeadresse, så vi kan "
        . "sende indkaldelsen til generalforsamlingen og opkrævningen af kontingent "
        . "det rigtige sted hen.\n\n"
        . "Udfyld resten her:\n"
        . $link . "\n\n"
        . "Linket virker i " . BEBOER_TOKEN_DAGE . " dage. Har du spørgsmål, så svar "
        . "blot på denne mail.\n\n"
        . "Venlig hilsen\n"
        . "Bestyrelsen\n"
        . "Grundejerforeningen Hesselbjerg Nord";

    $svar = mail_send([$mail], $emne, $tekst);

    return ($svar['ok'] ?? 0) > 0;
}
