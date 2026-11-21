<?php
/**
 * Beboerkartoteket er nu admin.php. Gamle bogmærker og links sendes
 * dertil — med søgning og filter, så "?vis=noter" stadig virker.
 */
$q = array_filter([
    's' => (string)($_GET['s'] ?? ''),
    'vis' => (string)($_GET['vis'] ?? ''),
    'besked' => (string)($_GET['besked'] ?? ''),
], 'strlen');

header('Location: admin.php' . ($q ? '?' . http_build_query($q) : ''), true, 301);
exit;
