<?php
/**
 * Adgangskort — ét kort pr. husstand, med en QR-kode der giver login.
 *
 * Tanken: bestyrelsen deler et postkort ud til hver husstand. Kortet bærer
 * en kode, der hører til netop den adresse. At kortet er afleveret i
 * postkassen på Klitrosevej 18, er i sig selv beviset for, at den, der
 * samler det op, hører til Klitrosevej 18 — derfor skal bestyrelsen ikke
 * godkende noget i hånden.
 *
 * Koden brænder ikke ved brug. Et sommerhus har tit to ejere, og et login,
 * man bruger tre gange om året, bliver glemt. Kortet er derfor husstandens
 * blivende nøgle: scan igen for at oprette en bruger mere eller for at
 * sætte en ny adgangskode. Bliver et hus solgt, spærrer bestyrelsen kortet
 * og laver et nyt.
 */

require_once __DIR__ . '/adresser.php';
require_once __DIR__ . '/beboer.php';

/** Tegn, koden sættes sammen af. I, O, 0 og 1 er udeladt — de forveksles. */
const ADGANG_TEGN = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Antal tegn i en kode. 32^8 ≈ 1.100 milliarder — kan ikke gættes. */
const ADGANG_LAENGDE = 8;

/** Danner en ny, tilfældig kode. */
function adgang_ny_kode(): string
{
    $kode = '';

    for ($i = 0; $i < ADGANG_LAENGDE; $i++) {
        $kode .= ADGANG_TEGN[random_int(0, strlen(ADGANG_TEGN) - 1)];
    }

    return $kode;
}

/** Koden som den står på kortet: "K7M2-P9QX". Lettere at taste af. */
function adgang_kode_paent(string $kode): string
{
    return substr($kode, 0, 4) . '-' . substr($kode, 4);
}

/** Tager imod koden, som den måtte være tastet: med bindestreg, små bogstaver. */
function adgang_rens_kode(string $input): string
{
    $kode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');

    return strlen($kode) === ADGANG_LAENGDE ? $kode : '';
}

/** Slår et kort op på koden. Returnerer rækken, eller null. */
function adgang_find_kort(PDO $pdo, string $kode): ?array
{
    $kode = adgang_rens_kode($kode);

    if ($kode === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM adgangskort WHERE kode = :kode LIMIT 1');
    $stmt->execute([':kode' => $kode]);
    $kort = $stmt->fetch();

    return $kort ?: null;
}

/** Brugere, der allerede er oprettet på adressen. */
function adgang_brugere_paa_adresse(PDO $pdo, string $adresse): array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, display_name, is_active, created_at, last_login_at
           FROM users WHERE address = :adresse ORDER BY id'
    );
    $stmt->execute([':adresse' => $adresse]);

    return $stmt->fetchAll();
}

/**
 * Opretter en bruger ud fra et kort.
 *
 * Brugernavnet er mailadressen — den kan man huske, og den er i forvejen
 * unik pr. person. Returnerer ['ok' => bool, 'fejl' => string, 'id' => int].
 */
function adgang_opret_bruger(PDO $pdo, array $kort, string $navn, string $mail, string $kode): array
{
    $navn = trim($navn);
    $mail = mb_strtolower(trim($mail));

    if (mb_strlen($navn) < 2) {
        return ['ok' => false, 'fejl' => 'Skriv dit navn.', 'id' => 0];
    }

    if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || mb_strlen($mail) > 64) {
        return ['ok' => false, 'fejl' => 'Skriv en gyldig mailadresse.', 'id' => 0];
    }

    if (strlen($kode) < 8) {
        return ['ok' => false, 'fejl' => 'Adgangskoden skal være mindst 8 tegn.', 'id' => 0];
    }

    // Rollen kommer fra beboerkartoteket: står mailen på en række med
    // bestyrelsesflaget, bliver kontoen et bestyrelseslogin. Sådan slipper
    // bestyrelsen for at have to logins — ét fra kortet og ét oprettet i
    // hånden — og en ny bestyrelse får adgangen ved blot at sætte flueben i
    // kartoteket.
    $rolle = beboer_rolle_for_mail($pdo, $mail);

    $stmt = $pdo->prepare('SELECT id, address, role FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $mail]);
    $findes = $stmt->fetch();

    if ($findes) {
        // Hører den eksisterende bruger til samme adresse, er det samme
        // husstand, der scanner kortet igen — så sættes en ny adgangskode.
        // Ellers er mailen i brug af en anden husstand, og så skal der ikke
        // røres ved den konto.
        if ((string)$findes['address'] !== (string)$kort['adresse']) {
            return [
                'ok' => false,
                'fejl' => 'Mailadressen er allerede i brug på en anden adresse. '
                    . 'Brug en anden mail, eller skriv til bestyrelsen@hesselbjergnord.dk.',
                'id' => 0,
            ];
        }

        // Rollen følger kartoteket, også begge veje: er fluebenet taget væk
        // efter en generalforsamling, bliver kontoen menigt medlem igen.
        //
        // Men aldrig det sidste bestyrelseslogin. Røg det, kunne ingen komme
        // ind på beboerkartotek.php og sætte fluebenet tilbage, og så ville
        // der ikke være nogen vej ind igen uden adgang til databasen.
        $nyRolle = $findes['role'];

        if ($rolle !== null && $rolle !== $findes['role']) {
            $sidsteAdmin = $rolle === 'medlem'
                && $findes['role'] === 'bestyrelse'
                && beboer_antal_bestyrelse($pdo, (int)$findes['id']) === 0;

            if (!$sidsteAdmin) {
                $nyRolle = $rolle;
            }
        }

        $pdo->prepare(
            'UPDATE users SET password_hash = :h, display_name = :n, role = :r, is_active = 1
              WHERE id = :id'
        )->execute([
            ':h' => password_hash($kode, PASSWORD_DEFAULT),
            ':n' => $navn,
            ':r' => $nyRolle,
            ':id' => $findes['id'],
        ]);

        return [
            'ok' => true, 'fejl' => '', 'id' => (int)$findes['id'],
            'fornyet' => true, 'rolle' => $nyRolle,
        ];
    }

    $nyRolle = $rolle ?? 'medlem';

    $pdo->prepare(
        'INSERT INTO users (username, display_name, password_hash, role, address)
         VALUES (:u, :n, :h, :r, :a)'
    )->execute([
        ':u' => $mail,
        ':n' => $navn,
        ':h' => password_hash($kode, PASSWORD_DEFAULT),
        ':r' => $nyRolle,
        ':a' => $kort['adresse'],
    ]);

    return [
        'ok' => true, 'fejl' => '', 'id' => (int)$pdo->lastInsertId(),
        'fornyet' => false, 'rolle' => $nyRolle,
    ];
}

/** Noterer, at kortet er brugt. */
function adgang_noter_brug(PDO $pdo, int $kortId): void
{
    $pdo->prepare('UPDATE adgangskort SET sidst_brugt = NOW(), antal_brug = antal_brug + 1 WHERE id = :id')
        ->execute([':id' => $kortId]);
}

/** Logger brugeren ind med det samme efter oprettelsen. */
function adgang_log_ind(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT id, username, display_name, role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return;
    }

    auth_start_session();
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['role'] = $user['role'];

    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute([':id' => $userId]);
}

/** Adressen på kortet, uden at afsløre noget hvis koden er forkert. */
function adgang_e(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Mailadresserne i beboerkartoteket, samlet pr. husstand.
 *
 * Nøglen er adressen på foldet form ("klitrosevej18"), ikke teksten.
 * Kortene er lavet ud fra OpenStreetMap, der skriver Hylleskovvænget, mens
 * kartoteket skriver Hyldeskovvænget — en sammenligning tegn for tegn ville
 * tabe 17 husstande på gulvet. adresse_noegle_af_fritekst() kender begge
 * stavemåder og giver dem den samme nøgle.
 *
 * Både ejer og medejere kommer med: kortet hører til husstanden, ikke til
 * én person, og alle på adressen skal kunne komme ind.
 */
function adgang_mail_pr_husstand(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT sommerhusadresse, kontaktperson, mail, mail2
           FROM beboerkartotek
          ORDER BY forhold, id'
    )->fetchAll();

    $ud = [];

    foreach ($rows as $r) {
        $noegle = adresse_noegle_af_fritekst((string)$r['sommerhusadresse']);

        if ($noegle === '') {
            continue;
        }

        foreach (['mail', 'mail2'] as $felt) {
            $mail = mb_strtolower(trim((string)$r[$felt]));

            if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Den samme mail kan stå flere gange på en adresse.
            $ud[$noegle][$mail] = trim((string)$r['kontaktperson']);
        }
    }

    return $ud;
}

/** Modtagerne til ét kort. Tom liste, hvis husstanden ikke har en mail. */
function adgang_modtagere(array $mailKort, string $adresse): array
{
    return $mailKort[adresse_noegle_af_fritekst($adresse)] ?? [];
}

/**
 * Sender koden til én husstand.
 *
 * Returnerer ['ok' => antal sendte, 'fejlede' => [adresser]].
 */
function adgang_send_kode(array $kort, array $modtagere, string $link): array
{
    if ($modtagere === []) {
        return ['ok' => 0, 'fejlede' => []];
    }

    $emne = 'Din adgang til hesselbjergnord.dk — ' . $kort['adresse'];

    $ok = 0;
    $fejlede = [];

    // Hver modtager får sin egen mail med sit eget navn. De må ikke kunne
    // se hinandens adresser, og en fælles To-linje ville netop vise dem.
    foreach ($modtagere as $mail => $navn) {
        $hilsen = $navn !== '' ? 'Hej ' . $navn : 'Hej';

        $tekst = $hilsen . "\n\n"
            . "Her er jeres adgang til foreningens hjemmeside. Den hører til "
            . $kort['adresse'] . ".\n\n"
            . "Åbn dette link:\n"
            . $link . "\n\n"
            . "Eller gå ind på hesselbjergnord.dk/adgang.php og tast koden:\n"
            . "    " . adgang_kode_paent($kort['kode']) . "\n\n"
            . "Du vælger selv navn og adgangskode undervejs. Bag login finder du "
            . "referater, regnskaber, vedtægter og medlemsbilleder.\n\n"
            . "Gem denne mail. Koden bliver ved med at virke, så I kan begge "
            . "oprette hver jeres login, og har du glemt din adgangskode, "
            . "bruger du bare linket igen.\n\n"
            . "Venlig hilsen\n"
            . "Bestyrelsen\n"
            . "Grundejerforeningen Hesselbjerg Nord";

        $svar = mail_send([$mail], $emne, $tekst);

        if (($svar['ok'] ?? 0) > 0) {
            $ok++;
        } else {
            $fejlede[] = $mail;
        }
    }

    return ['ok' => $ok, 'fejlede' => $fejlede];
}
