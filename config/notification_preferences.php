<?php

function notification_preference_keys(string $role): array
{
    return $role === 'landlord'
        ? ['booking_alerts', 'verification_alerts', 'summary_emails']
        : ($role === 'tenant' ? ['tenant_alerts', 'announcement_emails'] : []);
}

function get_notification_preferences(PDO $pdo, int $userId, string $role): array
{
    $defaults = array_fill_keys(notification_preference_keys($role), true);
    $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($defaults as $key => &$value) {
        if (array_key_exists($key, $row)) $value = (bool)$row[$key];
    }
    return $defaults;
}

function save_notification_preferences(PDO $pdo, int $userId, string $role, array $values): array
{
    $allowed = notification_preference_keys($role);
    if (!$values || !$allowed) throw new InvalidArgumentException('No supported preferences supplied.');
    foreach ($values as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_bool($value)) {
            throw new InvalidArgumentException('Preferences must use supported names and Boolean values.');
        }
    }
    $columns = array_keys($values);
    $updates = implode(', ', array_map(fn($key) => "$key = VALUES($key)", $columns));
    $sql = 'INSERT INTO notification_preferences (user_id, ' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns) + 1, '?')) . ') ON DUPLICATE KEY UPDATE ' . $updates;
    $pdo->prepare($sql)->execute(array_merge([$userId], array_map(fn($value) => (int)$value, array_values($values))));
    return get_notification_preferences($pdo, $userId, $role);
}

/** Announcement and summary senders can use the same guard as existing category emails. */
function notification_delivery_enabled(PDO $pdo, int $userId, string $role, string $category): bool
{
    try {
        return get_notification_preferences($pdo, $userId, $role)[$category] ?? true;
    } catch (Throwable $error) {
        error_log('UniStay notification preferences: ' . $error->getMessage());
        return false;
    }
}
