<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';

// Alt herunder kræver login — besøgende uden session sendes til forsiden,
// hvor loginvinduet åbner automatisk.
auth_require('index.html');

$user = auth_user();
$csrf = auth_csrf_token();

$uploadError = '';
$uploadSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];

    if (!auth_csrf_valid($_POST['csrf_token'] ?? null)) {
        $uploadError = 'Sessionen er udløbet. Genindlæs siden, og prøv igen.';
    } elseif (is_array($file['name'])) {
        $uploadError = 'Upload ét billede ad gangen.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'Billedet er for stort.'
            : 'Uploaden mislykkedes. Prøv igen.';
    } elseif ($file['size'] <= 0 || $file['size'] > 8 * 1024 * 1024) {
        $uploadError = 'Billedet skal fylde mellem 0 og 8 MB.';
    } else {
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            $uploadError = 'Kun JPG, PNG og WebP filer er tilladt.';
        } else {
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0775, true);
            }

            $originalName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($file['name']));
            // Filnavnet kan ikke gættes — billederne serveres alligevel kun
            // gennem photo.php, som kræver login.
            $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            $targetPath = $uploadsDir . '/' . $safeName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                chmod($targetPath, 0644);

                $stmt = $pdo->prepare(
                    'INSERT INTO member_photos (user_id, member_name, file_name, original_name, mime_type, created_at)
                     VALUES (:user_id, :member_name, :file_name, :original_name, :mime_type, NOW())'
                );
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':member_name' => $user['display_name'] !== '' ? $user['display_name'] : 'Medlem',
                    ':file_name' => $safeName,
                    ':original_name' => $originalName,
                    ':mime_type' => $mime,
                ]);

                $uploadSuccess = 'Billedet er uploadet.';
            } else {
                $uploadError = 'Billedet kunne ikke gemmes på serveren.';
            }
        }
    }
}

$stmt = $pdo->query(
    'SELECT id, member_name, original_name, created_at
       FROM member_photos
      ORDER BY created_at DESC, id DESC'
);
$photos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="da">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Medlemsfotos — Hesselbjerg Nord</title>
<link rel="icon" type="image/jpeg" href="beach-bg.jpg">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    height: 100%;
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
    background: rgba(0, 0, 0, 0.38);
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
    max-width: 1100px;
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
  .form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 20px;
  }
  input, button {
    font: inherit;
  }
  input[type="file"] {
    width: 100%;
    max-width: 280px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.28);
    background: rgba(255,255,255,0.08);
    color: #fff;
  }
  button {
    background: rgba(255,255,255,0.12);
    color: #fff;
    padding: 10px 20px;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 10px;
    cursor: pointer;
  }
  .message {
    margin-top: 12px;
    font-weight: 600;
  }
  .message.error { color: #ffd3d3; }
  .message.success { color: #d7ffd7; }
  .empty {
    margin-top: 30px;
    color: rgba(255,255,255,0.8);
  }
  .gallery {
    margin-top: 40px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
  }
  .photo-card {
    background: rgba(255,255,255,0.05);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid rgba(255,255,255,0.14);
  }
  .photo-card img {
    width: 100%;
    height: 220px;
    object-fit: cover;
    display: block;
  }
  .photo-meta {
    padding: 10px 12px 14px;
    font-size: 0.9rem;
    color: rgba(255,255,255,0.85);
  }
  footer {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 20px 10px 30px;
    color: rgba(255,255,255,0.8);
  }
</style>
</head>
<body>
  <nav>
    <div class="nav-links">
      <a href="index.html">Forside</a>
      <a href="vedtaegter.html">Vedtægter</a>
      <a href="bestyrelsen.html">Bestyrelsen</a>
      <a href="kontingent.html">Kontingent</a>
      <a href="aktiviteter.html">Aktiviteter</a>
      <a href="hjertestarter.html">Hjertestarter</a>
      <a href="medlemsfotos.php" class="active">Medlemsfotos</a>
    </div>
    <div class="nav-account">
      <span class="nav-user">Logget ind som <?php echo htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8'); ?></span>
      <a href="logout.php" class="login-btn">Log ud</a>
    </div>
  </nav>

  <div class="container">
    <div class="panel">
      <h1>Medlemsfotos</h1>
      <p>Upload dine egne billeder til medlemsgalleriet. Galleriet er kun synligt for medlemmer, der er logget ind.</p>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-row">
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
          <button type="submit">Upload billede</button>
        </div>
      </form>

      <?php if ($uploadError !== ''): ?>
        <div class="message error"><?php echo htmlspecialchars($uploadError, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php elseif ($uploadSuccess !== ''): ?>
        <div class="message success"><?php echo htmlspecialchars($uploadSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <?php if (!$photos): ?>
        <p class="empty">Der er ikke uploadet billeder endnu. Bliv den første.</p>
      <?php else: ?>
        <div class="gallery">
          <?php foreach ($photos as $photo): ?>
            <div class="photo-card">
              <img src="photo.php?id=<?php echo (int)$photo['id']; ?>"
                   alt="<?php echo htmlspecialchars($photo['original_name'], ENT_QUOTES, 'UTF-8'); ?>"
                   loading="lazy">
              <div class="photo-meta">
                <strong><?php echo htmlspecialchars($photo['member_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                <?php echo htmlspecialchars(date('d-m-Y', strtotime($photo['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <footer>&copy; 2026 Hesselbjerg Nord</footer>
</body>
</html>
