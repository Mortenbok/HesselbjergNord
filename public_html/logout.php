<?php
require __DIR__ . '/includes/auth.php';

auth_logout();

header('Location: index.html', true, 302);
exit;
