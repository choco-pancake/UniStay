<?php

/**
 * Builds the HTML of a mail of the app.
 *
 * The layout uses tables and inline styles, that is what mail clients such as
 * Gmail and Outlook understand best.
 */
function mail_html(string $heading, array $paragraphs, string $buttonText, string $buttonUrl, string $footerNote = ''): string
{
    $paragraphHtml = '';

    foreach ($paragraphs as $paragraph) {
        $paragraphHtml .= '            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3b312c;">'
            . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
    $safeButton = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeFooter = htmlspecialchars($footerNote !== '' ? $footerNote : 'UniStay · Student Accommodation & Dormitory Management Platform.', ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $safeHeading . '</title>
</head>
<body style="margin:0;padding:0;background:#fdfaf5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fdfaf5;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #d9c9bd;border-radius:16px;">
                <tr>
                    <td style="background:#7a4b3a;padding:20px 28px;border-radius:15px 15px 0 0;">
                        <img src="cid:unistay_logo" width="38" height="38" alt="UniStay" style="vertical-align:middle;border-radius:8px;margin-right:12px;display:inline-block;border:0;"><span style="font-family:Georgia,serif;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.5px;vertical-align:middle;">UniStay</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px;">
                        <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#1f1a17;">' . $safeHeading . '</h1>
' . $paragraphHtml . '                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 20px;">
                            <tr>
                                <td align="center" bgcolor="#7a4b3a" style="border-radius:10px;">
                                    <a href="' . $safeUrl . '" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">' . $safeButton . '</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:0;font-size:12px;line-height:1.7;color:#9a8c84;">If the button does not work, copy this link into your browser:<br>
                            <a href="' . $safeUrl . '" style="color:#5f3425;word-break:break-all;">' . $safeUrl . '</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 28px;background:#f5f1ec;border-radius:0 0 15px 15px;font-size:12px;line-height:1.6;color:#9a8c84;">
                        ' . $safeFooter . '
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';
}

/**
 * Builds a promotional / newsletter mail with the UniStay look.
 *
 * Expects an array with these keys (all optional except heading):
 *   preheader, heading, paragraphs[], bullets[], button_text, button_url,
 *   footer_note, unsubscribe_url, privacy_url, logo_url, brand.
 */
function mail_brand_email(array $options): string
{
    $brand = htmlspecialchars((string) ($options['brand'] ?? 'UniStay'), ENT_QUOTES, 'UTF-8');
    $heading = htmlspecialchars((string) ($options['heading'] ?? ''), ENT_QUOTES, 'UTF-8');
    $preheader = htmlspecialchars((string) ($options['preheader'] ?? ''), ENT_QUOTES, 'UTF-8');
    $buttonText = htmlspecialchars((string) ($options['button_text'] ?? ''), ENT_QUOTES, 'UTF-8');
    $buttonUrl = htmlspecialchars((string) ($options['button_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $footerNote = htmlspecialchars((string) ($options['footer_note'] ?? ''), ENT_QUOTES, 'UTF-8');
    $unsubscribeUrl = htmlspecialchars((string) ($options['unsubscribe_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $privacyUrl = htmlspecialchars((string) ($options['privacy_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $logoUrl = htmlspecialchars((string) ($options['logo_url'] ?? ''), ENT_QUOTES, 'UTF-8');

    $paragraphHtml = '';
    foreach ((array) ($options['paragraphs'] ?? []) as $paragraph) {
        $paragraphHtml .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#3b312c;">'
            . htmlspecialchars((string) $paragraph, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    $bullets = array_values(array_filter((array) ($options['bullets'] ?? []), static fn ($item) => trim((string) $item) !== ''));
    $bulletHtml = '';

    if ($bullets !== []) {
        $bulletHtml = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 20px;">' . "\n";
        foreach ($bullets as $bullet) {
            $bulletHtml .= '    <tr><td width="26" valign="top" style="padding:6px 0 6px 4px;font-size:15px;line-height:1.5;color:#7a4b3a;">&#10003;</td>'
                . '<td style="padding:6px 0;font-size:15px;line-height:1.5;color:#3b312c;">'
                . htmlspecialchars((string) $bullet, ENT_QUOTES, 'UTF-8') . '</td></tr>' . "\n";
        }
        $bulletHtml .= '</table>' . "\n";
    }

    $buttonHtml = '';

    if ($buttonText !== '' && $buttonUrl !== '') {
        $buttonHtml = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 18px;">' . "\n"
            . '    <tr><td align="center" bgcolor="#7a4b3a" style="border-radius:10px;">'
            . '<a href="' . $buttonUrl . '" style="display:inline-block;padding:14px 30px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">'
            . $buttonText . '</a></td></tr>' . "\n"
            . '</table>' . "\n"
            . '<p style="margin:0;font-size:12px;line-height:1.7;color:#9a8c84;">If the button does not work, copy this link into your browser:<br>'
            . '<a href="' . $buttonUrl . '" style="color:#5f3425;word-break:break-all;">' . $buttonUrl . '</a></p>' . "\n";
    }

    $logoSrc = ($options['logo_url'] ?? '') !== '' ? (string) ($options['logo_url'] ?? '') : 'cid:unistay_logo';
    if (strpos($logoSrc, 'localhost') !== false || $logoSrc === '') {
        $logoSrc = 'cid:unistay_logo';
    }
    $logoHtml = '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" width="38" height="38" alt="' . $brand . '" style="vertical-align:middle;border-radius:8px;margin-right:12px;display:inline-block;border:0;">';

    $unsubscribeHtml = $unsubscribeUrl !== ''
        ? '<a href="' . $unsubscribeUrl . '" style="color:#5f3425;text-decoration:underline;">Unsubscribe</a>'
        : '';

    $privacyHtml = $privacyUrl !== ''
        ? ' &nbsp;·&nbsp; <a href="' . $privacyUrl . '" style="color:#5f3425;text-decoration:underline;">Privacy Policy</a>'
        : '';

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . $heading . '</title>
</head>
<body style="margin:0;padding:0;background:#fdfaf5;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $preheader . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fdfaf5;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border:1px solid #d9c9bd;border-radius:16px;">
                <tr>
                    <td style="background:#7a4b3a;padding:20px 28px;border-radius:15px 15px 0 0;">
                        ' . $logoHtml . '<span style="font-family:Georgia,serif;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.5px;vertical-align:middle;">' . $brand . '</span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:30px 28px 24px;">
                        <h1 style="margin:0 0 18px;font-size:22px;line-height:1.3;color:#1f1a17;">' . $heading . '</h1>
' . $paragraphHtml . $bulletHtml . $buttonHtml . '
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 28px;background:#f5f1ec;border-radius:0 0 15px 15px;font-size:12px;line-height:1.7;color:#8c7b72;">
                        ' . $footerNote . '<br><br>
                        ' . $unsubscribeHtml . $privacyHtml . '<br>
                        ' . $brand . ' · Student Accommodation &amp; Dormitory Management Platform
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';
}
