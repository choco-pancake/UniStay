<?php

/**
 * Builds the HTML of a mail of the app.
 *
 * The layout uses tables and inline styles, that is what mail clients such as
 * Gmail and Outlook understand best.
 */
function mail_html(string $heading, array $paragraphs, string $buttonText, string $buttonUrl): string
{
    $paragraphHtml = '';

    foreach ($paragraphs as $paragraph) {
        $paragraphHtml .= '            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#3b312c;">'
            . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>' . "\n";
    }

    $safeUrl = htmlspecialchars($buttonUrl, ENT_QUOTES, 'UTF-8');
    $safeButton = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');

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
                    <td style="background:#7a4b3a;padding:22px 28px;border-radius:15px 15px 0 0;">
                        <span style="font-family:Georgia,serif;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.5px;">UniStay</span>
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
                        UniStay, student dorm accommodation. This mail was sent because somebody asked to reset a password.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>';
}
