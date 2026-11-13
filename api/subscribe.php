<?php
require __DIR__ . '/db.php';
require_once __DIR__ . '/../config/subscribers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') fail('Method not allowed.', 405);

$input = body();
$email = subscriber_norm_email((string) ($input['email'] ?? ''));

if (!subscriber_is_valid_email($email)) fail('Please enter a valid email address.');

// Hidden field: only bots fill it in. Pretend the signup worked.
if (trim((string) ($input['website'] ?? '')) !== '') {
  ok(['ok' => true, 'email' => $email]);
}

// Reject cross-site form posts when the browser tells us where it came from.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
  $originHost = parse_url($origin, PHP_URL_HOST);
  $requestHost = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));

  if (!is_string($originHost) || strcasecmp($originHost, (string) $requestHost) !== 0) {
    fail('This request could not be verified.', 403);
  }
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

try {
  $pdo = db();
  subscribers_ensure_schema($pdo);

  if (subscriber_rate_limited($pdo, $ip)) {
    fail('Too many subscribe attempts. Please try again in a few minutes.', 429);
  }

  $result = subscriber_upsert($pdo, $email, [
    'source' => 'landing_footer',
    'ip' => $ip,
    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
  ]);

  $mailStatus = subscriber_after_subscribe($pdo, $result);

  ok([
    'ok' => true,
    'email' => $email,
    'mail_sent' => $mailStatus === 'sent',
    'mail_status' => $mailStatus,
  ]);
} catch (Throwable $e) {
  // Keep signups working even if MySQL is not running (e.g. XAMPP with only Apache started).
  error_log('UniStay subscribe failed: ' . $e->getMessage());

  if (save_subscriber_file($email)) {
    ok([
      'ok' => true,
      'email' => $email,
      'mail_sent' => false,
      'mail_status' => 'failed',
    ]);
  }

  fail('We could not subscribe you right now. Please try again.', 500);
}

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
