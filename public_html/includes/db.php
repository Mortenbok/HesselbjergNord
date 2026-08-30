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
