<?php
/**
 * Beskeder — SMS og mail til beboerne.
 *
 * Kun bestyrelsen har adgang. En udsendelse koster penge (SMS) eller kan ikke
 * kaldes tilbage (begge dele), så siden kræver, at afsenderen først ser
 * modtagerlisten og derefter bekræfter i et ekstra trin.
 *
 * Modtagerne kommer fra BEBOERKARTOTEKET — foreningens eget register. Både
 * mail og mail2 tæller, og det samme gør telefon og telefon2, så begge
 * voksne på en adresse kan nås. Den samme adresse får kun beskeden én gang.
 *
 * En udsendelse til hele foreningen kan ikke nå at blive sendt i ét kald:
 * hver mail får sin egen SMTP-forbindelse. Derfor lægges modtagerne i kø i
 * message_recipients, og siden sender BESKED_AD_GANGEN ad gangen. sendt_at
 * viser, hvem der har fået den, så en afbrudt udsendelse kan tages op igen.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/mail.php';
require_once __DIR__ . '/includes/beboer.php';

auth_require('index.html');

$user = auth_user();
$denied = ($user['role'] ?? '') !== 'bestyrelse';

if ($denied) {
    http_response_code(403);
}

/** Modtagere pr. udsendelse. Resten venter til næste klik. */
const BESKED_AD_GANGEN = 25;

/** Hvor langt er en udsendelse nået? */
function besked_koe(PDO $pdo, int $messageId): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS ialt,
                SUM(sendt_at IS NOT NULL) AS sendt,
                SUM(fejl <> '') AS fejlede
           FROM message_recipients
          WHERE message_id = :m"
    );
    $stmt->execute([':m' => $messageId]);
    $r = $stmt->fetch() ?: [];

    $ialt = (int)($r['ialt'] ?? 0);
    $sendt = (int)($r['sendt'] ?? 0);

    return [
        'ialt' => $ialt,
        'sendt' => $sendt,
        'fejlede' => (int)($r['fejlede'] ?? 0),
        'mangler' => max(0, $ialt - $sendt),
    ];
}

/**
 * Sender det næste hold af en udsendelse.
 *
 * Hver modtager mærkes, så snart beskeden er af sted. Bliver kaldet
 * afbrudt — af en tidsgrænse eller en lukket fane — kan der trykkes igen,
 * og de allerede sendte bliver ikke sendt om.
 */
function besked_send_hold(PDO $pdo, array $msg, int $antal): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM message_recipients
          WHERE message_id = :m AND sendt_at IS NULL
          ORDER BY id LIMIT ' . (int)$antal
    );
    $stmt->execute([':m' => (int)$msg['id']]);
    $hold = $stmt->fetchAll();

    if ($hold === []) {
        return ['ok' => 0, 'fejl' => 0, 'besked' => ''];
    }

    $noter = $pdo->prepare(
        'UPDATE message_recipients SET sendt_at = NOW(), fejl = :f WHERE id = :id'
    );

    $ok = 0;
    $fejl = 0;
    $sidsteFejl = '';

    if ($msg['channel'] === 'sms') {
        // SureSMS tager hele holdet i ét kald — numrene adskilles med
        // semikolon — og svarer ja eller nej for dem alle under ét.
        $svar = sms_send(array_column($hold, 'msisdn'), (string)$msg['body']);

        foreach ($hold as $m) {
            $noter->execute([
                ':f' => $svar['ok'] ? '' : mb_substr((string)$svar['error'], 0, 255),
                ':id' => (int)$m['id'],
            ]);
        }

        if ($svar['ok']) {
            $ok = count($hold);
        } else {
            $fejl = count($hold);
            $sidsteFejl = (string)$svar['error'];
        }
    } else {
        foreach ($hold as $m) {
            $svar = mail_send([$m['email']], (string)$msg['subject'], (string)$msg['body']);
            $gik = ($svar['ok'] ?? 0) > 0;

            if ($gik) {
                $ok++;
            } else {
                $fejl++;
                $sidsteFejl = ($svar['error'] ?? '') !== '' ? $svar['error'] : 'Mailen blev afvist.';
            }

            $noter->execute([
                ':f' => $gik ? '' : mb_substr($sidsteFejl, 0, 255),
                ':id' => (int)$m['id'],
            ]);
        }
    }

    // Tælleren på selve udsendelsen følger med, så historikken er rigtig,
    // også hvis udsendelsen aldrig bliver gjort færdig.
    $pdo->prepare('UPDATE messages SET ok_count = ok_count + :n WHERE id = :id')
        ->execute([':n' => $ok, ':id' => (int)$msg['id']]);

    $koe = besked_koe($pdo, (int)$msg['id']);

    if ($koe['mangler'] === 0) {
        $pdo->prepare('UPDATE messages SET status = :s, error = :e WHERE id = :id')->execute([
            ':s' => $koe['fejlede'] >= $koe['ialt'] ? 'fejl' : 'sendt',
            ':e' => mb_substr($sidsteFejl, 0, 255),
            ':id' => (int)$msg['id'],
        ]);
    }

    return ['ok' => $ok, 'fejl' => $fejl, 'besked' => $sidsteFejl];
}

/** Kort kvittering efter et hold. */
function besked_kvittering(array $svar, array $koe): array
{
    $hale = $koe['mangler'] > 0
        ? $koe['mangler'] . ' mangler endnu — tryk igen.'
        : 'Udsendelsen er færdig.';

    if ($svar['fejl'] > 0) {
        return ['warn', 'Sendt til ' . $svar['ok'] . '. ' . $svar['fejl']
            . ' kunne ikke nås. ' . $svar['besked'] . ' ' . $hale];
    }

    return ['ok', 'Sendt til ' . $svar['ok'] . '. ' . $hale];
}

$channel = ($_POST['channel'] ?? 'sms') === 'email' ? 'email' : 'sms';
$gruppe = ($_POST['gruppe'] ?? 'alle') === 'bestyrelse' ? 'bestyrelse' : 'alle';
$subject = trim((string)($_POST['subject'] ?? ''));
$body = trim((string)($_POST['body'] ?? ''));
$message = null;
$confirming = false;

$kunBestyrelse = $gruppe === 'bestyrelse';

// Modtagerne kommer fra beboerkartoteket. Den gamle residents-tabel bliver
// ikke længere fyldt — ny-beboer.php skriver i kartoteket.
$recipients = $denied ? [] : beboer_modtagere($pdo, $channel === 'sms' ? 'sms' : 'mail', $kunBestyrelse);

// Begge tal skal med ud i siden, så teksten kan opdateres, når kanalen
// skiftes — uden at spørge serveren igen.
$antalKanal = $denied ? ['mail' => 0, 'sms' => 0] : beboer_antal_modtagere($pdo, $kunBestyrelse);
$countSms = $antalKanal['sms'];
$countMail = $antalKanal['mail'];

// Bestyrelsens tal med ud i siden, så teksten kan følge med, når man
// skifter gruppe — uden at spørge serveren igen.
$antalBest = $denied ? ['mail' => 0, 'sms' => 0] : beboer_antal_modtagere($pdo, true);
$length = sms_length($body);

if (!$denied && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $trin = (string)($_POST['step'] ?? '');

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $message = ['bad', 'Handlingen var udløbet. Prøv igen.'];
    } elseif ($trin === 'fortsaet') {
        // Næste hold af en udsendelse, der allerede er lagt i kø.
        $stmt = $pdo->prepare("SELECT * FROM messages WHERE id = :id AND status = 'sender' LIMIT 1");
        $stmt->execute([':id' => (int)($_POST['message_id'] ?? 0)]);
        $msg = $stmt->fetch();

        if (!$msg) {
            $message = ['bad', 'Udsendelsen blev ikke fundet, eller den er allerede færdig.'];
        } else {
            $svar = besked_send_hold($pdo, $msg, BESKED_AD_GANGEN);
            $message = besked_kvittering($svar, besked_koe($pdo, (int)$msg['id']));
        }
    } elseif ($body === '') {
        $message = ['bad', 'Skriv en besked, før du sender.'];
    } elseif ($channel === 'email' && $subject === '') {
        $message = ['bad', 'Skriv et emne, før du sender en mail.'];
    } elseif ($recipients === []) {
        $message = ['bad', $channel === 'sms'
            ? 'Der er ingen i beboerkartoteket med et brugbart telefonnummer.'
            : 'Der er ingen i beboerkartoteket med en mailadresse.'];
    } elseif ($trin !== 'send') {
        $confirming = true;
    } else {
        // Udsendelsen lægges i kø og sendes i hold. Køen oprettes i én
        // transaktion, så der aldrig står en halv modtagerliste.
        $messageId = 0;

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "INSERT INTO messages (body, channel, subject, sender_name, sent_by, sent_by_name,
                                       recipient_count, ok_count, parts, status, error)
                 VALUES (:body, :channel, :subject, :sender, :by, :byname, :count, 0, :parts, 'sender', '')"
            )->execute([
                ':body' => $body,
                ':channel' => $channel,
                ':subject' => $channel === 'email' ? $subject : '',
                ':sender' => $channel === 'sms' ? sms_config()['sms_sender'] : mail_from()[0],
                ':by' => $user['id'],
                ':byname' => $user['display_name'],
                ':count' => count($recipients),
                ':parts' => $channel === 'sms' ? $length['parts'] : 1,
            ]);

            $messageId = (int)$pdo->lastInsertId();

            $rcpt = $pdo->prepare(
                'INSERT INTO message_recipients (message_id, beboer_id, name, msisdn, email)
                 VALUES (:m, :b, :n, :p, :e)'
            );

            foreach ($recipients as $r) {
                $rcpt->execute([
                    ':m' => $messageId,
                    ':b' => $r['beboer_id'],
                    ':n' => $r['navn'],
                    ':p' => $r['msisdn'],
                    ':e' => $r['email'],
                ]);
            }

            $pdo->commit();
        } catch (PDOException $ex) {
            $pdo->rollBack();
            error_log('Besked: ' . $ex->getMessage());
            $messageId = 0;
            $message = ['bad', 'Udsendelsen kunne ikke oprettes. Intet blev sendt.'];
        }

        if ($messageId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM messages WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $messageId]);

            $svar = besked_send_hold($pdo, $stmt->fetch(), BESKED_AD_GANGEN);
            $message = besked_kvittering($svar, besked_koe($pdo, $messageId));

            $body = '';
            $subject = '';
            $length = sms_length('');
        }
    }
}

// En udsendelse, der er lagt i kø, men ikke sendt færdig. Den står øverst,
// til den er af sted — ellers bliver den glemt halvvejs.
$igang = $denied ? null : $pdo->query(
    "SELECT * FROM messages WHERE status = 'sender' ORDER BY id DESC LIMIT 1"
)->fetch();

$igangKoe = $igang ? besked_koe($pdo, (int)$igang['id']) : null;

$history = $denied ? [] : $pdo->query(
    'SELECT * FROM messages ORDER BY created_at DESC LIMIT 20'
)->fetchAll();

$csrf = auth_csrf_token();
$e = static fn(?string $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Beskeder — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: url('kontingent.jpg') no-repeat center center fixed;
    background-size: cover;
    color: #fff;
    position: relative;
  }

  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 0;
  }

  nav {
    position: relative;
    z-index: 2;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 20px 15px;
    background: linear-gradient(to bottom, rgba(0,0,0,0.45), transparent);
  }

  .nav-links {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 10px;
    flex: 1;
  }

  nav a,
  .login-btn {
    color: #fff;
    text-decoration: none;
    font-size: 0.95rem;
    letter-spacing: 1px;
    padding: 8px 16px;
    border-radius: 20px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    transition: background 0.25s ease;
  }

  nav a:hover,
  nav a.active,
  .login-btn:hover {
    background: rgba(255,255,255,0.18);
  }

  .nav-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 10px;
  }

  .nav-user {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    white-space: nowrap;
  }

  .login-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.4);
    white-space: nowrap;
    cursor: pointer;
    font-family: inherit;
  }

  .container {
    position: relative;
    z-index: 1;
    max-width: 820px;
    margin: 0 auto;
    padding: 20px 20px 70px;
  }

  h1 {
    font-size: clamp(2rem, 5vw, 3rem);
    letter-spacing: 1px;
    text-shadow: 0 2px 10px rgba(0,0,0,0.6);
    margin-bottom: 10px;
  }

  p.lead {
    font-size: clamp(1rem, 2.2vw, 1.15rem);
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    margin-bottom: 28px;
    max-width: 62ch;
  }

  .panel {
    background: rgba(10, 20, 25, 0.55);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }

  .panel h2 {
    font-size: 1.35rem;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
  }

  /* Tjekliste over det, man skal have ved hånden. */
  .checklist {
    list-style: none;
    display: grid;
    gap: 12px;
  }

  .checklist li {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    line-height: 1.5;
  }

  .checklist li::before {
    content: "✓";
    flex: 0 0 auto;
    width: 24px;
    height: 24px;
    margin-top: 1px;
    border-radius: 50%;
    background: rgba(70, 170, 110, 0.35);
    border: 1px solid rgba(150, 230, 180, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
  }

  .checklist strong { display: block; }

  .checklist span {
    color: rgba(255,255,255,0.8);
    font-size: 0.92rem;
  }

  .steps {
    list-style: none;
    counter-reset: step;
    display: grid;
    gap: 16px;
  }

  .steps li {
    counter-increment: step;
    display: flex;
    gap: 16px;
    align-items: flex-start;
  }

  .steps li::before {
    content: counter(step);
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
  }

  .steps strong {
    display: block;
    margin-bottom: 3px;
  }

  .steps span {
    color: rgba(255,255,255,0.85);
    font-size: 0.95rem;
    line-height: 1.5;
  }

  /* Selve tilmeldingsknappen. */
  .signup {
    text-align: center;
    padding: 26px 24px;
  }

  .signup-btn {
    display: inline-block;
    background: rgba(45, 115, 190, 0.75);
    color: #fff;
    text-decoration: none;
    font-size: 1.15rem;
    font-weight: 600;
    letter-spacing: 1px;
    padding: 16px 40px;
    border-radius: 12px;
    border: 1px solid rgba(160, 205, 255, 0.7);
    box-shadow: 0 6px 18px rgba(0,0,0,0.3);
    transition: background 0.2s ease, transform 0.1s ease;
  }

  .signup-btn:hover { background: rgba(60, 140, 220, 0.9); }
  .signup-btn:active { transform: translateY(1px); }

  .signup-note {
    margin-top: 14px;
    font-size: 0.88rem;
    color: rgba(255,255,255,0.75);
  }

  .todo {
    background: rgba(190, 140, 20, 0.32);
    border: 1px solid rgba(255, 210, 120, 0.6);
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 0.92rem;
    line-height: 1.55;
    color: #ffeec9;
    margin-bottom: 24px;
  }

  .todo strong { color: #fff; }

  .todo code {
    font-family: Consolas, 'Courier New', monospace;
    background: rgba(0,0,0,0.35);
    padding: 1px 5px;
    border-radius: 4px;
    word-break: break-all;
  }

  .todo ol {
    margin: 10px 0 0 20px;
    display: grid;
    gap: 6px;
  }

  .faq dt {
    font-weight: 600;
    margin-top: 14px;
  }

  .faq dt:first-child { margin-top: 0; }

  .faq dd {
    margin: 4px 0 0;
    color: rgba(255,255,255,0.82);
    font-size: 0.95rem;
    line-height: 1.55;
  }

  /* Login-dialog — samme som på de øvrige sider. */
  .modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.5);
    z-index: 20;
  }

  .modal.open { display: flex; }

  .modal-content {
    background: rgba(14, 22, 27, 0.94);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 16px;
    padding: 24px;
    width: min(92vw, 380px);
    box-shadow: 0 20px 50px rgba(0,0,0,0.35);
  }

  .modal-content h2 {
    margin-bottom: 18px;
    text-align: center;
    font-size: 1.5rem;
  }

  .modal-content label {
    display: block;
    margin-bottom: 8px;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.9);
  }

  .modal-content input {
    width: 100%;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
  }

  .modal-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 8px;
  }

  .modal-actions button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    padding: 10px 16px;
    cursor: pointer;
    font: inherit;
  }

  .close-btn {
    background: transparent;
    border: none;
    color: rgba(255,255,255,0.8);
    font-size: 1.5rem;
    cursor: pointer;
    line-height: 1;
  }

  .login-error {
    display: none;
    margin-bottom: 14px;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(180, 40, 40, 0.35);
    color: #ffdede;
    font-size: 0.9rem;
    text-align: left;
  }

  .login-error.show { display: block; }

  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.75);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }

  @media (max-width: 520px) {
    .signup-btn {
      display: block;
      width: 100%;
      padding: 16px 20px;
    }
  }
  /* ---- Beskeder --------------------------------------------------------- */
  .muted { color: rgba(255,255,255,0.72); font-size: 0.92rem; margin-bottom: 14px; }
  .container a { color: inherit; text-decoration: underline; text-underline-offset: 2px; }

  #msgBody {
    width: 100%;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
    font-size: 16px;
    resize: vertical;
  }

  #msgBody[readonly] { opacity: 0.75; }

  .counter { margin-top: 8px; font-size: 0.88rem; color: rgba(255,255,255,0.75); }
  .warn-text { color: #ffc9a8; }

  .submit-btn, .actions button {
    margin-top: 18px;
    padding: 13px 26px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,0.45);
    background: rgba(255,255,255,0.14);
    color: #fff;
    font: inherit;
    font-size: 1rem;
    cursor: pointer;
  }

  .actions button.danger { border-color: rgba(230,120,120,0.6); background: rgba(150,45,45,0.45); }
  .actions button[disabled] { opacity: 0.45; cursor: not-allowed; }
  .actions { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; }
  .cancel { font-size: 0.92rem; }

  .panel.confirm { margin-top: 18px; border-color: rgba(230,160,90,0.5); background: rgba(90,55,20,0.35); }
  .panel.warn { border-color: rgba(230,180,90,0.5); background: rgba(90,70,20,0.35); }
  .panel.ok { border-color: rgba(80,190,120,0.5); background: rgba(30,90,50,0.35); }
  .panel.bad { border-color: rgba(220,90,90,0.5); background: rgba(120,35,35,0.35); }

  .rcpt { margin: 10px 0 0 18px; font-size: 0.9rem; line-height: 1.7; max-height: 240px; overflow-y: auto; }

  .log { list-style: none; display: grid; gap: 12px; }

  .log li {
    padding: 14px 16px;
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 12px;
    background: rgba(0,0,0,0.22);
  }

  .log li.fejl { border-color: rgba(220,90,90,0.45); }
  .log-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
  .log-when { font-size: 0.84rem; color: rgba(255,255,255,0.6); }
  .log-body { margin: 8px 0; line-height: 1.5; }
  .log-meta { font-size: 0.84rem; color: rgba(255,255,255,0.62); }

  .tag { flex: none; padding: 3px 11px; border-radius: 999px; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em; }
  .tag.sendt { background: rgba(60,150,90,0.35); color: #d8ffe6; }
  .tag.fejl { background: rgba(190,70,70,0.4); color: #ffdede; }

  @media (max-width: 560px) {
    .actions { flex-direction: column; align-items: stretch; }
    .actions button { width: 100%; }
    .cancel { text-align: center; }
  }

  /* ---- Kanalvalg og emnefelt -------------------------------------------- */
  .channel { border: 0; display: flex; gap: 22px; margin-bottom: 14px; }
  .channel legend { font-size: 0.92rem; color: rgba(255,255,255,0.9); margin-bottom: 8px; }
  .channel label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 1rem; }
  .channel input { width: 20px; height: 20px; }
  .channel input:disabled + span { opacity: 0.6; }

  #subjectRow label { display: block; margin: 4px 0 6px; font-size: 0.92rem; color: rgba(255,255,255,0.9); }

  #msgSubject {
    width: 100%;
    margin-bottom: 14px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.22);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font: inherit;
    font-size: 16px;
  }

  #msgSubject[readonly] { opacity: 0.75; }
  .hidden-row { display: none; }

  .log-subject { margin: 6px 0 2px; }
  .tag.kanal { background: rgba(255,255,255,0.14); color: rgba(255,255,255,0.85); margin-right: 6px; }
</style>
<link rel="stylesheet" href="mobile-nav.css">
<script src="mobile-nav.js" defer></script>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="index.html">Forside</a>
      <a href="bestyrelsen.php">Bestyrelsen</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="omraade.html">Området</a>
      <a href="aarshjul.php">Årshjul</a>
      <a href="ny-beboer.php" data-guest-only>Ny beboer</a>
      <a href="vedtaegter.php" data-members-only hidden>Vedtægter</a>
      <a href="medlemsfotos.php" data-members-only hidden>Medlemsfotos</a>
      <a href="generalforsamling.php" data-members-only hidden>Generalforsamling</a>
      <a href="besked.php" class="active" data-board-only hidden>Beskeder</a>
      <a href="adgangskort.php" data-board-only hidden>Adgangskort</a>
      <a href="beboerkartotek.php" data-board-only hidden>Beboerkartotek</a>
    </div>
    <div class="nav-account">
      <span class="nav-user" data-user-name hidden></span>
      <button type="button" class="login-btn" data-open-login>Login</button>
      <a href="logout.php" class="login-btn" data-logout hidden>Log ud</a>
    </div>
  </nav>

  <div class="modal" id="loginModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h2 id="loginTitle">Log ind</h2>
        <button type="button" class="close-btn" aria-label="Luk" data-close-login>&times;</button>
      </div>
      <p class="login-error" id="loginError" role="alert"></p>

      <form method="post" action="login.php">
        <input type="hidden" name="next" value="medlemsfotos.php">

        <label for="loginUser">Brugernavn eller mail</label>
        <input id="loginUser" type="text" name="username" placeholder="Brugernavn eller mail"
               autocomplete="username" required>

        <label for="loginPass">Adgangskode</label>
        <input id="loginPass" type="password" name="password" placeholder="Adgangskode"
               autocomplete="current-password" required>

        <div class="modal-actions">
          <button type="button" data-close-login>Annuller</button>
          <button type="submit">Log ind</button>
        </div>
      </form>
    </div>
  </div>


  <div class="container">
    <h1>Beskeder</h1>

    <?php if ($denied): ?>
      <div class="panel bad">
        <h2>Ingen adgang</h2>
        <p>Kun bestyrelsen kan sende beskeder til beboerne.</p>
      </div>
    <?php else: ?>

      <?php if ($channel === 'sms' && !sms_enabled()): ?>
        <div class="panel warn">
          <strong>SMS er ikke sat op endnu.</strong>
          <p>
            Læg <code>suresms_client_id</code> og <code>suresms_secret_id</code>
            i <code>includes/config.local.php</code>. Begge dele står på din
            konto hos SureSMS. Indtil da kan SMS skrives, men ikke sendes —
            mail virker uafhængigt af det.
          </p>
        </div>
      <?php endif; ?>

      <?php if ($channel === 'email' && !mail_uses_smtp()): ?>
        <div class="panel warn">
          <strong>Mail er ikke sat op endnu.</strong>
          <p>
            Udfyld <code>smtp_host</code>, <code>smtp_user</code> og
            <code>smtp_pass</code> i <code>includes/config.local.php</code>.
            Uden dem forsøger siden med PHP's <code>mail()</code>, som er slået
            fra hos de fleste webhoteller — og så kommer mailen aldrig frem.
          </p>
        </div>
      <?php endif; ?>

      <?php if ($igang !== null && $igangKoe !== null && $igangKoe['mangler'] > 0): ?>
        <div class="panel warn">
          <h2>Udsendelse i gang</h2>
          <p>
            <strong><?php echo $e($igang['channel'] === 'sms' ? 'SMS' : $igang['subject']); ?></strong>
            er sendt til <strong><?php echo $igangKoe['sendt']; ?></strong> af
            <strong><?php echo $igangKoe['ialt']; ?></strong> modtagere.
            <?php echo $igangKoe['mangler']; ?> mangler endnu.
          </p>
          <p class="muted">
            Der sendes <?php echo BESKED_AD_GANGEN; ?> ad gangen, så udsendelsen
            ikke løber ind i serverens tidsgrænse. Tryk, til tallet er nul —
            ingen får beskeden to gange.
          </p>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
            <input type="hidden" name="message_id" value="<?php echo (int)$igang['id']; ?>">
            <div class="actions">
              <button type="submit" name="step" value="fortsaet" class="danger">
                Send til de næste <?php echo min($igangKoe['mangler'], BESKED_AD_GANGEN); ?>
              </button>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($message !== null): ?>
        <div class="panel <?php echo $e($message[0]); ?>" role="status"><?php echo $e($message[1]); ?></div>
      <?php endif; ?>

      <div class="panel">
        <h2>Ny besked</h2>

        <form method="post" id="msgForm">
          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">

          <fieldset class="channel">
            <legend>Send som</legend>
            <label>
              <input type="radio" name="channel" value="sms"
                     <?php echo $channel === 'sms' ? 'checked' : ''; ?>
                     <?php echo $confirming ? 'disabled' : ''; ?>>
              <span>SMS</span>
            </label>
            <label>
              <input type="radio" name="channel" value="email"
                     <?php echo $channel === 'email' ? 'checked' : ''; ?>
                     <?php echo $confirming ? 'disabled' : ''; ?>>
              <span>Mail</span>
            </label>
          </fieldset>

          <?php if ($confirming): ?>
            <input type="hidden" name="channel" value="<?php echo $e($channel); ?>">
          <?php endif; ?>

          <fieldset class="channel">
            <legend>Modtagere</legend>
            <label>
              <input type="radio" name="gruppe" value="alle"
                     <?php echo $gruppe === 'alle' ? 'checked' : ''; ?>
                     <?php echo $confirming ? 'disabled' : ''; ?>>
              <span>Alle i beboerkartoteket</span>
            </label>
            <label>
              <input type="radio" name="gruppe" value="bestyrelse"
                     <?php echo $gruppe === 'bestyrelse' ? 'checked' : ''; ?>
                     <?php echo $confirming ? 'disabled' : ''; ?>>
              <span>Kun bestyrelsen</span>
            </label>
          </fieldset>

          <?php if ($confirming): ?>
            <input type="hidden" name="gruppe" value="<?php echo $e($gruppe); ?>">
          <?php endif; ?>

          <p class="muted" id="rcptInfo"
             data-sms="<?php echo (int)$countSms; ?>"
             data-mail="<?php echo (int)$countMail; ?>"
             data-sms-best="<?php echo (int)$antalBest['sms']; ?>"
             data-mail-best="<?php echo (int)$antalBest['mail']; ?>">
            Sendes til <strong><?php echo count($recipients); ?></strong>
            <?php echo count($recipients) === 1 ? 'modtager' : 'modtagere'; ?>
            <?php echo $channel === 'sms' ? 'med telefonnummer' : 'med mailadresse'; ?>
            i beboerkartoteket.
          </p>

          <div id="subjectRow" class="<?php echo $channel === 'email' ? '' : 'hidden-row'; ?>">
            <label for="msgSubject">Emne</label>
            <input id="msgSubject" type="text" name="subject" maxlength="255"
                   placeholder="Fx: Indkaldelse til generalforsamling"
                   <?php echo $confirming ? 'readonly' : ''; ?>
                   value="<?php echo $e($subject); ?>">
          </div>

          <label for="msgBody">Tekst</label>
          <textarea id="msgBody" name="body" rows="<?php echo $channel === 'email' ? 10 : 5; ?>"
                    maxlength="<?php echo $channel === 'email' ? 5000 : 1000; ?>"
                    placeholder="Fx: Husk generalforsamling torsdag kl. 19 i klubhuset."
                    <?php echo $confirming ? 'readonly' : ''; ?>><?php echo $e($body); ?></textarea>

          <p class="counter" id="counter" aria-live="polite"
             <?php echo $channel === 'email' ? 'hidden' : ''; ?>>
            <?php echo $length['units']; ?> tegn &middot;
            <?php echo $length['parts']; ?> SMS pr. modtager
            <?php if ($length['unicode']): ?>
              &middot; <span class="warn-text">specialtegn — kun 70 tegn pr. SMS</span>
            <?php endif; ?>
          </p>

          <?php if ($confirming): ?>
            <div class="panel confirm">
              <h3>Er du sikker?</h3>
              <p>
                <?php if ($channel === 'sms'): ?>
                  Beskeden sendes som SMS til <strong><?php echo count($recipients); ?></strong>
                  modtagere som <strong><?php echo $length['parts']; ?></strong> SMS hver
                  — i alt <strong><?php echo count($recipients) * $length['parts']; ?></strong> SMS.
                <?php else: ?>
                  Mailen sendes til <strong><?php echo count($recipients); ?></strong> modtagere.
                  Hver enkelt får sin egen mail, så ingen kan se de andres adresser.
                <?php endif; ?>
                Det kan ikke fortrydes.
              </p>
              <details>
                <summary>Vis modtagere</summary>
                <ul class="rcpt">
                  <?php foreach ($recipients as $r): ?>
                    <li><?php echo $e($r['navn']); ?> &middot;
                        <?php echo $e($channel === 'sms' ? $r['msisdn'] : $r['email']); ?></li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <div class="actions">
                <button type="submit" name="step" value="send" class="danger"
                        <?php echo ($channel === 'sms' && !sms_enabled()) ? 'disabled' : ''; ?>>
                  Ja, send nu
                </button>
                <a class="cancel" href="besked.php">Annuller</a>
              </div>
            </div>
          <?php else: ?>
            <button type="submit" class="submit-btn">Gennemse og send</button>
          <?php endif; ?>
        </form>
      </div>

      <div class="panel">
        <h2>Sendte beskeder</h2>
        <?php if ($history === []): ?>
          <p class="muted">Der er ikke sendt nogen beskeder endnu.</p>
        <?php else: ?>
          <ul class="log">
            <?php foreach ($history as $h): ?>
              <li class="<?php echo $e($h['status']); ?>">
                <div class="log-top">
                  <span class="log-when"><?php echo $e($h['created_at']); ?></span>
                  <span>
                    <span class="tag kanal"><?php echo $h['channel'] === 'email' ? 'Mail' : 'SMS'; ?></span>
                    <span class="tag <?php echo $e($h['status']); ?>">
                      <?php echo $h['status'] === 'sendt' ? 'Sendt' : 'Fejlet'; ?>
                    </span>
                  </span>
                </div>
                <?php if ($h['subject'] !== ''): ?>
                  <p class="log-subject"><strong><?php echo $e($h['subject']); ?></strong></p>
                <?php endif; ?>
                <p class="log-body"><?php echo nl2br($e($h['body'])); ?></p>
                <p class="log-meta">
                  <?php echo (int)$h['ok_count']; ?> af <?php echo (int)$h['recipient_count']; ?> modtagere
                  <?php if ($h['channel'] === 'sms'): ?>
                    &middot; <?php echo (int)$h['parts']; ?> SMS hver
                  <?php endif; ?>
                  &middot; <?php echo $e($h['sent_by_name']); ?>
                  <?php if ($h['error'] !== ''): ?>
                    <br><span class="warn-text"><?php echo $e($h['error']); ?></span>
                  <?php endif; ?>
                </p>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <script>
    /* Tegntæller, der regner på samme måde som sms_length() i PHP:
       teksten er GSM-7, indtil der dukker et tegn op, som ikke findes der. */
    (function () {
      var box = document.getElementById('msgBody');
      var out = document.getElementById('counter');
      if (!box || !out) return;

      var GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
      var EXT = "^{}\[~]|€";

      function measure(text) {
        var units = 0, unicode = false;
        for (var ch of text) {
          if (GSM.indexOf(ch) !== -1) units++;
          else if (EXT.indexOf(ch) !== -1) units += 2;
          else { unicode = true; break; }
        }
        if (unicode) {
          // UTF-16 kodeenheder: emoji uden for BMP fylder to.
          units = text.length;
          return { units: units, parts: units <= 70 ? 1 : Math.ceil(units / 67), unicode: true };
        }
        return { units: units, parts: units <= 160 ? 1 : Math.ceil(units / 153), unicode: false };
      }

      function update() {
        var r = measure(box.value);
        out.innerHTML = r.units + ' tegn &middot; ' + r.parts + ' SMS pr. modtager' +
          (r.unicode ? ' &middot; <span class="warn-text">specialtegn — kun 70 tegn pr. SMS</span>' : '');
      }

      box.addEventListener('input', update);
      update();
    })();
  </script>
  <script>
    /* Emnefeltet hører kun til mail, og tegntælleren kun til SMS. */
    (function () {
      var form = document.getElementById('msgForm');
      if (!form) return;

      var subjectRow = document.getElementById('subjectRow');
      var subject = document.getElementById('msgSubject');
      var counter = document.getElementById('counter');
      var box = document.getElementById('msgBody');

      function channel() {
        var picked = form.querySelector('input[name="channel"]:checked');
        return picked ? picked.value : 'sms';
      }

      var info = document.getElementById('rcptInfo');

      function kunBestyrelse() {
        var valgt = form.querySelector('input[name="gruppe"]:checked');
        return valgt ? valgt.value === 'bestyrelse' : false;
      }

      function syncRecipients(isMail) {
        if (!info) return;
        var navn = (isMail ? 'data-mail' : 'data-sms') + (kunBestyrelse() ? '-best' : '');
        var n = parseInt(info.getAttribute(navn), 10) || 0;
        info.innerHTML = 'Sendes til <strong>' + n + '</strong> ' +
          (n === 1 ? 'modtager' : 'modtagere') +
          (isMail ? ' med mailadresse' : ' med telefonnummer') +
          (kunBestyrelse() ? ' i bestyrelsen.' : ' i beboerkartoteket.');
      }

      function sync() {
        var isMail = channel() === 'email';
        syncRecipients(isMail);
        if (subjectRow) subjectRow.classList.toggle("hidden-row", !isMail);
        if (subject) subject.required = isMail;
        if (counter) counter.hidden = isMail;
        if (box) {
          box.rows = isMail ? 10 : 5;
          box.maxLength = isMail ? 5000 : 1000;
        }
      }

      form.querySelectorAll('input[name="channel"], input[name="gruppe"]').forEach(function (el) {
        el.addEventListener('change', sync);
      });

      sync();
    })();
  </script>
  <footer>&copy; 2026 Grundejerforeningen Hesselbjerg Nord</footer>

  <script>
    const loginModal = document.getElementById('loginModal');
    const openLoginButtons = document.querySelectorAll('[data-open-login]');
    const closeLoginButtons = document.querySelectorAll('[data-close-login]');

    openLoginButtons.forEach((button) => {
      button.addEventListener('click', () => {
        loginModal.classList.add('open');
        loginModal.setAttribute('aria-hidden', 'false');
      });
    });

    closeLoginButtons.forEach((button) => {
      button.addEventListener('click', () => {
        loginModal.classList.remove('open');
        loginModal.setAttribute('aria-hidden', 'true');
      });
    });

    loginModal.addEventListener('click', (event) => {
      if (event.target === loginModal) {
        loginModal.classList.remove('open');
        loginModal.setAttribute('aria-hidden', 'true');
      }
    });

    const loginForm = loginModal.querySelector('form');
    const loginError = document.getElementById('loginError');

    // Fejlkoder fra login.php (bruges kun, når JavaScript er slået fra og
    // browseren er blevet sendt tilbage hertil med ?error=...).
    const LOGIN_ERRORS = {
      empty: 'Udfyld både brugernavn og adgangskode.',
      throttled: 'For mange forsøg. Vent et minut, og prøv igen.',
      auth: 'Forkert brugernavn eller adgangskode.',
      request: 'Ugyldig forespørgsel.',
    };

    function showLoginError(message) {
      loginError.textContent = message;
      loginError.classList.add('show');
    }

    // Navigationen tilpasses efter, om den besøgende er logget ind.
    fetch('session-status.php', { credentials: 'same-origin' })
      .then((response) => (response.ok ? response.json() : null))
      .then((status) => {
        if (!status || !status.loggedIn) {
          return;
        }


        // Tilmeldingen er til nye beboere — den skal ikke fylde i menuen,
        // når man allerede er logget ind.
        if (status.role === 'bestyrelse') {
          document.querySelectorAll('[data-board-only]').forEach((el) => { el.hidden = false; });
        }

        document.querySelectorAll('[data-guest-only]').forEach((el) => { el.hidden = true; });

        document.querySelectorAll('[data-members-only], [data-logout]').forEach((el) => {
          el.hidden = false;
        });

        document.querySelectorAll('[data-open-login]').forEach((el) => {
          el.hidden = true;
        });

        document.querySelectorAll('[data-user-name]').forEach((el) => {
          el.textContent = status.displayName || '';
          el.hidden = !status.displayName;
        });
      })
      .catch(() => {
        // Uden svar bliver menuen stående som "ikke logget ind".
      });

    loginForm.addEventListener('submit', (event) => {
      event.preventDefault();
      loginError.classList.remove('show');

      const submitButton = loginForm.querySelector('button[type="submit"]');
      submitButton.disabled = true;

      fetch('login.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: new FormData(loginForm),
      })
        .then((response) => response.json())
        .then((result) => {
          if (result.ok) {
            window.location.href = result.redirect || 'medlemsfotos.php';
            return;
          }

          submitButton.disabled = false;
          showLoginError(result.message || 'Login mislykkedes.');
        })
        .catch(() => {
          submitButton.disabled = false;
          showLoginError('Der kunne ikke oprettes forbindelse. Prøv igen.');
        });
    });

    // index.html?login=1 åbner loginvinduet med det samme — det er dertil,
    // medlemssider sender besøgende uden login.
    const params = new URLSearchParams(window.location.search);

    if (params.get('login') === '1') {
      loginModal.classList.add('open');
      loginModal.setAttribute('aria-hidden', 'false');

      const errorCode = params.get('error');

      if (errorCode) {
        showLoginError(LOGIN_ERRORS[errorCode] || 'Login mislykkedes. Prøv igen.');
      }

      const next = params.get('next');
      const nextField = loginForm.querySelector('input[name="next"]');

      if (next && /^[a-z0-9._-]+\.(php|html)$/i.test(next)) {
        nextField.value = next;
      }
    }
  </script>
</body>
</html>
