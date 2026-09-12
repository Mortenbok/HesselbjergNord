<?php
/**
 * Skabelon til includes/config.local.php.
 *
 * SÅDAN: kopiér filen til config.local.php, udfyld værdierne, og læg KUN
 * config.local.php på serveren. Den må aldrig i git — den indeholder
 * adgangskoder.
 */

return [
    'gatewayapi_token' => '',
    'sms_sender' => 'Hesselbjerg',

    'mail_from' => 'bestyrelsen@hesselbjergnord.dk',
    'mail_from_name' => 'Grundejerforeningen Hesselbjerg Nord',

    // SMTP hos udbyderen. Tom smtp_host = brug PHP's mail() i stedet.
    'smtp_host' => 'websmtp.simply.com',
    'smtp_port' => 587,
    'smtp_user' => 'bestyrelsen@hesselbjergnord.dk',
    'smtp_pass' => '',
    'smtp_helo' => 'hesselbjergnord.dk',
];
