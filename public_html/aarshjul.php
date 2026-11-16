<?php
/**
 * Årshjul / Aktiviteter — foreningens faste år.
 *
 * Siden er OFFENTLIG: alle kan læse den uden login. Er man logget ind som
 * bestyrelsesmedlem, står redigeringen nederst på siden, så datoer og tekster
 * kan rettes direkte i browseren uden FTP.
 *
 * Indholdet ligger i tabellen aarshjul — al logik og HTML i
 * includes/aarshjul.php.
 */

require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/aarshjul.php';

auth_start_session();

$user = auth_user();
$message = aarshjul_handle_post($pdo, $user);

aarshjul_render_page(aarshjul_list($pdo), $user, $message, (int)date('Y'));
