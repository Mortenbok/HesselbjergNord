<?php
/**
 * Dokumentarkivet bag login — hele indholdet på generalforsamling.php.
 *
 * Arkivet har tre mapper: Dagsorden, Referat og Regnskab. De lå før på to
 * sider (generalforsamling.php og regnskab.php); nu er der én side med tre
 * mapper, og regnskab.php sender blot videre hertil.
 *
 * Filerne gemmes i uploads/dokumenter/ under et tilfældigt navn og serveres
 * kun gennem dokument.php, som kræver login — præcis som medlemsbillederne.
 */

/**
 * Mapperne i arkivet. Nøglen er den værdi, der ligger i databasens
 * category-kolonne; rækkefølgen her er den, mapperne vises i.
 *
 * Tilføjes en mappe, skal ENUM'en på documents.category udvides samme sted,
 * som den vedligeholdes: includes/db.php og database/schema.sql.
 */
const DOCUMENT_FOLDERS = [
    'dagsorden' => [
        'navn' => 'Dagsorden',
        'intro' => 'Indkaldelser og dagsordener til generalforsamlingerne.',
        'pladsholder' => 'Dagsorden til ordinær generalforsamling',
        'tom' => 'Der ligger endnu ingen dagsordener i mappen.',
    ],
    'referat' => [
        'navn' => 'Referat',
        'intro' => 'Referater fra de afholdte generalforsamlinger.',
        'pladsholder' => 'Referat af ordinær generalforsamling',
        'tom' => 'Der ligger endnu ingen referater i mappen.',
    ],
    'regnskab' => [
        'navn' => 'Regnskab',
        'intro' => 'Årsregnskaber og budgetter, som fremlægges på generalforsamlingen.',
        'pladsholder' => 'Årsregnskab',
        'tom' => 'Der ligger endnu ingen regnskaber i mappen.',
    ],
];

/** Største tilladte filstørrelse (PHP's egen upload_max_filesize kan være lavere). */
const DOCUMENT_MAX_BYTES = 20 * 1024 * 1024;

/**
 * Tilladte filtyper: MIME-type fra finfo => filendelse.
 *
 * Word- og Excel-filer er zip-arkiver indeni, og finfo melder dem derfor
 * nogle gange som application/zip. Derfor slås endelsen op i
 * DOCUMENT_ZIP_EXTENSIONS, når typen er zip.
 */
const DOCUMENT_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];

/** Endelser, der må accepteres, når finfo kun kan se, at filen er et zip-arkiv. */
const DOCUMENT_ZIP_EXTENSIONS = ['docx', 'xlsx'];

/** Mappen, dokumenterne ligger i. Spærret udefra af uploads/.htaccess. */
function documents_dir(): string
{
    return dirname(__DIR__) . '/uploads/dokumenter';
}

/** Kun bestyrelsen må lægge dokumenter op og fjerne dem igen. */
function documents_can_manage(?array $user): bool
{
    return is_array($user) && ($user['role'] ?? '') === 'bestyrelse';
}

/** Er værdien en mappe, arkivet kender? */
function documents_valid_folder(?string $folder): bool
{
    return is_string($folder) && isset(DOCUMENT_FOLDERS[$folder]);
}

/** Læselig filstørrelse, fx "1,4 MB". */
function documents_format_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    }

    return $bytes . ' B';
}

/** Kort tekst til ikonet ud fra filendelsen. */
function documents_kind(string $originalName): string
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    return $ext !== '' ? strtoupper($ext) : 'FIL';
}

/**
 * Håndterer POST fra arkivsiden — upload eller sletning.
 *
 * Returnerer ['error' => string, 'success' => string, 'folder' => string].
 * 'folder' fortæller, hvilken mappe siden skal åbne bagefter, så man bliver
 * stående i den mappe, man arbejdede i.
 */
function documents_handle_post(PDO $pdo, array $user): array
{
    $result = ['error' => '', 'success' => '', 'folder' => ''];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $result;
    }

    if (!documents_can_manage($user)) {
        $result['error'] = 'Kun bestyrelsen kan ændre i arkivet.';
        return $result;
    }

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $result['error'] = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
        return $result;
    }

    $folder = (string)($_POST['folder'] ?? '');

    if (!documents_valid_folder($folder)) {
        $result['error'] = 'Ukendt mappe.';
        return $result;
    }

    $result['folder'] = $folder;

    $outcome = ($_POST['action'] ?? '') === 'delete'
        ? documents_delete($pdo, $folder, (int)($_POST['id'] ?? 0))
        : documents_upload($pdo, $user, $folder);

    return $outcome + ['folder' => $folder];
}

/** Fjerner ét dokument — både databaserækken og selve filen. */
function documents_delete(PDO $pdo, string $folder, int $id): array
{
    if ($id <= 0) {
        return ['error' => 'Dokumentet blev ikke fundet.', 'success' => ''];
    }

    // Mappen er en del af opslaget, så en manipuleret formular ikke kan slette
    // et dokument fra en anden mappe end den, knappen stod i.
    $stmt = $pdo->prepare('SELECT file_name FROM documents WHERE id = :id AND category = :folder LIMIT 1');
    $stmt->execute([':id' => $id, ':folder' => $folder]);
    $document = $stmt->fetch();

    if (!$document) {
        return ['error' => 'Dokumentet blev ikke fundet.', 'success' => ''];
    }

    $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $id]);

    $path = documents_dir() . '/' . basename($document['file_name']);

    if (is_file($path)) {
        unlink($path);
    }

    return ['error' => '', 'success' => 'Dokumentet er fjernet.'];
}

/** Modtager en uploadet fil og gemmer den i arkivet. */
function documents_upload(PDO $pdo, array $user, string $folder): array
{
    if (!isset($_FILES['document'])) {
        return ['error' => 'Vælg en fil, før du uploader.', 'success' => ''];
    }

    $file = $_FILES['document'];

    if (is_array($file['name'])) {
        return ['error' => 'Upload ét dokument ad gangen.', 'success' => ''];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'Filen er for stor.'
            : 'Uploaden mislykkedes. Prøv igen.';

        return ['error' => $message, 'success' => ''];
    }

    if ($file['size'] <= 0 || $file['size'] > DOCUMENT_MAX_BYTES) {
        return [
            'error' => 'Filen skal fylde mellem 0 og ' . (DOCUMENT_MAX_BYTES / (1024 * 1024)) . ' MB.',
            'success' => '',
        ];
    }

    $originalName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (isset(DOCUMENT_TYPES[$mime])) {
        $storedExtension = DOCUMENT_TYPES[$mime];
    } elseif ($mime === 'application/zip' && in_array($extension, DOCUMENT_ZIP_EXTENSIONS, true)) {
        // Word/Excel-filer, som finfo kun kan se er zip-arkiver.
        $storedExtension = $extension;
        $mime = $extension === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } else {
        return ['error' => 'Kun PDF, Word, Excel og billeder er tilladt.', 'success' => ''];
    }

    $title = trim((string)($_POST['title'] ?? ''));

    if ($title === '') {
        // Uden titel bruges filnavnet uden endelse.
        $title = pathinfo($file['name'], PATHINFO_FILENAME);
    }

    // mbstring er ikke slået til overalt, derfor faldet tilbage til substr().
    $title = function_exists('mb_substr')
        ? mb_substr($title, 0, 200)
        : substr($title, 0, 200);

    $year = (int)($_POST['doc_year'] ?? 0);

    if ($year < 1970 || $year > (int)date('Y') + 1) {
        $year = (int)date('Y');
    }

    $dir = documents_dir();

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['error' => 'Mappen til dokumenter kunne ikke oprettes.', 'success' => ''];
    }

    // Filnavnet kan ikke gættes — filerne serveres alligevel kun gennem
    // dokument.php, som kræver login.
    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $storedExtension;
    $targetPath = $dir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['error' => 'Filen kunne ikke gemmes på serveren.', 'success' => ''];
    }

    chmod($targetPath, 0644);

    $stmt = $pdo->prepare(
        'INSERT INTO documents
            (category, doc_year, title, file_name, original_name, mime_type, file_size,
             uploaded_by, uploader_name, created_at)
         VALUES
            (:folder, :doc_year, :title, :file_name, :original_name, :mime_type, :file_size,
             :uploaded_by, :uploader_name, NOW())'
    );
    $stmt->execute([
        ':folder' => $folder,
        ':doc_year' => $year,
        ':title' => $title,
        ':file_name' => $safeName,
        ':original_name' => $originalName,
        ':mime_type' => $mime,
        ':file_size' => (int)$file['size'],
        ':uploaded_by' => $user['id'],
        ':uploader_name' => $user['display_name'] !== '' ? $user['display_name'] : 'Bestyrelsen',
    ]);

    return ['error' => '', 'success' => 'Dokumentet er lagt i mappen ' . DOCUMENT_FOLDERS[$folder]['navn'] . '.'];
}

/**
 * Hele arkivet: mappe => år => dokumenter.
 *
 * Alle mapper kommer med, også de tomme, så siden kan vise dem.
 */
function documents_list(PDO $pdo): array
{
    $arkiv = [];

    foreach (array_keys(DOCUMENT_FOLDERS) as $folder) {
        $arkiv[$folder] = [];
    }

    $rows = $pdo->query(
        'SELECT id, category, doc_year, title, original_name, mime_type, file_size,
                uploader_name, created_at
           FROM documents
          ORDER BY doc_year DESC, created_at DESC, id DESC'
    )->fetchAll();

    foreach ($rows as $row) {
        // En række med en ukendt mappe (fx fra en ældre udgave) springes over,
        // så siden ikke går i stykker af den.
        if (!documents_valid_folder($row['category'])) {
            continue;
        }

        $arkiv[$row['category']][(int)$row['doc_year']][] = $row;
    }

    return $arkiv;
}

/** Kort for htmlspecialchars med sidens faste indstillinger. */
function documents_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Skriver hele arkivsiden ud.
 *
 * $arkiv kommer fra documents_list(). Databasen hentes altså ikke herinde —
 * så kan siden gengives uden en databaseforbindelse, fx under afprøvning.
 */
function documents_render_page(array $arkiv, array $user, array $message): void
{
    $canManage = documents_can_manage($user);
    $csrf = auth_csrf_token();
    $currentYear = (int)date('Y');
    $e = 'documents_e';

    // Efter en upload eller sletning åbnes den mappe, der blev arbejdet i.
    // Ellers åbnes den mappe, adressen beder om (?mappe=referat), og ellers
    // den første.
    $aaben = $message['folder'] ?? '';

    if (!documents_valid_folder($aaben)) {
        $aaben = (string)($_GET['mappe'] ?? '');
    }

    if (!documents_valid_folder($aaben)) {
        $aaben = array_key_first(DOCUMENT_FOLDERS);
    }
    ?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Generalforsamling — Grundejerforeningen Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="favicon.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    min-height: 100%;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }
  body {
    background: url('beach-bg.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
    color: #fff;
  }
  body::before {
    content: "";
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
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
  nav a, .login-btn {
    color: #fff;
    text-decoration: none;
    font-size: 0.95rem;
    letter-spacing: 1px;
    padding: 8px 16px;
    border-radius: 20px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
    transition: background 0.25s ease;
  }
  nav a:hover, .login-btn:hover, nav a.active {
    background: rgba(255,255,255,0.18);
  }
  .nav-account {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-right: 10px;
    white-space: nowrap;
  }
  .nav-user {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }
  .login-btn {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.4);
    white-space: nowrap;
  }
  .container {
    position: relative;
    z-index: 1;
    max-width: 1000px;
    margin: 0 auto;
    padding: 30px 20px 80px;
  }
  .panel {
    background: rgba(10, 20, 25, 0.42);
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    backdrop-filter: blur(4px);
  }
  h1 {
    font-size: clamp(2rem, 4vw, 2.8rem);
    margin-bottom: 12px;
  }
  .lead {
    font-weight: 300;
    color: rgba(255,255,255,0.9);
    max-width: 62ch;
    line-height: 1.55;
  }

  /* Mapperne. Uden JavaScript står alle tre under hinanden — se scriptet
     nederst, der laver dem om til faner. */
  .mapper {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 26px;
    padding-bottom: 16px;
    border-bottom: 1px solid rgba(255,255,255,0.16);
  }
  .mappe-knap {
    font: inherit;
    color: #fff;
    cursor: pointer;
    padding: 10px 20px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.06);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background 0.2s ease, border-color 0.2s ease;
  }
  .mappe-knap:hover {
    background: rgba(150, 210, 255, 0.18);
    border-color: rgba(150, 210, 255, 0.5);
  }
  .mappe-knap[aria-selected="true"] {
    background: rgba(150, 210, 255, 0.26);
    border-color: rgba(150, 210, 255, 0.7);
    font-weight: 600;
  }
  .mappe-knap .antal {
    font-size: 0.78rem;
    color: rgba(255,255,255,0.65);
    background: rgba(0,0,0,0.3);
    border-radius: 20px;
    padding: 2px 9px;
  }

  .mappe { margin-top: 26px; }
  .mappe > h2 {
    font-size: 1.4rem;
    margin-bottom: 6px;
  }
  .mappe > .mappe-intro {
    font-weight: 300;
    color: rgba(255,255,255,0.75);
    max-width: 62ch;
    line-height: 1.5;
  }

  /* Uploadfelterne — kun synlige for bestyrelsen. */
  .upload {
    margin-top: 22px;
    padding-top: 20px;
    border-top: 1px solid rgba(255,255,255,0.16);
  }
  .upload h3 {
    font-size: 1rem;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: rgba(150, 210, 255, 0.95);
    margin-bottom: 14px;
  }
  .form-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
  }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field label {
    font-size: 0.82rem;
    color: rgba(255,255,255,0.65);
  }
  input, button, select { font: inherit; }
  input[type="text"], input[type="number"], input[type="file"] {
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.08);
    color: #fff;
  }
  input[type="text"] { min-width: 260px; }
  input[type="number"] { width: 110px; }
  input[type="file"] { max-width: 300px; }
  button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    padding: 10px 20px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    cursor: pointer;
  }
  .hint {
    margin-top: 10px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.6);
  }
  .message {
    margin-top: 20px;
    font-weight: 600;
  }
  .message.error { color: #ffd3d3; }
  .message.success { color: #d7ffd7; }

  /* Dokumenterne, grupperet efter år. */
  .archive { margin-top: 26px; }
  .year {
    font-size: 1.15rem;
    margin: 24px 0 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid rgba(255,255,255,0.16);
  }
  .year:first-of-type { margin-top: 0; }
  .doc {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 16px;
    margin-bottom: 10px;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.14);
    border-radius: 12px;
  }
  .doc-kind {
    flex: 0 0 auto;
    width: 52px;
    height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.22);
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
  }
  .doc-body { flex: 1; min-width: 0; }
  .doc-title {
    font-size: 1.02rem;
    font-weight: 600;
    margin-bottom: 3px;
  }
  .doc-title a { color: #fff; text-decoration: none; }
  .doc-title a:hover { text-decoration: underline; }
  .doc-meta {
    font-size: 0.85rem;
    color: rgba(255,255,255,0.62);
  }
  .doc-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
  }
  .doc-actions a {
    color: #cfe9ff;
    text-decoration: none;
    font-size: 0.9rem;
    white-space: nowrap;
  }
  .doc-actions a:hover { text-decoration: underline; }
  .doc-actions button {
    padding: 6px 12px;
    font-size: 0.85rem;
    border-color: rgba(255,140,140,0.5);
    color: #ffd3d3;
  }
  .empty {
    margin-top: 24px;
    color: rgba(255,255,255,0.8);
  }
  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 10px 10px 30px;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.8);
    text-shadow: 0 1px 4px rgba(0,0,0,0.6);
  }

  @media (max-width: 560px) {
    .doc { flex-wrap: wrap; }
    .doc-actions { width: 100%; justify-content: flex-end; }
    input[type="text"] { min-width: 0; width: 100%; }
    .field { width: 100%; }
    .mappe-knap { flex: 1 1 auto; justify-content: center; }
  }
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
      <a href="vedtaegter.php">Vedtægter</a>
      <a href="medlemsfotos.php">Medlemsfotos</a>
      <a href="generalforsamling.php" class="active">Generalforsamling</a>
      <?php if (($user['role'] ?? '') === 'bestyrelse'): ?>
        <a href="besked.php">Beskeder</a>
        <a href="admin.php">Admin</a>
      <?php endif; ?>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo $e($user['display_name']); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Generalforsamling</h1>
      <p class="lead">Foreningens dagsordener, referater og regnskaber ligger her
        i hver sin mappe. Arkivet er kun tilgængeligt for medlemmer, der er
        logget ind.</p>

      <?php if ($message['error'] !== ''): ?>
        <div class="message error"><?php echo $e($message['error']); ?></div>
      <?php elseif ($message['success'] !== ''): ?>
        <div class="message success"><?php echo $e($message['success']); ?></div>
      <?php endif; ?>

      <div class="mapper" role="tablist" aria-label="Mapper i arkivet">
        <?php foreach (DOCUMENT_FOLDERS as $noegle => $mappe): ?>
          <?php
            $antal = 0;
            foreach ($arkiv[$noegle] as $raekker) {
                $antal += count($raekker);
            }
          ?>
          <button type="button"
                  class="mappe-knap"
                  role="tab"
                  id="fane-<?php echo $e($noegle); ?>"
                  data-mappe="<?php echo $e($noegle); ?>"
                  aria-controls="mappe-<?php echo $e($noegle); ?>"
                  aria-selected="<?php echo $noegle === $aaben ? 'true' : 'false'; ?>">
            <?php echo $e($mappe['navn']); ?>
            <span class="antal"><?php echo $antal; ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <?php foreach (DOCUMENT_FOLDERS as $noegle => $mappe): ?>
        <section class="mappe"
                 id="mappe-<?php echo $e($noegle); ?>"
                 role="tabpanel"
                 aria-labelledby="fane-<?php echo $e($noegle); ?>"
                 data-mappe="<?php echo $e($noegle); ?>">
          <h2><?php echo $e($mappe['navn']); ?></h2>
          <p class="mappe-intro"><?php echo $e($mappe['intro']); ?></p>

          <?php if ($canManage): ?>
            <div class="upload">
              <h3>Læg et dokument i <?php echo $e($mappe['navn']); ?></h3>
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="folder" value="<?php echo $e($noegle); ?>">
                <div class="form-grid">
                  <div class="field">
                    <label for="title-<?php echo $e($noegle); ?>">Titel</label>
                    <input id="title-<?php echo $e($noegle); ?>" type="text" name="title"
                           maxlength="200" placeholder="<?php echo $e($mappe['pladsholder']); ?>">
                  </div>
                  <div class="field">
                    <label for="aar-<?php echo $e($noegle); ?>">År</label>
                    <input id="aar-<?php echo $e($noegle); ?>" type="number" name="doc_year"
                           min="1970" max="<?php echo $currentYear + 1; ?>"
                           value="<?php echo $currentYear; ?>">
                  </div>
                  <div class="field">
                    <label for="fil-<?php echo $e($noegle); ?>">Fil</label>
                    <input id="fil-<?php echo $e($noegle); ?>" type="file" name="document"
                           accept=".pdf,.docx,.xlsx,.jpg,.jpeg,.png,.webp" required>
                  </div>
                  <button type="submit">Upload</button>
                </div>
              </form>
              <p class="hint">PDF, Word, Excel eller billede — højst <?php echo (int)(DOCUMENT_MAX_BYTES / (1024 * 1024)); ?> MB. Uden titel bruges filnavnet.</p>
            </div>
          <?php endif; ?>

          <?php if (!$arkiv[$noegle]): ?>
            <p class="empty"><?php echo $e($mappe['tom']); ?></p>
          <?php else: ?>
            <div class="archive">
              <?php foreach ($arkiv[$noegle] as $aar => $raekker): ?>
                <h3 class="year"><?php echo (int)$aar; ?></h3>
                <?php foreach ($raekker as $doc): ?>
                  <div class="doc">
                    <div class="doc-kind"><?php echo $e(documents_kind($doc['original_name'])); ?></div>
                    <div class="doc-body">
                      <p class="doc-title">
                        <a href="dokument.php?id=<?php echo (int)$doc['id']; ?>">
                          <?php echo $e($doc['title']); ?>
                        </a>
                      </p>
                      <p class="doc-meta">
                        <?php echo $e(documents_format_size((int)$doc['file_size'])); ?>
                        &middot; lagt op <?php echo $e(date('d-m-Y', strtotime($doc['created_at']))); ?>
                        <?php if ($doc['uploader_name'] !== ''): ?>
                          af <?php echo $e($doc['uploader_name']); ?>
                        <?php endif; ?>
                      </p>
                    </div>
                    <div class="doc-actions">
                      <a href="dokument.php?id=<?php echo (int)$doc['id']; ?>&amp;download=1">Hent</a>
                      <?php if ($canManage): ?>
                        <form method="post"
                              onsubmit="return confirm('Fjern dokumentet fra arkivet?');">
                          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="folder" value="<?php echo $e($noegle); ?>">
                          <input type="hidden" name="id" value="<?php echo (int)$doc['id']; ?>">
                          <button type="submit">Fjern</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </div>

  <footer>&copy; <?php echo date('Y'); ?> Grundejerforeningen Hesselbjerg Nord</footer>

  <script>
  (function () {
    'use strict';

    // Uden JavaScript står alle tre mapper under hinanden, og siden virker
    // fint — derfor skjules de først her.
    var knapper = document.querySelectorAll('.mappe-knap');
    var mapper = document.querySelectorAll('.mappe');

    if (!knapper.length) return;

    function vis(navn) {
      mapper.forEach(function (m) { m.hidden = m.dataset.mappe !== navn; });
      knapper.forEach(function (k) {
        k.setAttribute('aria-selected', k.dataset.mappe === navn ? 'true' : 'false');
      });

      // Adressen følger med, så et genindlæst vindue åbner samme mappe.
      if (window.history.replaceState) {
        window.history.replaceState(null, '', '?mappe=' + encodeURIComponent(navn));
      }
    }

    knapper.forEach(function (k) {
      k.addEventListener('click', function () { vis(k.dataset.mappe); });
    });

    var valgt = document.querySelector('.mappe-knap[aria-selected="true"]');
    vis(valgt ? valgt.dataset.mappe : knapper[0].dataset.mappe);
  })();
  </script>
</body>
</html>
    <?php
}
