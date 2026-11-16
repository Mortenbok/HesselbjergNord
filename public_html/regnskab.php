<?php
/**
 * Regnskab havde før sin egen side. Regnskaberne ligger nu i mappen Regnskab
 * på generalforsamling.php, og denne fil sender blot videre dertil.
 *
 * Filen må IKKE slettes fra git: udgivelsen fjerner aldrig filer på serveren,
 * så den gamle, fungerende side ville blive stående, hvis denne forsvandt.
 * Gamle links og bogmærker peger også stadig hertil.
 */

header('Location: generalforsamling.php?mappe=regnskab', true, 301);
exit;
