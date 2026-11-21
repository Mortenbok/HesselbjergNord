<?php
/**
 * Ejertjek — henter ejernavnene fra OIS til de husstande, der ikke er
 * tjekket den sidste måned.
 *
 * Kaldes af GitHub Actions én gang om måneden (.github/workflows/ejertjek.yml),
 * igen og igen, til "mangler" er 0. Hvert kald tager højst
 * EJERTJEK_AD_GANGEN husstande, så det holder sig inden for serverens
 * tidsgrænse.
 *
 * Siden kræver intet login, og det er med vilje: den viser ingen navne,
 * kun tal, og den rører kun husstande, hvis tjek er ældre end
 * EJERTJEK_DAGE. Kalder nogen den hundrede gange, sker der intet efter
 * første omgang. Så skal der ikke ligge en nøgle hverken på serveren
 * eller i GitHub.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ejertjek.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

@set_time_limit(120);

echo json_encode(ejertjek_koer($pdo));
