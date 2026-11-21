<?php
/**
 * Adgangskoder — hver person i kartoteket har sin egen kode, der giver login.
 *
 * Bestyrelsen sender koden fra admin.php til personens mail i
 * beboerkartoteket. At mailen står på personen, er beviset for, at
 * modtageren hører til adressen — derfor skal bestyrelsen ikke godkende
 * noget i hånden.
 *
 * Koden brænder ikke ved brug: et glemt kodeord klares ved at bruge linket
 * igen. Sender bestyrelsen en ny kode til en person, dør personens gamle,
 * og personens eget login lukkes; de andre på adressen røres ikke.
 *
 * Før de personlige koder havde hver husstand én fælles kode (tabellen
 * adgangskort), sendt til ejeren. De koder virker stadig, til ejeren får
 * sin egen.
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

    if ($kort) {
        return $kort;
    }

    // En personlig kode fra kartoteket. Den ligner et kort udadtil, men
    // id er 0, og mailen er personens egen, så formularen kan udfylde den.
    $stmt = $pdo->prepare('SELECT * FROM beboerkartotek WHERE kode = :kode LIMIT 1');
    $stmt->execute([':kode' => $kode]);
    $p = $stmt->fetch();

    if (!$p) {
        return null;
    }

    require_once __DIR__ . '/admin.php';

    // Adressen skrives som husstandens, så loginnet havner på det rigtige hus.
    $hus = admin_hus($pdo, (string)$p['sommerhusadresse']);

    return [
        'id' => 0,
        'beboer_id' => (int)$p['id'],
        'adresse' => $hus['adresse'] ?? admin_kanonisk_adresse((string)$p['sommerhusadresse']),
        'kode' => $kode,
        'spaerret' => 0,
        'navn' => trim((string)$p['kontaktperson']),
        'mail' => admin_person_mail($p)['mail'],
    ];
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
        // ind på admin.php og sætte fluebenet tilbage, og så ville
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
    // En personlig kode (id 0) har intet kort at tælle op på.
    if ($kortId <= 0) {
        return;
    }

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
 * Sender koden til én person på adressen. Returnerer true, hvis mailen gik af.
 *
 * Fra forsiden går den til ejeren (admin_ejer_mail), fra Ret-siden til den
 * person, der er åbnet (admin_person_mail) — se includes/admin.php.
 */
function adgang_send_kode(string $adresse, string $kode, string $mail, string $navn, string $link): bool
{
    $emne = 'Din adgang til hesselbjergnord.dk — ' . $adresse;
    $hilsen = $navn !== '' ? 'Hej ' . $navn : 'Hej';

    $tekst = $hilsen . "\n\n"
        . "Her er jeres adgang til foreningens hjemmeside. Den hører til "
        . $adresse . ".\n\n"
        . "Åbn dette link:\n"
        . $link . "\n\n"
        . "Eller gå ind på hesselbjergnord.dk/adgang.php og tast koden:\n"
        . "    " . adgang_kode_paent($kode) . "\n\n"
        . "Du vælger selv adgangskode undervejs. Bag login finder du "
        . "referater, regnskaber, vedtægter og medlemsbilleder.\n\n"
        . "Er I flere på adressen, kan I hver især oprette et login med den "
        . "samme kode. Koden virker, indtil bestyrelsen sender en ny — og så "
        . "lukkes de logins, der er oprettet på adressen. Havde du et login "
        . "i forvejen, skal du derfor oprette det igen med linket ovenfor.\n\n"
        . "Venlig hilsen\n"
        . "Bestyrelsen\n"
        . "Grundejerforeningen Hesselbjerg Nord";

    $svar = mail_send([$mail], $emne, $tekst);

    return ($svar['ok'] ?? 0) > 0;
}
