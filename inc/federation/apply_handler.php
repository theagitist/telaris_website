<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/operators/apply
 *
 * Operator-application intake. Accepts a JSON body describing the
 * applicant's instance, fetches the instance's identity envelope to confirm
 * it self-identifies as a Telaris instance, captures its Ed25519 public key,
 * PII-encrypts the operator email + optional secondary contacts, inserts a
 * pending row into `instances`, mints a magic-link token, and emails the
 * verification URL to the applicant.
 *
 * Spec: P2P federation plan v10 § 126 + the Stage 2 application surface
 * design note (vault). Verification of the magic link itself is in 2g-i.
 *
 * Errors use RFC 9457 Problem Details via federation_router_problem.
 */

// The /api/pluriverse/* router short-circuits in index.php BEFORE the page
// bootstrap that loads config.php + inc/db.php (the identity endpoint
// does not need them). This handler is DB-heavy, so bring up the DB layer
// explicitly. config.php already chains into inc/db.php.
require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/identity_client.php';

// -----------------------------------------------------------------------
// Rate limit: 5 requests per hour per source IP (APCu best-effort; nginx
// limit_req is the load-bearing layer in production).
// -----------------------------------------------------------------------
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_apply:' . date('YmdH') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 5) {
        federation_router_problem(
            429,
            'rate_limited',
            'Too many application attempts from this IP this hour; retry within an hour.',
            '/api/pluriverse/operators/apply'
        );
        return;
    }
}

// -----------------------------------------------------------------------
// Parse + validate JSON body.
// -----------------------------------------------------------------------
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    federation_router_problem(400, 'empty_body', 'Request body is empty; expected JSON.', '/api/pluriverse/operators/apply');
    return;
}
if (strlen($raw) > 16384) {
    federation_router_problem(413, 'body_too_large', 'Request body exceeds 16 KB.', '/api/pluriverse/operators/apply');
    return;
}
try {
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    federation_router_problem(400, 'invalid_json', 'Request body is not valid JSON: ' . $e->getMessage(), '/api/pluriverse/operators/apply');
    return;
}
if (!is_array($body)) {
    federation_router_problem(400, 'invalid_body', 'Request body must be a JSON object.', '/api/pluriverse/operators/apply');
    return;
}

$errors = [];

$url = trim((string)($body['url'] ?? ''));
if (!preg_match('#^https://#', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
    $errors[] = 'url must be a valid https:// URL';
}

// Derive hostname from the URL host component. Form callers do not
// send hostname any more; API callers may still supply it explicitly
// and we will respect / cross-check that value against the URL host.
$urlHost = '';
if ($url !== '') {
    $parsed = parse_url($url);
    if (is_array($parsed) && isset($parsed['host']) && is_string($parsed['host'])) {
        $urlHost = strtolower($parsed['host']);
    }
}
$hostnameRaw = isset($body['hostname']) ? trim((string)$body['hostname']) : '';
$hostname = $hostnameRaw !== '' ? strtolower($hostnameRaw) : $urlHost;
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $hostname) || strlen($hostname) < 4 || strlen($hostname) > 255) {
    $errors[] = 'url must include a valid hostname (a-z0-9 . -), 4..255 chars';
}

// pluriverse_endpoint is now optional. When the operator omits it we
// derive `<url>/api/pluriverse/identity`, which is where every Telaris
// instance serves the envelope by convention. The form no longer asks
// for it; the API still accepts an explicit value for the rare case of
// an instance running the federation surface under a non-default path.
$endpoint = trim((string)($body['pluriverse_endpoint'] ?? ''));
if ($endpoint === '' && $url !== '' && preg_match('#^https://#', $url)) {
    $endpoint = rtrim($url, '/') . '/api/pluriverse/identity';
}
if ($endpoint === '' || !preg_match('#^https://#', $endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
    $errors[] = 'pluriverse_endpoint must be a valid https:// URL (omit to default to <url>/api/pluriverse/identity)';
}

$operatorEmail = trim((string)($body['operator_email'] ?? ''));
if (!filter_var($operatorEmail, FILTER_VALIDATE_EMAIL) || strlen($operatorEmail) > 254) {
    $errors[] = 'operator_email must be a valid email address (max 254 chars)';
}

$label = trim((string)($body['label'] ?? ''));
if ($label === '' || mb_strlen($label) > 255) {
    $errors[] = 'label is required, max 255 chars';
}

$editorialFraming = (string)($body['editorial_framing'] ?? '');
if (mb_strlen($editorialFraming) > 2000) {
    $errors[] = 'editorial_framing exceeds 2000 chars';
}

$publishableSlugs = $body['publishable_slugs'] ?? [];
if (!is_array($publishableSlugs)) {
    $errors[] = 'publishable_slugs must be an array';
    $publishableSlugs = [];
} else {
    if (count($publishableSlugs) === 0) {
        $errors[] = 'publishable_slugs must include at least one slug; load galaxies from your instance and pick at least one';
    }
    foreach ($publishableSlugs as $i => $s) {
        if (!is_string($s) || !preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/', $s)) {
            $errors[] = "publishable_slugs[{$i}] must be a kebab-case slug 1..128 chars";
        }
    }
}

// Bridges intentionally not collected on the apply form: the operator
// surface for bridge configuration is admin-mediated and bridge-specific.
// Keeping the column on the schema so admin can set it later. Accepting
// the field in the API body for forward-compat and CLI tooling, but
// silently ignoring anything an applicant submits.
$bridges = [];

$otherContacts = $body['other_contacts'] ?? [];
if (!is_array($otherContacts)) {
    $errors[] = 'other_contacts must be an array of {service, user_id} objects';
    $otherContacts = [];
} else {
    if (count($otherContacts) > 8) {
        $errors[] = 'other_contacts must contain at most 8 entries';
    }
    foreach ($otherContacts as $i => $entry) {
        if (!is_array($entry) || !isset($entry['service'], $entry['user_id'])
            || !is_string($entry['service']) || !is_string($entry['user_id'])) {
            $errors[] = "other_contacts[{$i}] must be {service, user_id} with both as strings";
            continue;
        }
        $service = trim($entry['service']);
        $userId = trim($entry['user_id']);
        if ($service === '' || mb_strlen($service) > 64) {
            $errors[] = "other_contacts[{$i}].service required, 1..64 chars";
        }
        if ($userId === '' || mb_strlen($userId) > 256) {
            $errors[] = "other_contacts[{$i}].user_id required, 1..256 chars";
        }
    }
}

$locale = (string)($body['locale'] ?? 'en');
if (!in_array($locale, ['en', 'es', 'pt', 'fr'], true)) {
    $locale = 'en';
}

if ($errors !== []) {
    federation_router_problem(
        422,
        'validation_failed',
        implode('; ', $errors),
        '/api/pluriverse/operators/apply'
    );
    return;
}

// If hostname was supplied explicitly (API caller, not the form), the
// URL host must equal the declared hostname.
if ($hostnameRaw !== '' && $urlHost !== $hostname) {
    federation_router_problem(422, 'hostname_url_mismatch', 'hostname does not match the host in url', '/api/pluriverse/operators/apply');
    return;
}

// -----------------------------------------------------------------------
// Existing-application check. Apply once is the rule for v1; an operator
// who wants to change instance can do that from /dashboard after they
// finish verification. Three uniqueness keys to honour: hostname, email,
// and the operator-chosen Name (DB column `label`). One SELECT covers
// all three; we post-process the matched row to emit a specific code
// per clash so the form can highlight the right field.
// -----------------------------------------------------------------------
try {
    $pdo = getDB();
    db_ensure_instances_table();
    $emailLookupHash = federation_pii_lookup_hash($operatorEmail);
    $stmt = $pdo->prepare("
        SELECT id, admission_status, hostname, operator_email_lookup_hash, label
        FROM instances
        WHERE hostname = :hostname OR operator_email_lookup_hash = :lookup OR label = :label
        LIMIT 1
    ");
    $stmt->bindValue(':hostname', $hostname);
    $stmt->bindValue(':lookup', $emailLookupHash, PDO::PARAM_LOB);
    $stmt->bindValue(':label', $label);
    $stmt->execute();
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing !== false) {
        $code = 'application_exists';
        $detail = "An application is already on file for this hostname, email, or name (status: {$existing['admission_status']}).";
        if (strcasecmp((string)$existing['hostname'], $hostname) === 0) {
            $code = 'hostname_taken';
            $detail = "An application for hostname '{$hostname}' is already on file (status: {$existing['admission_status']}).";
        } elseif (hash_equals((string)$existing['operator_email_lookup_hash'], $emailLookupHash)) {
            $code = 'email_taken';
            $detail = "An application from this email address is already on file (status: {$existing['admission_status']}).";
        } elseif (strcasecmp((string)$existing['label'], $label) === 0) {
            $code = 'name_taken';
            $detail = "The name '{$label}' is already taken by another instance. Pick a different name.";
        }
        federation_router_problem(409, $code, $detail, '/api/pluriverse/operators/apply');
        return;
    }
} catch (Throwable $e) {
    error_log('apply: pre-insert conflict check: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Application could not be processed; please retry shortly.', '/api/pluriverse/operators/apply');
    return;
}

// -----------------------------------------------------------------------
// Identity fetch + cross-check. The Pluriverse fetches the instance's
// /api/pluriverse/identity endpoint, confirms kind=telaris-instance, and
// captures the public key + fingerprint. Any failure here is 422
// (unprocessable applicant) rather than 5xx (the Pluriverse is fine).
// -----------------------------------------------------------------------
require_once __DIR__ . '/identity_client.php';
try {
    $identity = federation_fetch_identity($endpoint);
} catch (FederationIdentityFetchError $e) {
    federation_router_problem(
        422,
        'identity_unverifiable',
        'Could not verify the supplied pluriverse_endpoint: ' . $e->getMessage(),
        '/api/pluriverse/operators/apply'
    );
    return;
}

// Hostname-of-record cross-check: the identity envelope's hostname should
// match the applicant's declared hostname. Lenient (case-insensitive).
if (strtolower($identity['hostname']) !== strtolower($hostname)) {
    federation_router_problem(
        422,
        'hostname_identity_mismatch',
        "Declared hostname '{$hostname}' does not match identity envelope hostname '{$identity['hostname']}'",
        '/api/pluriverse/operators/apply'
    );
    return;
}

// -----------------------------------------------------------------------
// Insert + mint magic link + send acknowledgement.
// -----------------------------------------------------------------------
try {
    $instanceId = db_insert_instance_application([
        'hostname' => $hostname,
        'url' => $url,
        'pluriverse_endpoint' => $endpoint,
        'operator_email' => $operatorEmail,
        'label' => $label,
        'editorial_framing' => $editorialFraming,
        'publishable_slugs' => $publishableSlugs,
        'bridges' => $bridges,
        'other_contacts' => $otherContacts,
    ], $identity);
} catch (Throwable $e) {
    error_log('apply: INSERT instances failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Application could not be saved; please retry shortly.', '/api/pluriverse/operators/apply');
    return;
}

try {
    $tokenRaw = db_create_magic_link_token($emailLookupHash, 3600);
    $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);
} catch (Throwable $e) {
    error_log("apply: magic-link mint failed (instance id={$instanceId}): " . $e->getMessage());
    // Row inserted; operator can request a fresh link via the (yet-to-ship)
    // /api/pluriverse/operators/request-magic-link endpoint in 2g-i.
    $tokenUrl = null;
}

if ($tokenUrl !== null) {
    require_once dirname(__DIR__) . '/mail.php';
    $subject = 'Verify your Pluriverse application';
    $bodyText = "Hello,\n\n"
              . "We received your application for the Telaris instance at {$hostname}.\n"
              . "Confirm your email address by visiting the link below within the next hour:\n\n"
              . "  {$tokenUrl}\n\n"
              . "After confirmation, an admin will review your application and let you\n"
              . "know when your instance is published in the Pluriverse.\n\n"
              . "If you did not submit this application, you can ignore this email; the\n"
              . "pending record will be removed automatically within 48 hours.\n\n"
              . "Pluriverse · https://www.telaris.ca/\n";
    try {
        pluriverse_send_mail($operatorEmail, $subject, $bodyText);
    } catch (Throwable $e) {
        error_log("apply: ack mail to {$operatorEmail} failed (instance id={$instanceId}): " . $e->getMessage());
        // Row + token persist; operator can request a fresh link in 2g-i.
    }
}

// -----------------------------------------------------------------------
// 201 Created. We do NOT echo the magic-link in the response (only the
// inbox sees it); we do not echo any PII either.
// -----------------------------------------------------------------------
http_response_code(201);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'pending',
    'instance_id' => $instanceId,
    'public_key_fingerprint' => $identity['public_key_fingerprint'],
    'message' => 'Application received. Check your email for a verification link; it expires in one hour. The pending application itself expires in 48 hours if not verified.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
