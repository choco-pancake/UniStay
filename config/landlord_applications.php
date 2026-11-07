<?php

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/mailer.php';

/**
 * Fetches and validates the short-lived credential used by an admin review email.
 */
function landlord_application_from_review_token(PDO $pdo, int $applicationId, string $token): ?array
{
    if ($applicationId <= 0) return null;

    $statement = $pdo->prepare('SELECT id, first_name, middle_name, last_name, email, email AS personal_email, phone_number AS phone, facebook_link, residential_address, experience_summary, gov_id AS government_id_file, status, feedback, created_at, decided_at FROM landlords WHERE id = ? LIMIT 1');
    $statement->execute([$applicationId]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;
    if (($application['status'] ?? '') !== 'pending') return null;

    return $application;
}

/** Validates the narrow review grant stored in the current PHP session. */
function landlord_application_from_review_grant(PDO $pdo, array $grant): ?array
{
    $applicationId = (int)($grant['application_id'] ?? 0);
    if ($applicationId <= 0) return null;

    $statement = $pdo->prepare('SELECT id, first_name, middle_name, last_name, email, email AS personal_email, phone_number AS phone, facebook_link, residential_address, experience_summary, gov_id AS government_id_file, status, feedback, created_at, decided_at FROM landlords WHERE id = ? LIMIT 1');
    $statement->execute([$applicationId]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$application) return null;
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
    $application = null;

    try {
        $statement = $pdo->prepare('SELECT * FROM landlords WHERE id = ? LIMIT 1');
        $statement->execute([$applicationId]);
        $application = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            return ['ok' => false, 'message' => 'The landlord application could not be found.', 'invitation_url' => ''];
        }

        if ($action === 'approve_application') {
            $update = $pdo->prepare("UPDATE landlords SET status = 'approved', feedback = ?, decided_at = NOW() WHERE id = ?");
            $update->execute([$decisionNote !== '' ? $decisionNote : null, $applicationId]);
        } else {
            $update = $pdo->prepare("UPDATE landlords SET status = 'declined', feedback = ?, decided_at = NOW() WHERE id = ?");
            $update->execute([$decisionNote !== '' ? $decisionNote : null, $applicationId]);
        }
    } catch (Throwable $error) {
        error_log('UniStay landlord application decision failed: ' . $error->getMessage());
        return ['ok' => false, 'message' => 'The decision could not be saved. Please try again.', 'invitation_url' => ''];
    }

    $name = trim((string)($application['first_name'] ?? '') . ' ' . (string)($application['last_name'] ?? ''));
    $targetEmail = (string)($application['email'] ?? ($application['personal_email'] ?? ''));
    if ($action === 'approve_application') {
        $loginUrl = app_url('auth/login.php');
        $plain = "Hi {$name},\n\nGreat news! Your UniStay landlord application has been approved by the administration. You can now log in immediately with your email and password to manage your dorm listings:\n{$loginUrl}";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Great news! Your UniStay landlord application has been approved by the administration.</p>'
            . '<p>You can now log in immediately with your email and password to manage your dorm listings:</p>'
            . '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#7a4b3a;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;display:inline-block;">Log in to Landlord Portal</a></p>';
        $mailSent = send_mail($targetEmail, 'Your UniStay landlord application was approved! 🎉', $plain, $html);
        return [
            'ok' => true,
            'message' => $mailSent
                ? 'The application was approved, landlord account activated, and confirmation email sent.'
                : 'The application was approved and landlord account activated. (Email could not be delivered, but the landlord can log in directly).',
            'invitation_url' => '',
            'mail_sent' => $mailSent,
        ];
    }

    $plain = "Hi {$name},\n\nYour UniStay landlord application was not approved." . ($decisionNote !== '' ? "\n\nAdmin note: {$decisionNote}" : '');
    $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Your UniStay landlord application was not approved.</p>'
        . ($decisionNote !== '' ? '<p><b>Admin note:</b><br>' . nl2br(htmlspecialchars($decisionNote, ENT_QUOTES, 'UTF-8')) . '</p>' : '');
    $mailSent = send_mail($targetEmail, 'Update on your UniStay landlord application', $plain, $html);
    return [
        'ok' => true,
        'message' => $mailSent ? 'The application was declined and the applicant was notified.' : 'The application was declined, but the notification email could not be delivered.',
        'invitation_url' => '',
        'mail_sent' => $mailSent,
    ];
}
