<?php
// Preserve existing bookmarks while Requests uses requests.php as its canonical URL.
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: requests.php' . ($query !== '' ? '?' . $query : ''), true, 308);
exit;
