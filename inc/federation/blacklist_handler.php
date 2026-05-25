<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/blacklist.json
 *
 * Public read of the Pluriverse-curated blacklist. Other peers pull
 * this to refuse incoming federation requests from listed entries.
 * Three entry types:
 *
 *   hostname → exact-match host refusal
 *   domain   → suffix-match refusal (e.g. evil.example.com blocks
 *              foo.evil.example.com too)
 *   ip       → exact-match or CIDR
 *
 * Response is plain JSON for this chunk; JWS-signed envelope is a
 * follow-up before stages 3+ ship the peer-side pull verifier. The
 * `reason` and `added_by` fields are surfaced for transparency.
 *
 * Caching: ETag (sha256 prefix) + Last-Modified (max added_at) +
 * Cache-Control: public, max-age=300. Conditional GET yields 304.
 */

require_once dirname(__DIR__, 2) . '/config.php';

try {
    $pdo = getDB();
    db_ensure_blacklists_table();
    $rows = $pdo->query("
        SELECT entry_type, entry_value, reason, added_by, added_at
        FROM blacklists
        ORDER BY entry_type, entry_value
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('blacklist_handler: query failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read the blacklist.', '/api/pluriverse/blacklist.json');
    return;
}

$maxAdded = 0;
$entries = [];
foreach ($rows as $r) {
    $entries[] = [
        'entry_type' => (string)$r['entry_type'],
        'entry_value' => (string)$r['entry_value'],
        'reason' => $r['reason'],
        'added_by' => $r['added_by'],
        'added_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['added_at'])),
    ];
    $a = strtotime((string)$r['added_at']);
    if ($a !== false && $a > $maxAdded) $maxAdded = $a;
}

$dataPayload = ['version' => '1.0', 'count' => count($entries), 'entries' => $entries];
$etag = '"' . substr(hash('sha256', json_encode($dataPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16) . '"';

$body = json_encode([
    'version' => '1.0',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => count($entries),
    'entries' => $entries,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$lastModified = $maxAdded > 0 ? gmdate('D, d M Y H:i:s', $maxAdded) . ' GMT' : null;

$ifNoneMatch = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
$ifNoneMatchStripped = preg_replace('/^W\//', '', $ifNoneMatch);
if ($ifNoneMatchStripped !== '' && hash_equals($etag, $ifNoneMatchStripped)) {
    http_response_code(304);
    header('ETag: ' . $etag);
    if ($lastModified !== null) header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=300');
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('ETag: ' . $etag);
if ($lastModified !== null) header('Last-Modified: ' . $lastModified);
header('Cache-Control: public, max-age=300');
echo $body;
