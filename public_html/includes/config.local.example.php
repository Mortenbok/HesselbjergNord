<?php
/**
 * Hemmeligheder, der IKKE må ligge i git.
 *
 * SÅDAN TAGER DU DEN I BRUG
 *   1. Kopiér denne fil til includes/config.local.php
 *   2. Sæt din rigtige GatewayAPI-nøgle ind
 *   3. Læg KUN config.local.php på serveren — aldrig i git
 *
 * Nøglen hentes hos GatewayAPI under "API-nøgler". Den giver adgang til at
 * sende for foreningens regning, så den skal behandles som en adgangskode.
 */

return [
    // API-token fra gatewayapi.com. Tom streng = afsendelse slået fra.
    'gatewayapi_token' => '',

    // Afsendernavn, som beboerne ser. Højst 11 tegn, kun bogstaver og tal.
    'sms_sender' => 'Hesselbjerg',
];
