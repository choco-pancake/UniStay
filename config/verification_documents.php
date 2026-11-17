<?php
require_once __DIR__ . '/app.php';

/** URL for a stored verification document, subject to access checks. */
function resolve_doc_web_url(?string $path, string $role = 'landlord'): string
{
    $path = trim((string)$path);
    return $path === '' ? '' : app_private_document_url($path);
}
