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
            category ENUM('generalforsamling', 'regnskab') NOT NULL,
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
