<?php

/**
 * Newsletter subscribers (footer form on the landing page).
 *
 * Subscribers live in their own table and never touch the tenants, landlords,
 * or admins tables. The table starts with just an email address; this file
 * upgrades it in place with the delivery state that the mailer needs, so an
 * existing database keeps working without a separate migration step.
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_template.php';

const SUBSCRIBER_TOKEN_BYTES = 32;

function subscriber_norm_email(string $email): string
{
    return strtolower(trim($email));
}

function subscriber_is_valid_email(string $email): bool
{
    return $email !== ''
        && strlen($email) <= 190
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function subscriber_token(): string
{
    return bin2hex(random_bytes(SUBSCRIBER_TOKEN_BYTES));
}

function subscriber_double_opt_in(): bool
{
    $settings = mail_settings();

    // Default to false so landing page subscribers receive instant promotional ads upon entering their email
    return (bool) ($settings['double_opt_in'] ?? false);
}

function subscriber_privacy_url(): string
{
    return app_url('pages/privacy-policy.php');
}

function subscriber_logo_url(): string
{
    return app_url('assets/images/logo.png');
}

/**
 * Creates or upgrades the subscriber tables. Runs at most once per request.
 */
function subscribers_ensure_schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscribers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM subscribers') as $column) {
        $columns[strtolower((string) $column['Field'])] = true;
    }

    $additions = [
        'status' => "ADD COLUMN status ENUM('pending','active','unsubscribed','bounced') NOT NULL DEFAULT 'active' AFTER email",
        'source' => "ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'landing_footer'",
        'confirm_token' => 'ADD COLUMN confirm_token CHAR(64) NULL',
        'confirm_expires_at' => 'ADD COLUMN confirm_expires_at DATETIME NULL',
        'unsubscribe_token' => 'ADD COLUMN unsubscribe_token CHAR(64) NULL',
        'ip_address' => 'ADD COLUMN ip_address VARCHAR(45) NULL',
        'user_agent' => 'ADD COLUMN user_agent VARCHAR(255) NULL',
        'consent_at' => 'ADD COLUMN consent_at DATETIME NULL',
        'confirmed_at' => 'ADD COLUMN confirmed_at DATETIME NULL',
        'unsubscribed_at' => 'ADD COLUMN unsubscribed_at DATETIME NULL',
        'last_sent_at' => 'ADD COLUMN last_sent_at DATETIME NULL',
    ];

    $addedStatus = false;

    foreach ($additions as $name => $sql) {
        if (isset($columns[$name])) {
            continue;
        }

        $pdo->exec('ALTER TABLE subscribers ' . $sql);

        if ($name === 'status') {
            $addedStatus = true;
        }
    }

    if ($addedStatus) {
        // Existing rows were collected before double opt-in existed: keep them
        // subscribed instead of turning them into pending sign-ups.
        $pdo->exec("ALTER TABLE subscribers ALTER COLUMN status SET DEFAULT 'pending'");
        $pdo->exec('UPDATE subscribers SET confirmed_at = created_at WHERE status = \'active\' AND confirmed_at IS NULL');
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM subscribers') as $index) {
        $indexes[(string) $index['Key_name']] = true;
    }

    if (!isset($indexes['idx_subscribers_status'])) {
        $pdo->exec('ALTER TABLE subscribers ADD INDEX idx_subscribers_status (status)');
    }

    if (!isset($indexes['uniq_subscribers_unsubscribe_token'])) {
        $pdo->exec('ALTER TABLE subscribers ADD UNIQUE INDEX uniq_subscribers_unsubscribe_token (unsubscribe_token)');
    }

    // Keep existing installations self-healing. Older databases may have the
    // subscribers table but not the delivery tables introduced with mailouts.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscriber_id INT NULL,
            campaign_id INT NULL,
            template VARCHAR(32) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
            attempts TINYINT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            provider_message_id VARCHAR(191) NULL,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_queue_status (status, scheduled_at),
            INDEX idx_email_queue_campaign (campaign_id),
            INDEX idx_email_queue_subscriber (subscriber_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscriber_id INT NULL,
            email VARCHAR(190) NOT NULL,
            template VARCHAR(32) NOT NULL,
            status VARCHAR(16) NOT NULL,
            provider_message_id VARCHAR(191) NULL,
            error VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_logs_email (email),
            INDEX idx_email_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            heading VARCHAR(255) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            cta_text VARCHAR(80) NOT NULL DEFAULT 'Explore UniStay',
            cta_url VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('draft','sending','sent') NOT NULL DEFAULT 'draft',
            created_by VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $createdBy = $pdo->query("SHOW COLUMNS FROM campaigns LIKE 'created_by'")->fetch();
    if ($createdBy && stripos((string)$createdBy['Type'], 'int') !== false) {
        $pdo->exec('ALTER TABLE campaigns MODIFY created_by VARCHAR(20) NULL');
    }

    $done = true;
}

/**
 * Returns true when this IP address has used up its subscribe attempts.
 */
function subscriber_rate_limited(PDO $pdo, string $ip, int $max = 5, int $minutes = 10): bool
{
    if ($ip === '') {
        return false;
    }

    $minutes = max(1, $minutes);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (!isset($_SESSION['sub_attempts']) || !is_array($_SESSION['sub_attempts'])) {
        $_SESSION['sub_attempts'] = [];
    }

    $now = time();
    $cutoff = $now - ($minutes * 60);
    $_SESSION['sub_attempts'] = array_filter($_SESSION['sub_attempts'], fn($t) => is_numeric($t) && $t > $cutoff);

    if (count($_SESSION['sub_attempts']) >= $max) {
        return true;
    }

    $_SESSION['sub_attempts'][] = $now;
    return false;
}

function subscriber_find_by_email(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subscribers WHERE email = ? LIMIT 1');
    $stmt->execute([subscriber_norm_email($email)]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function subscriber_find_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM subscribers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function subscriber_find_by_token(PDO $pdo, string $column, string $token): ?array
{
    if (!in_array($column, ['confirm_token', 'unsubscribe_token'], true) || strlen($token) < 32) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM subscribers WHERE {$column} = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Stores a subscriber. Re-subscribing reactivates a previous subscriber, and an
 * already active address stays active (no fresh confirmation mail every time).
 *
 * @return array{subscriber:array,is_new:bool,needs_confirmation:bool,send_welcome:bool}
 */
function subscriber_upsert(PDO $pdo, string $email, array $meta = []): array
{
    $email = subscriber_norm_email($email);
    $source = substr(trim((string) ($meta['source'] ?? 'landing_footer')), 0, 40);
    $ip = substr(trim((string) ($meta['ip'] ?? '')), 0, 45);
    $userAgent = substr(trim((string) ($meta['user_agent'] ?? '')), 0, 255);
    $desiredStatus = subscriber_double_opt_in() ? 'pending' : 'active';
    $expires = date('Y-m-d H:i:s', time() + 48 * 3600);

    $existing = subscriber_find_by_email($pdo, $email);

    if ($existing === null) {
        $token = $desiredStatus === 'pending' ? subscriber_token() : null;

        $stmt = $pdo->prepare(
            'INSERT INTO subscribers
                (email, status, source, ip_address, user_agent, consent_at,
                 confirm_token, confirm_expires_at, confirmed_at)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?)'
        );
        $stmt->execute([
            $email,
            $desiredStatus,
            $source,
            $ip !== '' ? $ip : null,
            $userAgent !== '' ? $userAgent : null,
            $token,
            $token !== null ? $expires : null,
            $desiredStatus === 'active' ? date('Y-m-d H:i:s') : null,
        ]);

        $subscriber = subscriber_find_by_email($pdo, $email);

        return [
            'subscriber' => $subscriber ?? [],
            'is_new' => true,
            'needs_confirmation' => $desiredStatus === 'pending',
            'send_welcome' => $desiredStatus === 'active',
        ];
    }

    $wasActive = (string) $existing['status'] === 'active';
    $status = $wasActive ? 'active' : $desiredStatus;
    $needsConfirmation = $status === 'pending';
    $token = $needsConfirmation ? subscriber_token() : null;

    $stmt = $pdo->prepare(
        "UPDATE subscribers
            SET status = ?,
                source = ?,
                ip_address = ?,
                user_agent = ?,
                consent_at = NOW(),
                unsubscribed_at = NULL,
                confirm_token = ?,
                confirm_expires_at = ?,
                confirmed_at = IF(? = 'active', COALESCE(confirmed_at, NOW()), confirmed_at)
          WHERE id = ?"
    );
    $stmt->execute([
        $status,
        $source,
        $ip !== '' ? $ip : null,
        $userAgent !== '' ? $userAgent : null,
        $token,
        $token !== null ? $expires : null,
        $status,
        (int) $existing['id'],
    ]);

    $subscriber = subscriber_find_by_id($pdo, (int) $existing['id']) ?? [];

    return [
        'subscriber' => $subscriber,
        'is_new' => false,
        'needs_confirmation' => $needsConfirmation,
        'send_welcome' => $status === 'active' && !$wasActive,
    ];
}

function subscriber_unsubscribe_url(PDO $pdo, array $subscriber): string
{
    // Preview/test messages are rendered without a real subscriber row.
    if ((int) ($subscriber['id'] ?? 0) <= 0) {
        return '';
    }

    $token = trim((string) ($subscriber['unsubscribe_token'] ?? ''));

    if ($token === '') {
        $token = subscriber_token();
        $pdo->prepare('UPDATE subscribers SET unsubscribe_token = ? WHERE id = ?')
            ->execute([$token, (int) $subscriber['id']]);
    }

    return app_url('subscribe/unsubscribe.php?token=' . urlencode($token));
}

/**
 * Builds the payload that is stored in email_queue and rendered by the worker.
 * Delivers immediate promotional advertisements and campus housing recommendations.
 */
function subscriber_welcome_payload(PDO $pdo, array $subscriber): array
{
    return [
        'template' => 'welcome',
        'subject' => 'Welcome to UniStay | Find your next student dorm',
        'preheader' => 'Browse approved dorm listings, compare rooms, and find housing near your campus.',
        'heading' => 'Find your next dorm with UniStay',
        'paragraphs' => [
            'Thanks for subscribing to UniStay.',
            'Discover student housing around Dagupan City and compare the information you need before choosing where to stay.',
        ],
        'bullets' => [
            'Browse dorm listings from approved landlords',
            'Compare room rates, capacity, photos, and amenities',
            'Explore dorm locations near supported university campuses',
            'View landlord details and request a room when you are ready',
        ],
        'button_text' => 'Browse Promotional Dorms',
        'button_url' => app_url('tenant/dorms.php'),
        'footer_note' => "You're receiving this promotional message because you subscribed on the UniStay landing page. You can unsubscribe at any time below.",
        'unsubscribe_url' => subscriber_unsubscribe_url($pdo, $subscriber),
        'privacy_url' => subscriber_privacy_url(),
        'logo_url' => subscriber_logo_url(),
    ];
}

function subscriber_confirmation_payload(PDO $pdo, array $subscriber): array
{
    $token = trim((string) ($subscriber['confirm_token'] ?? ''));

    return [
        'template' => 'confirm',
        'subject' => 'Confirm your UniStay subscription',
        'preheader' => 'One quick tap confirms your email and keeps you posted on dorm updates.',
        'heading' => 'One quick tap to confirm',
        'paragraphs' => [
            'Hi there,',
            'Please confirm that you want to receive updates about UniStay — new dorms, available rooms, and tips for finding a place near your campus.',
            'This link expires in 48 hours.',
        ],
        'bullets' => [],
        'button_text' => 'Confirm my subscription',
        'button_url' => app_url('subscribe/confirm.php?token=' . urlencode($token)),
        'footer_note' => "If you did not ask for this, you can safely ignore this email and nothing will be sent to you again.",
        'unsubscribe_url' => subscriber_unsubscribe_url($pdo, $subscriber),
        'privacy_url' => subscriber_privacy_url(),
        'logo_url' => subscriber_logo_url(),
    ];
}

function subscriber_campaign_payload(PDO $pdo, array $subscriber, array $campaign): array
{
    $paragraphs = preg_split('/\r?\n\s*\r?\n/', trim((string) $campaign['body'])) ?: [];
    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn ($p) => $p !== ''));

    $ctaUrl = trim((string) ($campaign['cta_url'] ?? ''));
    if ($ctaUrl === '') {
        $ctaUrl = app_url('index.php');
    }

    return [
        'template' => 'campaign',
        'campaign_id' => (int) $campaign['id'],
        'campaign_name' => (string) $campaign['name'],
        'subject' => (string) $campaign['subject'],
        'preheader' => (string) ($campaign['heading'] ?? ''),
        'heading' => (string) $campaign['heading'],
        'paragraphs' => $paragraphs,
        'bullets' => [],
        'button_text' => (string) ($campaign['cta_text'] ?? ''),
        'button_url' => $ctaUrl,
        'footer_note' => "You're receiving this because you subscribed to updates on the UniStay website.",
        'unsubscribe_url' => subscriber_unsubscribe_url($pdo, $subscriber),
        'privacy_url' => subscriber_privacy_url(),
        'logo_url' => subscriber_logo_url(),
    ];
}

function subscriber_enqueue(PDO $pdo, array $payload, int $subscriberId, ?int $campaignId = null): int
{
    $subject = substr(trim((string) ($payload['subject'] ?? 'UniStay')), 0, 255);
    $template = substr((string) ($payload['template'] ?? 'campaign'), 0, 32);

    $stmt = $pdo->prepare(
        "INSERT INTO email_queue (subscriber_id, campaign_id, template, subject, payload, status, scheduled_at)
         VALUES (?, ?, ?, ?, ?, 'queued', NOW())"
    );
    $stmt->execute([
        $subscriberId,
        $campaignId,
        $template,
        $subject,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return (int) $pdo->lastInsertId();
}

function subscriber_welcome_already_sent(PDO $pdo, int $subscriberId): bool
{
    $stmt = $pdo->prepare(
        "SELECT id FROM email_queue
         WHERE subscriber_id = ? AND template = 'welcome' AND status IN ('queued','sending','sent')
         LIMIT 1"
    );
    $stmt->execute([$subscriberId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Sends a just-queued row immediately so the subscriber does not have to wait
 * for the scheduled worker. A failure stays in the queue for the normal retry.
 */
function subscriber_send_queued_now(PDO $pdo, int $queueId): string
{
    try {
        $claim = $pdo->prepare(
            "UPDATE email_queue SET status = 'sending', scheduled_at = NOW() WHERE id = ? AND status = 'queued'"
        );
        $claim->execute([$queueId]);

        if ($claim->rowCount() === 1) {
            return subscriber_process_queue_row($pdo, $queueId);
        }
    } catch (Throwable $error) {
        error_log('UniStay mail: immediate send failed: ' . $error->getMessage());
    }

    return 'skipped';
}

/**
 * Translate the persisted queue state into a truthful status for the browser.
 * A file transport is a local preview, not an email delivered to the address.
 */
function subscriber_queue_delivery_status(PDO $pdo, int $queueId): string
{
    $stmt = $pdo->prepare(
        'SELECT status, provider_message_id FROM email_queue WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$queueId]);
    $queue = $stmt->fetch();

    if ($queue === false) {
        return 'failed';
    }

    if ((string)$queue['status'] === 'sent') {
        return str_starts_with((string)($queue['provider_message_id'] ?? ''), 'file:')
            ? 'stored'
            : 'sent';
    }

    if (in_array((string)$queue['status'], ['queued', 'sending'], true)) {
        return 'queued';
    }

    return 'failed';
}

function subscriber_latest_delivery(PDO $pdo, int $subscriberId, string $template): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, status, provider_message_id FROM email_queue
         WHERE subscriber_id = ? AND template = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$subscriberId, $template]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Called after a successful subscribe: queues the confirmation mail (double
 * opt-in) or the welcome mail (single opt-in), then tries to deliver it at
 * once. The address that filled in the footer form is always the recipient.
 */
function subscriber_after_subscribe(PDO $pdo, array $result): string
{
    $subscriber = $result['subscriber'] ?? [];
    $recipient = trim((string)($subscriber['email'] ?? ''));

    if ($subscriber === [] || empty($subscriber['id']) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return 'skipped';
    }

    $subscriberId = (int)$subscriber['id'];
    $needsConfirmation = !empty($result['needs_confirmation']);
    $template = $needsConfirmation ? 'confirm' : 'welcome';
    $latest = subscriber_latest_delivery($pdo, $subscriberId, $template);

    // An active address receives one promotion per subscription lifecycle. A
    // reactivated address has send_welcome=true and intentionally gets a fresh
    // message even when an older lifecycle has a sent row.
    if (!$needsConfirmation && empty($result['send_welcome']) && $latest !== null) {
        if ((string)$latest['status'] === 'sent') {
            $wasStoredLocally = str_starts_with((string)($latest['provider_message_id'] ?? ''), 'file:');
            if (!$wasStoredLocally) {
                return 'already_sent';
            }
            if (!mail_is_configured()) {
                return 'stored';
            }
            // SMTP was configured after a local preview was created. Continue
            // below and create the first real delivery for this address.
        }

        if ((string)$latest['status'] === 'sending') {
            return 'queued';
        }

        if ((string)$latest['status'] === 'queued') {
            subscriber_send_queued_now($pdo, (int)$latest['id']);
            return subscriber_queue_delivery_status($pdo, (int)$latest['id']);
        }
    }

    $payload = $needsConfirmation
        ? subscriber_confirmation_payload($pdo, $subscriber)
        : subscriber_welcome_payload($pdo, $subscriber);
    $queueId = subscriber_enqueue($pdo, $payload, $subscriberId);
    subscriber_send_queued_now($pdo, $queueId);

    return subscriber_queue_delivery_status($pdo, $queueId);
}

/**
 * Renders one queued payload into the pieces the mail transport needs.
 *
 * @return array{subject:string,plain:string,html:string,headers:array<string,string>}
 */
function subscriber_render(array $payload): array
{
    $heading = trim((string) ($payload['heading'] ?? ''));
    $paragraphs = array_values(array_filter((array) ($payload['paragraphs'] ?? []), static fn ($p) => trim((string) $p) !== ''));
    $bullets = array_values(array_filter((array) ($payload['bullets'] ?? []), static fn ($p) => trim((string) $p) !== ''));
    $buttonText = trim((string) ($payload['button_text'] ?? ''));
    $buttonUrl = trim((string) ($payload['button_url'] ?? ''));
    $footerNote = trim((string) ($payload['footer_note'] ?? ''));
    $unsubscribeUrl = trim((string) ($payload['unsubscribe_url'] ?? ''));
    $template = (string) ($payload['template'] ?? 'campaign');

    $html = mail_brand_email([
        'preheader' => (string) ($payload['preheader'] ?? ''),
        'heading' => $heading,
        'paragraphs' => $paragraphs,
        'bullets' => $bullets,
        'button_text' => $buttonText,
        'button_url' => $buttonUrl,
        'footer_note' => $footerNote,
        'unsubscribe_url' => $unsubscribeUrl,
        'privacy_url' => (string) ($payload['privacy_url'] ?? ''),
        'logo_url' => (string) ($payload['logo_url'] ?? ''),
    ]);

    $plainParts = [$heading];

    foreach ($paragraphs as $paragraph) {
        $plainParts[] = (string) $paragraph;
    }

    if ($bullets !== []) {
        $plainParts[] = '• ' . implode("\n• ", array_map('strval', $bullets));
    }

    if ($buttonText !== '' && $buttonUrl !== '') {
        $plainParts[] = $buttonText . ': ' . $buttonUrl;
    }

    if ($footerNote !== '') {
        $plainParts[] = $footerNote;
    }

    if ($unsubscribeUrl !== '') {
        $plainParts[] = 'Unsubscribe: ' . $unsubscribeUrl;
    }

    $plainParts[] = 'UniStay · Student Accommodation & Dormitory Management Platform';

    $plain = implode("\n\n", array_filter($plainParts, static fn ($part) => trim((string) $part) !== ''));

    $headers = [];

    // Gmail's one-click unsubscribe header requires a public HTTPS endpoint.
    // Advertising a localhost/HTTP URL in mail headers harms deliverability and
    // cannot work from the recipient's mail provider. The visible unsubscribe
    // link remains in the message body for local development.
    $unsubscribeScheme = strtolower((string)parse_url($unsubscribeUrl, PHP_URL_SCHEME));
    if ($unsubscribeUrl !== '' && $template !== 'confirm' && $unsubscribeScheme === 'https') {
        $headers['List-Unsubscribe'] = '<' . $unsubscribeUrl . '>';
        $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
    }

    return [
        'subject' => (string) ($payload['subject'] ?? 'UniStay'),
        'plain' => $plain,
        'html' => $html,
        'headers' => $headers,
    ];
}

/**
 * Claims and sends one queue row.
 *
 * @return string sent|failed|skipped
 */
function subscriber_process_queue_row(PDO $pdo, int $queueId): string
{
    $stmt = $pdo->prepare("SELECT * FROM email_queue WHERE id = ? AND status = 'sending' LIMIT 1");
    $stmt->execute([$queueId]);
    $queue = $stmt->fetch();

    if ($queue === false) {
        return 'skipped';
    }

    $subscriber = subscriber_find_by_id($pdo, (int) $queue['subscriber_id']);

    $fail = static function (PDO $pdo, array $queue, string $message): string {
        $pdo->prepare(
            "UPDATE email_queue
                SET status = 'failed', attempts = attempts + 1, last_error = ?, provider_message_id = NULL
              WHERE id = ?"
        )->execute([substr($message, 0, 500), (int) $queue['id']]);

        return 'failed';
    };

    if ($subscriber === null) {
        return $fail($pdo, $queue, 'Subscriber no longer exists.');
    }

    $template = (string) $queue['template'];
    $allowedStatuses = in_array($template, ['confirm', 'welcome'], true)
        ? ['pending', 'active']
        : ['active'];

    if (!in_array((string) $subscriber['status'], $allowedStatuses, true)) {
        $pdo->prepare("UPDATE email_queue SET status = 'failed', last_error = ? WHERE id = ?")
            ->execute(['Suppressed: subscriber status is ' . $subscriber['status'], (int) $queue['id']]);

        return 'skipped';
    }

    $payload = json_decode((string) $queue['payload'], true);
    if (!is_array($payload)) {
        return $fail($pdo, $queue, 'The queued message payload is not valid JSON.');
    }

    $rendered = subscriber_render($payload);
    $error = '';
    $result = mail_deliver_or_store(
        (string) $subscriber['email'],
        $rendered['subject'],
        $rendered['plain'],
        $rendered['html'],
        $rendered['headers'],
        $error
    );

    if ($result['ok']) {
        $messageId = substr((string) $result['id'], 0, 191);
        $note = $result['transport'] === 'file' ? 'Stored in storage/mail (SMTP not configured).' : null;

        $pdo->prepare(
            "UPDATE email_queue
                SET status = 'sent', sent_at = NOW(), provider_message_id = ?, last_error = ?
              WHERE id = ?"
        )->execute([$messageId !== '' ? $messageId : null, $note, (int) $queue['id']]);

        $pdo->prepare(
            'INSERT INTO email_logs (subscriber_id, email, template, status, provider_message_id, error)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            (int) $subscriber['id'],
            (string) $subscriber['email'],
            $template,
            'sent',
            $messageId !== '' ? $messageId : null,
            $note,
        ]);

        $pdo->prepare('UPDATE subscribers SET last_sent_at = NOW() WHERE id = ?')
            ->execute([(int) $subscriber['id']]);

        subscriber_refresh_campaign_status($pdo, $queue['campaign_id'] !== null ? (int) $queue['campaign_id'] : null);

        return 'sent';
    }

    $attempts = (int) $queue['attempts'] + 1;
    $status = $attempts >= 3 ? 'failed' : 'queued';
    $delayMinutes = 5 * $attempts;
    $message = $error !== '' ? $error : 'The mail transport refused the message.';

    $pdo->prepare(
        "UPDATE email_queue
            SET status = ?, attempts = ?, last_error = ?,
                scheduled_at = DATE_ADD(NOW(), INTERVAL {$delayMinutes} MINUTE)
          WHERE id = ?"
    )->execute([$status, $attempts, substr($message, 0, 500), (int) $queue['id']]);

    $pdo->prepare(
        'INSERT INTO email_logs (subscriber_id, email, template, status, provider_message_id, error)
         VALUES (?, ?, ?, ?, NULL, ?)'
    )->execute([
        (int) $subscriber['id'],
        (string) $subscriber['email'],
        $template,
        'failed',
        substr($message, 0, 500),
    ]);

    return 'failed';
}

/**
 * Sends queued mail in small batches. Safe to run more than once at the same time.
 *
 * @return array{claimed:int,sent:int,failed:int,skipped:int}
 */
function subscriber_queue_work(PDO $pdo, int $limit = 25): array
{
    subscribers_ensure_schema($pdo);

    $limit = max(1, min(200, $limit));
    $stats = ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

    // A worker that crashed mid-send leaves rows in "sending". Put them back in
    // the queue after a grace period instead of losing the message.
    $pdo->exec(
        "UPDATE email_queue
            SET status = 'queued', last_error = 'Recovered after an interrupted worker run.'
          WHERE status = 'sending' AND scheduled_at < (NOW() - INTERVAL 15 MINUTE)"
    );

    $ids = $pdo->query(
        "SELECT id FROM email_queue
          WHERE status = 'queued' AND scheduled_at <= NOW()
          ORDER BY id ASC
          LIMIT {$limit}"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        $claim = $pdo->prepare("UPDATE email_queue SET status = 'sending', scheduled_at = NOW() WHERE id = ? AND status = 'queued'");
        $claim->execute([(int) $id]);

        if ($claim->rowCount() !== 1) {
            continue;
        }

        $stats['claimed']++;
        $outcome = subscriber_process_queue_row($pdo, (int) $id);

        if (isset($stats[$outcome])) {
            $stats[$outcome]++;
        }

        usleep(250000);
    }

    return $stats;
}

/**
 * Marks a campaign as sent once it has no queued rows left.
 */
function subscriber_refresh_campaign_status(PDO $pdo, ?int $campaignId): void
{
    if ($campaignId === null || $campaignId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE campaign_id = ? AND status IN ('queued','sending')");
    $stmt->execute([$campaignId]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->prepare("UPDATE campaigns SET status = 'sent', sent_at = COALESCE(sent_at, NOW()) WHERE id = ?")
            ->execute([$campaignId]);
    }
}

/**
 * Confirms a subscription from the emailed link.
 *
 * @return array{ok:bool,message:string,email:string,newly_confirmed:bool}
 */
function subscriber_confirm_by_token(PDO $pdo, string $token): array
{
    $token = trim($token);
    $subscriber = subscriber_find_by_token($pdo, 'confirm_token', $token);

    if ($subscriber === null) {
        return [
            'ok' => false,
            'message' => 'This confirmation link is not valid or has already been used.',
            'email' => '',
            'newly_confirmed' => false,
        ];
    }

    if ((string) $subscriber['status'] === 'active') {
        return [
            'ok' => true,
            'message' => 'Your subscription is already confirmed.',
            'email' => (string) $subscriber['email'],
            'newly_confirmed' => false,
        ];
    }

    $expires = trim((string) ($subscriber['confirm_expires_at'] ?? ''));
    if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) {
        return [
            'ok' => false,
            'message' => 'This confirmation link has expired. Please subscribe again to receive a new one.',
            'email' => (string) $subscriber['email'],
            'newly_confirmed' => false,
        ];
    }

    $pdo->prepare(
        "UPDATE subscribers
            SET status = 'active', confirmed_at = NOW(), confirm_token = NULL, confirm_expires_at = NULL
          WHERE id = ?"
    )->execute([(int) $subscriber['id']]);

    $subscriber['status'] = 'active';

    if (!subscriber_welcome_already_sent($pdo, (int) $subscriber['id'])) {
        $queueId = subscriber_enqueue($pdo, subscriber_welcome_payload($pdo, $subscriber), (int) $subscriber['id']);
        subscriber_send_queued_now($pdo, $queueId);
    }

    return [
        'ok' => true,
        'message' => 'You are subscribed. Watch your inbox for updates about UniStay.',
        'email' => (string) $subscriber['email'],
        'newly_confirmed' => true,
    ];
}

/**
 * Unsubscribes an address from the emailed link.
 *
 * @return array{ok:bool,message:string,email:string}
 */
function subscriber_unsubscribe_by_token(PDO $pdo, string $token): array
{
    $token = trim($token);
    $subscriber = subscriber_find_by_token($pdo, 'unsubscribe_token', $token);

    if ($subscriber === null) {
        return [
            'ok' => false,
            'message' => 'This unsubscribe link is not valid.',
            'email' => '',
        ];
    }

    if ((string) $subscriber['status'] === 'unsubscribed') {
        return [
            'ok' => true,
            'message' => 'This address is already unsubscribed.',
            'email' => (string) $subscriber['email'],
        ];
    }

    $pdo->prepare(
        "UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE id = ?"
    )->execute([(int) $subscriber['id']]);

    $pdo->prepare(
        "UPDATE email_queue SET status = 'failed', last_error = 'Subscriber unsubscribed.'
          WHERE subscriber_id = ? AND status = 'queued'"
    )->execute([(int) $subscriber['id']]);

    return [
        'ok' => true,
        'message' => 'You have been unsubscribed and will not receive further updates.',
        'email' => (string) $subscriber['email'],
    ];
}

/**
 * @return array{total:int,active:int,pending:int,unsubscribed:int,bounced:int,queued:int,failed:int}
 */
function subscriber_stats(PDO $pdo): array
{
    $stats = [
        'total' => 0,
        'active' => 0,
        'pending' => 0,
        'unsubscribed' => 0,
        'bounced' => 0,
        'queued' => 0,
        'failed' => 0,
    ];

    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM subscribers GROUP BY status') as $row) {
        $status = (string) $row['status'];
        $count = (int) $row['total'];
        $stats['total'] += $count;

        if (isset($stats[$status])) {
            $stats[$status] = $count;
        }
    }

    $stats['queued'] = 0;
    $stats['failed'] = 0;

    return $stats;
}

function subscriber_create_campaign(PDO $pdo, array $data, ?string $adminId = null): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO campaigns (name, subject, heading, body, cta_text, cta_url, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)"
    );
    $stmt->execute([
        substr(trim((string) ($data['name'] ?? 'Untitled campaign')), 0, 120),
        substr(trim((string) ($data['subject'] ?? '')), 0, 255),
        substr(trim((string) ($data['heading'] ?? '')), 0, 255),
        trim((string) ($data['body'] ?? '')),
        substr(trim((string) ($data['cta_text'] ?? '')), 0, 80),
        substr(trim((string) ($data['cta_url'] ?? '')), 0, 255),
        $adminId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Queues a campaign for every active subscriber. Re-running it is safe: a
 * subscriber that already has a row for this campaign is skipped.
 */
function subscriber_enqueue_campaign(PDO $pdo, int $campaignId): int
{
    $stmt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ? LIMIT 1');
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if ($campaign === false) {
        return 0;
    }

    $already = [];
    $stmt = $pdo->prepare('SELECT subscriber_id FROM email_queue WHERE campaign_id = ?');
    $stmt->execute([$campaignId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $subscriberId) {
        $already[(int) $subscriberId] = true;
    }

    $subscribers = $pdo->query(
        "SELECT * FROM subscribers WHERE status = 'active' ORDER BY id ASC"
    )->fetchAll();

    $queued = 0;

    foreach ($subscribers as $subscriber) {
        if (isset($already[(int) $subscriber['id']])) {
            continue;
        }

        subscriber_enqueue(
            $pdo,
            subscriber_campaign_payload($pdo, $subscriber, $campaign),
            (int) $subscriber['id'],
            $campaignId
        );

        $queued++;
    }

    $pdo->prepare("UPDATE campaigns SET status = 'sending' WHERE id = ?")->execute([$campaignId]);
    subscriber_refresh_campaign_status($pdo, $campaignId);

    return $queued;
}

/**
 * Small CSRF helper for the admin campaign form.
 */
function subscriber_csrf_token(): string
{
    require_once __DIR__ . '/session.php';
    start_app_session();

    if (empty($_SESSION['subscriber_csrf'])) {
        $_SESSION['subscriber_csrf'] = bin2hex(random_bytes(24));
    }

    return (string) $_SESSION['subscriber_csrf'];
}

function subscriber_csrf_valid(?string $token): bool
{
    require_once __DIR__ . '/session.php';
    start_app_session();

    return is_string($token)
        && !empty($_SESSION['subscriber_csrf'])
        && hash_equals((string) $_SESSION['subscriber_csrf'], $token);
}
