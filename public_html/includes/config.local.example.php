<?php
/**
 * Skabelon til includes/config.local.php.
 *
 * SÅDAN: kopiér filen til config.local.php, udfyld værdierne, og læg KUN
 * config.local.php på serveren. Den må aldrig i git — den indeholder
 * adgangskoder.
 */

return [
    // SureSMS: en API-nøgle fra https://app.suresms.com/UserApi/Index.
    'suresms_apikey' => '',

    // Afsendernavnet, modtageren ser. "Hesselbjerg" er godkendt som
    // Sender-ID hos SureSMS. Et navn, der IKKE er godkendt, bliver
    // taget imod og smidt væk bagefter med "Unauthorized SenderID",
    // og det ses kun i udbakken. Tom streng = kontoens eget nummer.
    'sms_sender' => 'Hesselbjerg',
    'sms_sender' => 'Hesselbjerg',

    // Kun nødvendig på en maskine bag en TLS-inspicerende proxy,
    // hvor PHP ikke kender proxyens rodcertifikat. Lad den stå tom
    // på serveren.
    'sms_ca_bundle' => '',

    'mail_from' => 'bestyrelsen@hesselbjergnord.dk',
    'mail_from_name' => 'Grundejerforeningen Hesselbjerg Nord',

    // SMTP hos udbyderen. Tom smtp_host = brug PHP's mail() i stedet.
    'smtp_host' => 'websmtp.simply.com',
    'smtp_port' => 587,
    'smtp_user' => 'bestyrelsen@hesselbjergnord.dk',
    'smtp_pass' => '',
    'smtp_helo' => 'hesselbjergnord.dk',
];
