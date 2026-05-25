<?php
declare(strict_types=1);

/**
 * GET /api/pluriverse/peers.json
 *
 * Public read of the published-instance directory. Other Pluriverse
 * peers (federated instances) pull this endpoint to learn about each
 * other: hostname, public key, fingerprint, framing, locale, the most
 * recent key rotation (if any), the last_seen_at heartbeat, and the
 * `is_highlighted` curation flag.
 *
 * Only instances with admission_status = 'published' are exposed. The
 * other statuses (pending, verified, rejected, blacklisted, outdated,
 * withdrawn, expired, revoked) are operator-side or admin-side state
 * that the wider federation has no reason to see.
 *
 * Response is plain JSON for this chunk; a JWS-signed envelope (Ed25519
 * + the Pluriverse coord key) lands as a follow-up before stages 3+
 * ship the peer-side pull verifier. Until then, peers trust the
 * Pluriverse over HTTPS to the same extent they trust the identity
 * endpoint (which is also unsigned).
 *
 * Caching: ETag (sha256 prefix of body) + Last-Modified (max
 * instances.updated_at among published rows) + Cache-Control:
 * public, max-age=300. Conditional GET via If-None-Match yields 304.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/identity.php';

try {
    $pdo = getDB();
    db_ensure_instances_table();
    $stmt = $pdo->query("
        SELECT
            hostname, url, label,
            public_key, previous_public_key,
            key_rotated_at, rotation_reason,
            editorial_framing, publishable_slugs, bridges,
            locale, is_highlighted,
            last_seen_at, created_at, updated_at
        FROM instances
        WHERE admission_status = 'published'
        ORDER BY is_highlighted DESC, label ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('peers_handler: query failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Could not read the peer directory.', '/api/pluriverse/peers.json');
    return;
}

$maxUpdated = 0;
$peers = [];
foreach ($rows as $r) {
    $publicKey = (string)$r['public_key'];
    $previous = $r['previous_public_key'] !== null && $r['previous_public_key'] !== ''
        ? (string)$r['previous_public_key']
        : null;
    $peers[] = [
        'hostname' => (string)$r['hostname'],
        'url' => (string)$r['url'],
        'label' => (string)$r['label'],
        'public_key' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
        'public_key_fingerprint' => federation_compute_fingerprint($publicKey),
        'previous_public_key' => $previous !== null
            ? rtrim(strtr(base64_encode($previous), '+/', '-_'), '=')
            : null,
        'key_rotated_at' => $r['key_rotated_at'] !== null
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['key_rotated_at']))
            : null,
        'rotation_reason' => $r['rotation_reason'],
        'editorial_framing' => $r['editorial_framing'],
        'publishable_slugs' => $r['publishable_slugs'] !== null
            ? json_decode((string)$r['publishable_slugs'], true) ?: []
            : [],
        'bridges' => $r['bridges'] !== null
            ? json_decode((string)$r['bridges'], true) ?: []
            : [],
        'locale' => (string)$r['locale'],
        'is_highlighted' => (bool)$r['is_highlighted'],
        'last_seen_at' => $r['last_seen_at'] !== null
            ? gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['last_seen_at']))
            : null,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['created_at'])),
    ];
    $u = strtotime((string)$r['updated_at']);
    if ($u !== false && $u > $maxUpdated) $maxUpdated = $u;
}

// ETag is data-driven, not response-driven: it must stay stable while the
// underlying rows haven't changed even though `generated_at` ticks. Hash
// the peers payload sans `generated_at`.
$dataPayload = ['version' => '1.0', 'count' => count($peers), 'peers' => $peers];
$etag = '"' . substr(hash('sha256', json_encode($dataPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16) . '"';

$body = json_encode([
    'version' => '1.0',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => count($peers),
    'peers' => $peers,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$lastModified = $maxUpdated > 0 ? gmdate('D, d M Y H:i:s', $maxUpdated) . ' GMT' : null;

// Conditional GET: strip the W/ weak validator prefix that Cloudflare adds
// to outbound ETags. RFC 7232 says weak match is acceptable for content
// freshness checks.
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
