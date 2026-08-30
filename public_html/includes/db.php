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
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
?>
