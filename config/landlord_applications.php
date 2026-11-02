<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mailer.php';

/**
 * Fetches and validates the short-lived credential used by an admin review email.
 */
function landlord_application_from_review_token(PDO $pdo, int $applicationId, string $token): ?array
{
    if ($applicationId <= 0 || !preg_match('/^[a-f0-9]{64}$/i', $token)) return null;

    $statement = $pdo->prepare('SELECT * FROM landlord_applications WHERE id = ? LIMIT 1');
    $statement->execute([$applicationId]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;

    $storedHash = (string)($application['admin_review_token_hash'] ?? '');
    $expiresAt = strtotime((string)($application['admin_review_token_expires_at'] ?? '')) ?: 0;
    if ($storedHash === '' || !hash_equals($storedHash, hash('sha256', $token))) return null;
    if ($expiresAt < time() || !empty($application['admin_review_token_used_at'])) return null;
    if (($application['status'] ?? '') !== 'pending') return null;

    return $application;
}

/** Validates the narrow review grant stored in the current PHP session. */
function landlord_application_from_review_grant(PDO $pdo, array $grant): ?array
{
    $applicationId = (int)($grant['application_id'] ?? 0);
    $grantHash = (string)($grant['token_hash'] ?? '');
    $grantExpires = (int)($grant['expires_at'] ?? 0);
    if ($applicationId <= 0 || $grantHash === '' || $grantExpires < time()) return null;

    $statement = $pdo->prepare('SELECT * FROM landlord_applications WHERE id = ? LIMIT 1');
    $statement->execute([$applicationId]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;

    $storedHash = (string)($application['admin_review_token_hash'] ?? '');
    $databaseExpiry = strtotime((string)($application['admin_review_token_expires_at'] ?? '')) ?: 0;
    if ($storedHash === '' || !hash_equals($storedHash, $grantHash)) return null;
    if ($databaseExpiry < time() || !empty($application['admin_review_token_used_at'])) return null;
    if (($application['status'] ?? '') !== 'pending') return null;
    return $application;
}

/**
 * Performs the single authoritative approve/decline transition for an application.
 * Applicant mail is sent after the database transaction so a mail outage cannot
 * undo a valid administrative decision.
 */
function decide_landlord_application(PDO $pdo, int $applicationId, string $action, string $decisionNote = ''): array
{
    if (!in_array($action, ['approve_application', 'decline_application'], true)) {
        return ['ok' => false, 'message' => 'Unknown application action.', 'invitation_url' => ''];
    }

    $decisionNote = mb_substr(trim($decisionNote), 0, 500);
    $invitationUrl = '';
    $application = null;

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT * FROM landlord_applications WHERE id = ? FOR UPDATE');
        $statement->execute([$applicationId]);
        $application = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'The landlord application could not be found.', 'invitation_url' => ''];
        }
        if (($application['status'] ?? '') !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This application has already been reviewed.', 'invitation_url' => ''];
        }
        if (!empty($application['registered_at'])) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This application has already been used to create an account.', 'invitation_url' => ''];
        }

        if ($action === 'approve_application') {
            $inviteToken = bin2hex(random_bytes(32));
            $invitationUrl = app_url('/auth/landlord-register.php?invite=' . rawurlencode($inviteToken));
            $update = $pdo->prepare(
                "UPDATE landlord_applications
                 SET status = 'approved', decision_note = ?, invitation_token_hash = ?, decided_at = NOW(),
                     admin_review_token_used_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $update->execute([$decisionNote !== '' ? $decisionNote : null, hash('sha256', $inviteToken), $applicationId]);
        } else {
            $update = $pdo->prepare(
                "UPDATE landlord_applications
                 SET status = 'declined', decision_note = ?, invitation_token_hash = NULL, decided_at = NOW(),
                     admin_review_token_used_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $update->execute([$decisionNote !== '' ? $decisionNote : null, $applicationId]);
        }

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'This application was reviewed by another request.', 'invitation_url' => ''];
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('UniStay landlord application decision failed: ' . $error->getMessage());
        return ['ok' => false, 'message' => 'The decision could not be saved. Please try again.', 'invitation_url' => ''];
    }

    $name = trim((string)($application['first_name'] ?? '') . ' ' . (string)($application['last_name'] ?? ''));
    if ($action === 'approve_application') {
        $plain = "Hi {$name},\n\nYour UniStay landlord application was approved. Create your account using this one-time invitation:\n{$invitationUrl}";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Your UniStay landlord application was approved.</p>'
            . '<p><a href="' . htmlspecialchars($invitationUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#7a4b3a;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none">Create landlord account</a></p>';
        $mailSent = send_mail((string)$application['email'], 'Your UniStay landlord application was approved', $plain, $html);
        return [
            'ok' => true,
            'message' => $mailSent
                ? 'The application was approved and the invitation was emailed.'
                : 'The application was approved, but the invitation email could not be delivered. Copy the invitation below.',
            'invitation_url' => $invitationUrl,
            'mail_sent' => $mailSent,
        ];
    }

    $plain = "Hi {$name},\n\nYour UniStay landlord application was not approved." . ($decisionNote !== '' ? "\n\nAdmin note: {$decisionNote}" : '');
    $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Your UniStay landlord application was not approved.</p>'
        . ($decisionNote !== '' ? '<p><b>Admin note:</b><br>' . nl2br(htmlspecialchars($decisionNote, ENT_QUOTES, 'UTF-8')) . '</p>' : '');
    $mailSent = send_mail((string)$application['email'], 'Update on your UniStay landlord application', $plain, $html);
    return [
        'ok' => true,
        'message' => $mailSent ? 'The application was declined and the applicant was notified.' : 'The application was declined, but the notification email could not be delivered.',
        'invitation_url' => '',
        'mail_sent' => $mailSent,
    ];
}
