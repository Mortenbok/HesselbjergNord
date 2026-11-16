<?php
/**
 * Databaseforbindelse + automatisk oprettelse af tabeller.
 *
 * Skemaet vedligeholdes i ../database/schema.sql. Tabellerne oprettes også
 * herfra, så siden virker på et frisk hostingmiljø uden manuel import.
 */

$host = 'mysql37.unoeuro.com';
$port = 3306;
$dbname = 'hesselbjergnord_dk_db';
$username = 'hesselbjergnord_dk';
$password = 'BGdk93ympnrfhDzRExAw';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4',
        $username,
        $password,
        $options
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('bestyrelse', 'medlem') NOT NULL DEFAULT 'medlem',
            address VARCHAR(255) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_photos (
            id INT NOT NULL AUTO_INCREMENT,
            user_id INT NULL DEFAULT NULL,
            member_name VARCHAR(255) NOT NULL DEFAULT 'Medlem',
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_member_photos_file (file_name),
            KEY idx_member_photos_user (user_id),
            KEY idx_member_photos_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS documents (
            id INT NOT NULL AUTO_INCREMENT,
            category ENUM('dagsorden', 'referat', 'regnskab') NOT NULL,
            doc_year SMALLINT NOT NULL,
            title VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(128) NOT NULL DEFAULT 'application/pdf',
            file_size INT NOT NULL DEFAULT 0,
            uploaded_by INT NULL DEFAULT NULL,
            uploader_name VARCHAR(255) NOT NULL DEFAULT 'Bestyrelsen',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_documents_file (file_name),
            KEY idx_documents_category (category, doc_year),
            KEY idx_documents_user (uploaded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");


    // Arkivet havde før to mapper (generalforsamling, regnskab) på hver sin
    // side. Nu er der tre mapper på én side: dagsorden, referat, regnskab.
    //
    // ENUM'en udvides FØRST med både de gamle og de nye værdier. Skiftede den
    // direkte til de nye, ville eksisterende rækker med 'generalforsamling'
    // blive ugyldige midt i omlægningen. Derefter flyttes de rækker over i
    // 'referat', og til sidst strammes ENUM'en til de tre mapper.
    $docCategory = $pdo->query("SHOW COLUMNS FROM documents LIKE 'category'")->fetch();

    if ($docCategory && strpos($docCategory['Type'], "'generalforsamling'") !== false) {
        $pdo->exec("ALTER TABLE documents MODIFY category
            ENUM('generalforsamling', 'regnskab', 'dagsorden', 'referat') NOT NULL");

        $pdo->exec("UPDATE documents SET category = 'referat' WHERE category = 'generalforsamling'");

        $pdo->exec("ALTER TABLE documents MODIFY category
            ENUM('dagsorden', 'referat', 'regnskab') NOT NULL");
    }

    // Brugere hører til en adresse, så et adgangskort kan se, om kontoen
    // tilhører den husstand, kortet er delt ud til.
    $userCols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('address', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN address VARCHAR(255) NOT NULL DEFAULT '' AFTER role");
        $pdo->exec('ALTER TABLE users ADD KEY idx_users_address (address)');
    }

    // ---------------------------------------------------------------------
    // adgangskort — ét kort pr. husstand, uddelt som postkort med QR-kode
    // ---------------------------------------------------------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS adgangskort (
            id INT NOT NULL AUTO_INCREMENT,
            adresse VARCHAR(255) NOT NULL,
            kode CHAR(8) NOT NULL,
            spaerret TINYINT(1) NOT NULL DEFAULT 0,
            antal_brug INT NOT NULL DEFAULT 0,
            sidst_brugt DATETIME NULL DEFAULT NULL,
            sendt_at DATETIME NULL DEFAULT NULL,
            sendt_antal INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_adgangskort_kode (kode),
            UNIQUE KEY uniq_adgangskort_adresse (adresse)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Første gang laves ét kort pr. husstand fra listen i husstande.php.
    // Derefter styres kortene kun fra adgangskort.php — her røres de ikke.
    if ((int)$pdo->query('SELECT COUNT(*) FROM adgangskort')->fetchColumn() === 0) {
        require_once __DIR__ . '/husstande.php';
        require_once __DIR__ . '/adgang.php';

        $saet = $pdo->prepare('INSERT INTO adgangskort (adresse, kode) VALUES (:a, :k)');
        $brugte = [];

        foreach (HBN_HUSSTANDE as $adresse) {
            // Koden skal være unik; gentag indtil der er fundet en ledig.
            do {
                $kode = adgang_ny_kode();
            } while (isset($brugte[$kode]));

            $brugte[$kode] = true;
            $saet->execute([':a' => $adresse, ':k' => $kode]);
        }
    }

    // Kortene kan sendes ud på mail, og det skal kunne ses hvornår —
    // ellers kan en udsendelse til 200 husstande ikke genoptages, hvis
    // den bliver afbrudt undervejs.
    $akCols = $pdo->query('SHOW COLUMNS FROM adgangskort')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('sendt_at', $akCols, true)) {
        $pdo->exec('ALTER TABLE adgangskort
            ADD COLUMN sendt_at DATETIME NULL DEFAULT NULL AFTER sidst_brugt,
            ADD COLUMN sendt_antal INT NOT NULL DEFAULT 0 AFTER sendt_at');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS aarshjul (
            id INT NOT NULL AUTO_INCREMENT,
            kategori ENUM('bestyrelsen', 'generalforsamling', 'socialt', 'andet') NOT NULL,
            titel VARCHAR(200) NOT NULL,
            tidspunkt VARCHAR(120) NOT NULL DEFAULT '',
            maaned TINYINT NULL DEFAULT NULL,
            beregning VARCHAR(40) NOT NULL DEFAULT '',
            beskrivelse TEXT NOT NULL,
            sortering SMALLINT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL DEFAULT NULL,
            updated_by VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_aarshjul_sort (sortering)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // "Andet" kom til efter, at tabellen var oprettet. Udvidelsen er ren
    // tilføjelse: ingen eksisterende rækker bliver ugyldige af den.
    $aarshjulKategori = $pdo->query("SHOW COLUMNS FROM aarshjul LIKE 'kategori'")->fetch();

    if ($aarshjulKategori && strpos($aarshjulKategori['Type'], "'andet'") === false) {
        $pdo->exec("ALTER TABLE aarshjul MODIFY kategori
            ENUM('bestyrelsen', 'generalforsamling', 'socialt', 'andet') NOT NULL");
    }

    // Årshjulet fyldes ud én gang med det, bestyrelsen oplyste. Derefter
    // rettes det kun fra aarshjul.php — her rives intet over.
    if ((int)$pdo->query('SELECT COUNT(*) FROM aarshjul')->fetchColumn() === 0) {
        $start = [
            ['bestyrelsen', 'Bestyrelsesmøde', 'Medio oktober', 10, '', '', 10],
            ['bestyrelsen', 'Bestyrelsesmøde', 'Marts/april', 3, '', '', 20],
            ['bestyrelsen', 'Bestyrelsesmøde', 'September — inden generalforsamlingen', 9, '', '', 30],
            ['bestyrelsen', 'Møder efter behov', 'Efter behov', null, '',
                'Holdes enten fysisk eller på Teams.', 40],

            ['generalforsamling', 'Generalforsamling', 'Sidste lørdag i august', 8,
                'sidste_loerdag_august',
                "Indkaldes mindst 14 dage før via mail og på Facebook.\n"
                . 'Bestyrelsen plejer at indkalde tre uger før.', 50],
            ['generalforsamling', 'Frist for forslag', '15. juni', 6, '',
                'Forslag, der ønskes behandlet på generalforsamlingen, skal være '
                . 'formanden i hænde senest 15. juni. Det står i § 6 i vedtægterne.', 60],
            ['generalforsamling', 'Påmindelse om forslagsfristen', 'April/maj', 4, '',
                'Bestyrelsen plejer at minde om fristen, når referatet sendes ud.', 70],

            ['socialt', 'Arbejdsdag', 'Lørdagen inden palmesøndag', null,
                'loerdag_foer_palmesoendag',
                'Holdes efter behov. Datoen følger påsken og flytter sig derfor '
                . 'fra år til år.', 80],
            ['socialt', 'Grillaften', 'Uge 29 eller 30', 7, '',
                'Indkaldes nogle få dage før, alt efter vejrudsigten.', 90],
        ];

        $saet = $pdo->prepare(
            'INSERT INTO aarshjul
                (kategori, titel, tidspunkt, maaned, beregning, beskrivelse, sortering)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($start as $post) {
            $saet->execute($post);
        }
    }

    // ---------------------------------------------------------------------
    // beboerkartotek — hvem bor hvor
    // ---------------------------------------------------------------------
    // Fyldes først med bestyrelsens regneark (beboer-import.php) og derefter
    // af ny-beboer.php. INDEHOLDER PERSONOPLYSNINGER — kun bestyrelsen.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS beboerkartotek (
            id INT NOT NULL AUTO_INCREMENT,
            forhold ENUM('ejer', 'medejer') NOT NULL DEFAULT 'ejer',
            sommerhusadresse VARCHAR(255) NOT NULL,
            vej VARCHAR(100) NOT NULL DEFAULT '',
            nr VARCHAR(10) NOT NULL DEFAULT '',
            bestyrelse TINYINT(1) NOT NULL DEFAULT 0,
            kontaktperson VARCHAR(255) NOT NULL,
            hjemmeadresse VARCHAR(255) NOT NULL DEFAULT '',
            sted VARCHAR(100) NOT NULL DEFAULT '',
            postnr VARCHAR(12) NOT NULL DEFAULT '',
            `by` VARCHAR(100) NOT NULL DEFAULT '',
            land VARCHAR(80) NOT NULL DEFAULT '',
            mail VARCHAR(255) NOT NULL DEFAULT '',
            mail2 VARCHAR(255) NOT NULL DEFAULT '',
            telefon VARCHAR(32) NOT NULL DEFAULT '',
            telefon2 VARCHAR(32) NOT NULL DEFAULT '',
            rolle VARCHAR(60) NOT NULL DEFAULT '',
            bfe VARCHAR(12) NOT NULL DEFAULT '',
            flyttet_ind DATE NULL DEFAULT NULL,
            noter TEXT NULL DEFAULT NULL,
            kilde ENUM('kartotek', 'ny-beboer') NOT NULL DEFAULT 'kartotek',
            status ENUM('aktiv', 'afventer_oplysninger') NOT NULL DEFAULT 'aktiv',
            token CHAR(32) NULL DEFAULT NULL,
            token_udloeb DATETIME NULL DEFAULT NULL,
            bekraeftet_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_beboer_token (token),
            KEY idx_beboer_adresse (sommerhusadresse),
            KEY idx_beboer_mail (mail),
            KEY idx_beboer_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Kartoteket skal have præcis de kolonner, regnearket har. Tabellen kan
    // være oprettet efter en ældre udgave af arket, så den rettes til her.
    // Kolonnerne flyttes med ALTER og ikke ved at droppe tabellen — en
    // DROP ville tage alle rækker med sig, også dem beboerne selv har
    // oprettet gennem Ny beboer.
    $bk = $pdo->query('SHOW COLUMNS FROM beboerkartotek')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (in_array('type', $bk, true) && !in_array('forhold', $bk, true)) {
        $pdo->exec("ALTER TABLE beboerkartotek
            CHANGE `type` forhold ENUM('ejer', 'medejer') NOT NULL DEFAULT 'ejer'");
    }

    if (!in_array('sted', $bk, true)) {
        $pdo->exec("ALTER TABLE beboerkartotek
            ADD COLUMN sted VARCHAR(100) NOT NULL DEFAULT '' AFTER hjemmeadresse");
    }

    // BFE-nummeret (Bestemt Fast Ejendom) bruges til at slå ejeren op på
    // nyt.ois.dk. Det står ikke i regnearket og udfyldes i hånden.
    if (!in_array('bfe', $bk, true)) {
        $pdo->exec("ALTER TABLE beboerkartotek
            ADD COLUMN bfe VARCHAR(12) NOT NULL DEFAULT '' AFTER rolle");
    }

    // Kolonner fra en tidligere udgave af arket, som ikke er der længere.
    foreach (['navn_i_arket', 'valgt_ud_fra', 'nr2'] as $vaek) {
        if (in_array($vaek, $bk, true)) {
            $pdo->exec('ALTER TABLE beboerkartotek DROP COLUMN ' . $vaek);
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS residents (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            address VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(32) NOT NULL DEFAULT '',
            moved_in DATE NULL DEFAULT NULL,
            note TEXT NULL DEFAULT NULL,
            consent TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('ny', 'behandlet') NOT NULL DEFAULT 'ny',
            handled_by INT NULL DEFAULT NULL,
            handled_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_residents_status (status, created_at),
            KEY idx_residents_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS messages (
            id INT NOT NULL AUTO_INCREMENT,
            body TEXT NOT NULL,
            sender_name VARCHAR(32) NOT NULL DEFAULT 'Hesselbjerg',
            sent_by INT NULL DEFAULT NULL,
            sent_by_name VARCHAR(255) NOT NULL DEFAULT '',
            recipient_count INT NOT NULL DEFAULT 0,
            parts SMALLINT NOT NULL DEFAULT 1,
            status ENUM('sendt', 'fejl') NOT NULL DEFAULT 'sendt',
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_messages_created (created_at),
            KEY idx_messages_user (sent_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_recipients (
            id INT NOT NULL AUTO_INCREMENT,
            message_id INT NOT NULL,
            resident_id INT NULL DEFAULT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            msisdn VARCHAR(16) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_msgrcpt_message (message_id),
            CONSTRAINT fk_msgrcpt_message
                FOREIGN KEY (message_id) REFERENCES messages (id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // messages kan stamme fra en version, der kun kunne sende SMS.
    $msgCols = $pdo->query('SHOW COLUMNS FROM messages')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('channel', $msgCols, true)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN channel ENUM('sms', 'email') NOT NULL DEFAULT 'sms' AFTER body");
    }

    if (!in_array('subject', $msgCols, true)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN subject VARCHAR(255) NOT NULL DEFAULT '' AFTER channel");
    }

    if (!in_array('ok_count', $msgCols, true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN ok_count INT NOT NULL DEFAULT 0 AFTER recipient_count');
    }

    $rcptCols = $pdo->query('SHOW COLUMNS FROM message_recipients')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('email', $rcptCols, true)) {
        $pdo->exec("ALTER TABLE message_recipients ADD COLUMN email VARCHAR(255) NOT NULL DEFAULT '' AFTER msisdn");
        $pdo->exec("ALTER TABLE message_recipients MODIFY msisdn VARCHAR(16) NOT NULL DEFAULT ''");
    }
    // Tabellen kan stamme fra en tidligere version uden disse kolonner.
    $existing = $pdo->query('SHOW COLUMNS FROM member_photos')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('user_id', $existing, true)) {
        $pdo->exec('ALTER TABLE member_photos ADD COLUMN user_id INT NULL DEFAULT NULL AFTER id');
        $pdo->exec('ALTER TABLE member_photos ADD KEY idx_member_photos_user (user_id)');
    }

    if (!in_array('mime_type', $existing, true)) {
        $pdo->exec("ALTER TABLE member_photos ADD COLUMN mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg' AFTER original_name");
    }
} catch (PDOException $e) {
    error_log('Hesselbjerg Nord DB-fejl: ' . $e->getMessage());
    http_response_code(503);
    die('Databaseforbindelsen kunne ikke oprettes. Prøv igen senere.');
}
