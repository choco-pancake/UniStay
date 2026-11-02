<?php
require __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail('Method not allowed.', 405);

$email = strtolower(trim((string)(body()['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address.');
if (strlen($email) > 190) fail('That email address is too long.');

$saved = false;
try {
  db()->exec('CREATE TABLE IF NOT EXISTS subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
  db()->prepare('INSERT INTO subscribers (email) VALUES (?) ON DUPLICATE KEY UPDATE email = email')->execute([$email]);
  $saved = true;
} catch (Throwable $e) {
  // Keep signups working even if MySQL is not running (e.g. XAMPP with only Apache started).
  $saved = save_subscriber_file($email);
}

if (!$saved) fail('We could not subscribe you right now. Please try again.', 500);

ok(['ok' => true, 'email' => $email]);

function save_subscriber_file(string $email): bool {
  $dir = __DIR__ . '/../data';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

  $guard = $dir . '/.htaccess';
  if (!is_file($guard)) {
    @file_put_contents($guard, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
  }

  $file = $dir . '/subscribers.csv';
  $handle = @fopen($file, 'c+');
  if (!$handle) return false;

  $saved = false;
  if (flock($handle, LOCK_EX)) {
    $contents = (string)stream_get_contents($handle);
    $exists = false;
    foreach (preg_split('/\r?\n/', $contents) as $line) {
      if ($line !== '' && strtolower(strtok($line, ',')) === $email) {
        $exists = true;
        break;
      }
    }
    if (!$exists) {
      fseek($handle, 0, SEEK_END);
      fwrite($handle, $email . ',' . date('c') . PHP_EOL);
    }
    fflush($handle);
    flock($handle, LOCK_UN);
    $saved = true;
  }

  fclose($handle);
  return $saved;
}
