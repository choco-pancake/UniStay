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
function decide_landlord_application(PDO $pdo, int $applicationId, string $action, string $decisionNote = '', ?string $approvedBy = null): array
{
    if (!in_array($action, ['approve_application', 'decline_application'], true)) {
        return ['ok' => false, 'message' => 'Unknown application action.', 'invitation_url' => ''];
    }

    $decisionNote = mb_substr(trim($decisionNote), 0, 500);
    $approvedBy = $approvedBy !== null ? mb_substr(trim($approvedBy), 0, 150) : null;
    $application = null;

    try {
        $statement = $pdo->prepare('SELECT * FROM landlords WHERE id = ? LIMIT 1');
        $statement->execute([$applicationId]);
        $application = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$application) {
            return ['ok' => false, 'message' => 'The landlord application could not be found.', 'invitation_url' => ''];
        }

        if ($action === 'approve_application') {
            $activationToken = bin2hex(random_bytes(32));
            $update = $pdo->prepare("UPDATE landlords SET status = 'approved', activation_token = ?, approved_by = ?, feedback = ?, decided_at = NOW() WHERE id = ?");
            $update->execute([$activationToken, $approvedBy, $decisionNote !== '' ? $decisionNote : null, $applicationId]);
        } else {
            $update = $pdo->prepare("UPDATE landlords SET status = 'declined', activation_token = NULL, approved_by = ?, feedback = ?, decided_at = NOW() WHERE id = ?");
            $update->execute([$approvedBy, $decisionNote !== '' ? $decisionNote : null, $applicationId]);
        }
    } catch (Throwable $error) {
        error_log('UniStay landlord application decision failed: ' . $error->getMessage());
        return ['ok' => false, 'message' => 'The decision could not be saved. Please try again.', 'invitation_url' => ''];
    }

    $name = trim((string)($application['first_name'] ?? '') . ' ' . (string)($application['last_name'] ?? ''));
    $targetEmail = (string)($application['email'] ?? ($application['personal_email'] ?? ''));
    if ($action === 'approve_application') {
        $activationUrl = app_url('auth/landlord-register.php?email=' . urlencode($targetEmail) . '&token=' . $activationToken);
        $plain = "Hi {$name},\n\nGreat news! Your UniStay landlord application has been approved by the administration.\n\nPlease click the link below to set your password and complete your account setup:\n{$activationUrl}";
        $html = '<p>Hi ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>Great news! Your UniStay landlord application has been approved by the administration.</p>'
            . '<p>Please click the button below to set up your password and activate your landlord account:</p>'
            . '<p><a href="' . htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#7a4b3a;color:#fff;padding:12px 22px;border-radius:10px;text-decoration:none;display:inline-block;font-weight:bold;">Create Password &amp; Activate Account</a></p>'
            . '<p style="color:#78665c;font-size:0.88rem;">If the button above does not work, copy and paste this link into your browser:<br>' . htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8') . '</p>';
        $mailSent = send_mail($targetEmail, 'Your UniStay landlord application was approved! 🎉', $plain, $html);
        return [
            'ok' => true,
            'message' => $mailSent
                ? 'The application was approved and an activation email with signup link was sent to the landlord.'
                : 'The application was approved. (Email could not be delivered, but the activation link is ready).',
            'invitation_url' => $activationUrl,
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
