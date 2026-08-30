<?php
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
        CREATE TABLE IF NOT EXISTS member_photos (
            id INT NOT NULL AUTO_INCREMENT,
            member_name VARCHAR(255) NOT NULL DEFAULT 'Medlem',
            file_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
