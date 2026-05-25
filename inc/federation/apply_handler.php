<?php
declare(strict_types=1);

/**
 * POST /api/pluriverse/operators/apply
 *
 * Signed-only operator-application intake. The /apply page form was retired
 * 2026-05-24 in favour of an instance-side admin action: an authenticated
 * operator clicks "Publish to Pluriverse" on their own Telaris instance, the
 * instance pre-populates URL / email / galaxies from local state, the
 * operator confirms the Name, and the instance signs the request with its
 * pluriverse.key (RFC 9421) and posts it here.
 *
 * Why signed-only:
 *   - Identity comes for free: the Pluriverse fetches the signing
 *     instance's /api/pluriverse/identity, captures the public key, and
 *     verifies the request body against that key. The signer IS the
 *     operator of the instance at <keyid hostname>.
 *   - No spam vector: an attacker would need to stand up a real Telaris
 *     instance at a publicly-resolvable HTTPS URL and forfeit a
 *     pluriverse.key to it. That is expensive enough that the
 *     unauthenticated form was a worse threat surface.
 *   - No cross-origin proxy endpoints needed: the instance has its own
 *     galaxies / email / URL locally.
 *
 * Verification flow per request:
 *   1. Parse the Signature-Input header (sig1 label) to extract the keyid.
 *      keyid format: "<hostname>:<fingerprint>" (see instance-side
 *      bin/init-identity --check and inc/federation/identity.php).
 *   2. Fetch https://<hostname>/api/pluriverse/identity, validate
 *      kind=telaris-instance, recompute fingerprint, confirm match against
 *      the keyid's fingerprint (defence against a stale key claim).
 *   3. Run federation_http_sig_verify against the body using the
 *      identity's public_key. Expected tag: pluriverse-apply.
 *   4. Process the body (PII encryption, DB insert, magic-link mint, ack
 *      email) just like the previous public-form path did.
 *
 * Errors use RFC 9457 Problem Details via federation_router_problem.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/identity_client.php';
require_once __DIR__ . '/http_sig.php';

// -----------------------------------------------------------------------
// Rate limit: 20 req/hour/IP (signed endpoint; gentler than the old
// anonymous 5/hour because the trust model is stronger).
// -----------------------------------------------------------------------
if (function_exists('apcu_inc')) {
    $rateIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '-';
    $bucket = 'pluriverse_apply:' . date('YmdH') . ':' . $rateIp;
    $success = false;
    $count = apcu_inc($bucket, 1, $success, 3700);
    if ($count !== false && (int)$count > 20) {
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
// Read the body once. Needed both for signature verification (content
// digest) and for JSON parsing.
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

// -----------------------------------------------------------------------
// Step 1: extract keyid from Signature-Input before we know the public key.
// -----------------------------------------------------------------------
$sigInput = $_SERVER['HTTP_SIGNATURE_INPUT'] ?? '';
$sigValue = $_SERVER['HTTP_SIGNATURE'] ?? '';
if ($sigInput === '' || $sigValue === '') {
    federation_router_problem(
        401,
        'signature_required',
        'POST /api/pluriverse/operators/apply requires an RFC 9421 HTTP Signature from your instance\'s pluriverse.key. Submit via your instance\'s admin "Join the Pluriverse" action.',
        '/api/pluriverse/operators/apply'
    );
    return;
}
[$inputLabel, $inputRest] = federation_http_sig_split_label($sigInput);
if ($inputLabel !== 'sig1') {
    federation_router_problem(401, 'invalid_signature', 'Unsupported Signature-Input label.', '/api/pluriverse/operators/apply');
    return;
}
$parsed = federation_http_sig_parse_inner_list($inputRest);
if ($parsed === null) {
    federation_router_problem(401, 'invalid_signature', 'Malformed Signature-Input inner list.', '/api/pluriverse/operators/apply');
    return;
}
[, $sigParams] = $parsed;
$keyid = isset($sigParams['keyid']) && is_string($sigParams['keyid']) ? $sigParams['keyid'] : '';
if ($keyid === '' || strpos($keyid, ':') === false) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid must be "<hostname>:<fingerprint>".', '/api/pluriverse/operators/apply');
    return;
}
[$signerHostname, $signerFingerprint] = explode(':', $keyid, 2);
$signerHostname = strtolower(trim($signerHostname));
$signerFingerprint = trim($signerFingerprint);
if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $signerHostname) || strlen($signerHostname) > 255) {
    federation_router_problem(401, 'invalid_keyid', 'Signature keyid hostname is malformed.', '/api/pluriverse/operators/apply');
    return;
}

// -----------------------------------------------------------------------
// Step 2: fetch the instance's identity envelope to obtain the public key.
// federation_fetch_identity already validates kind=telaris-instance,
// protocol_version, and recomputes the fingerprint.
// -----------------------------------------------------------------------
try {
    $identity = federation_fetch_identity('https://' . $signerHostname . '/api/pluriverse/identity');
} catch (FederationIdentityFetchError $e) {
    federation_router_problem(
        422,
        'identity_unverifiable',
        'Could not verify the signing instance: ' . $e->getMessage(),
        '/api/pluriverse/operators/apply'
    );
    return;
}
if (!hash_equals($identity['public_key_fingerprint'], $signerFingerprint)) {
    federation_router_problem(
        401,
        'fingerprint_mismatch',
        "The instance at {$signerHostname} currently publishes a key with fingerprint {$identity['public_key_fingerprint']}, not the {$signerFingerprint} in the request keyid. If you rotated, sign with the new key.",
        '/api/pluriverse/operators/apply'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 3: run RFC 9421 verify with the captured public key.
// -----------------------------------------------------------------------
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verifyRequest = [
    'method' => 'POST',
    'target_uri' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.telaris.ca') . ($_SERVER['REQUEST_URI'] ?? '/api/pluriverse/operators/apply'),
    'headers' => [
        'signature'       => $sigValue,
        'signature-input' => $sigInput,
        'host'            => $_SERVER['HTTP_HOST'] ?? '',
        'date'            => $_SERVER['HTTP_DATE'] ?? '',
        'content-type'    => $_SERVER['CONTENT_TYPE'] ?? '',
        'content-length'  => $_SERVER['CONTENT_LENGTH'] ?? (string)strlen($raw),
        'content-digest'  => $_SERVER['HTTP_CONTENT_DIGEST'] ?? '',
    ],
    'body' => $raw,
];
$verify = federation_http_sig_verify($verifyRequest, $identity['public_key'], [
    'expected_tag' => 'pluriverse-apply',
]);
if (!$verify['valid']) {
    federation_router_problem(
        401,
        'signature_invalid',
        'Signature verification failed: ' . $verify['reason'],
        '/api/pluriverse/operators/apply'
    );
    return;
}

// -----------------------------------------------------------------------
// Step 4: parse + validate the JSON body. The signer is now established;
// the body fields can be trusted to have come from that instance.
// -----------------------------------------------------------------------
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

// Hostname is the signer-keyid hostname. The body MAY echo it for clarity;
// if it does, it must match the signer.
$urlHost = '';
if ($url !== '') {
    $parsed = parse_url($url);
    if (is_array($parsed) && isset($parsed['host']) && is_string($parsed['host'])) {
        $urlHost = strtolower($parsed['host']);
    }
}
if ($urlHost !== '' && $urlHost !== $signerHostname) {
    $errors[] = "url host '{$urlHost}' does not match the signer hostname '{$signerHostname}'";
}
$hostname = $signerHostname;

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

// Anti-siloing rule (v10 plan + operator directive 2026-05-25): the federation
// publishes every galaxy the instance has, no per-galaxy opt-out. Empty list
// is allowed at apply time; new galaxies will be picked up by the
// Pluriverse-side rescan mechanism (stage 2j+) as they appear on the
// instance.
$publishableSlugs = $body['publishable_slugs'] ?? [];
if (!is_array($publishableSlugs)) {
    $errors[] = 'publishable_slugs must be an array';
    $publishableSlugs = [];
} else {
    foreach ($publishableSlugs as $i => $s) {
        if (!is_string($s) || !preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/', $s)) {
            $errors[] = "publishable_slugs[{$i}] must be a kebab-case slug 1..128 chars";
        }
    }
}

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

// -----------------------------------------------------------------------
// Sweep stale-pending rows past their 24h verify window into 'expired',
// then run the conflict check. An expired prior attempt for the same
// hostname/email is replaced (delete + insert) so the operator can
// re-join without admin intervention.
// -----------------------------------------------------------------------
try {
    $pdo = getDB();
    db_ensure_instances_table();
    db_expire_stale_pending_instances();
    $emailLookupHash = federation_pii_lookup_hash($operatorEmail);

    // Look for any existing row matching by hostname OR email OR label.
    // If matches are 'expired' AND share both hostname and email (i.e.
    // the same operator's prior attempt), drop them and continue. Any
    // non-expired collision is still a 409.
    $stmt = $pdo->prepare("
        SELECT id, admission_status, hostname, operator_email_lookup_hash, label
        FROM instances
        WHERE hostname = :hostname OR operator_email_lookup_hash = :lookup OR label = :label
    ");
    $stmt->bindValue(':hostname', $hostname);
    $stmt->bindValue(':lookup', $emailLookupHash, PDO::PARAM_LOB);
    $stmt->bindValue(':label', $label);
    $stmt->execute();
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($matches as $row) {
        $sameHost = strcasecmp((string)$row['hostname'], $hostname) === 0;
        $sameEmail = hash_equals((string)$row['operator_email_lookup_hash'], $emailLookupHash);
        $sameLabel = strcasecmp((string)$row['label'], $label) === 0;
        if ($row['admission_status'] === 'expired' && $sameHost && $sameEmail) {
            // Same operator's expired prior attempt. Drop it (and its log
            // rows via FK cascade) so the new INSERT below can land.
            db_delete_instance((int)$row['id']);
            continue;
        }
        // Live conflict (any non-expired status, or expired but a label-only
        // collision from a different operator). Return 409.
        $code = 'application_exists';
        $detail = "An application is already on file for this hostname, email, or name (status: {$row['admission_status']}).";
        if ($sameHost) {
            $code = 'hostname_taken';
            $detail = "An application for hostname '{$hostname}' is already on file (status: {$row['admission_status']}).";
        } elseif ($sameEmail) {
            $code = 'email_taken';
            $detail = "An application from this email address is already on file (status: {$row['admission_status']}).";
        } elseif ($sameLabel) {
            $code = 'name_taken';
            $detail = "The name '{$label}' is already taken by another instance. Pick a different name.";
        }
        federation_router_problem(409, $code, $detail, '/api/pluriverse/operators/apply');
        return;
    }
} catch (Throwable $e) {
    error_log('apply: pre-insert conflict check: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Request could not be processed; please retry shortly.', '/api/pluriverse/operators/apply');
    return;
}

// -----------------------------------------------------------------------
// Insert + mint magic link + send acknowledgement.
// -----------------------------------------------------------------------
try {
    $instanceId = db_insert_instance_application([
        'hostname' => $hostname,
        'url' => $url,
        'pluriverse_endpoint' => 'https://' . $signerHostname . '/api/pluriverse/identity',
        'operator_email' => $operatorEmail,
        'label' => $label,
        'editorial_framing' => $editorialFraming,
        'publishable_slugs' => $publishableSlugs,
        'bridges' => [],
        'other_contacts' => $otherContacts,
        'locale' => $locale,
    ], $identity);
} catch (Throwable $e) {
    error_log('apply: INSERT instances failed: ' . $e->getMessage());
    federation_router_problem(500, 'database_error', 'Request could not be saved; please retry shortly.', '/api/pluriverse/operators/apply');
    return;
}

try {
    $tokenRaw = db_create_magic_link_token($emailLookupHash, 86400);
    $tokenUrl = 'https://www.telaris.ca/operators/verify-magic-link?t=' . federation_token_url_encode($tokenRaw);
} catch (Throwable $e) {
    error_log("apply: magic-link mint failed (instance id={$instanceId}): " . $e->getMessage());
    $tokenUrl = null;
}

if ($tokenUrl !== null) {
    require_once dirname(__DIR__) . '/mail.php';
    $emailLocale = in_array($locale, ['en', 'es', 'pt', 'fr'], true) ? $locale : 'en';
    $emailBodies = [
        'en' => [
            'subject' => 'Verify your Pluriverse join request',
            'body' => "Hello,\n\n"
                    . "We received your request to join the Pluriverse from the Telaris\n"
                    . "instance at {$hostname}. Confirm your email address by visiting the\n"
                    . "link below within the next 24 hours:\n\n"
                    . "  {$tokenUrl}\n\n"
                    . "After confirmation, an admin will review your request and let you\n"
                    . "know when your instance is published in the Pluriverse.\n\n"
                    . "If you do not confirm in that window, the request will expire and\n"
                    . "a fresh one can be submitted from the instance admin panel.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'es' => [
            'subject' => 'Verifica tu solicitud para unirte a la Pluriverse',
            'body' => "Hola,\n\n"
                    . "Recibimos tu solicitud para unirte a la Pluriverse desde la instancia\n"
                    . "de Telaris en {$hostname}. Confirma tu dirección de correo visitando\n"
                    . "el enlace de abajo dentro de las próximas 24 horas:\n\n"
                    . "  {$tokenUrl}\n\n"
                    . "Tras la confirmación, alguien con acceso de administración revisará\n"
                    . "tu solicitud y te avisará cuando tu instancia esté publicada en la\n"
                    . "Pluriverse.\n\n"
                    . "Si no se confirma dentro de ese plazo, la solicitud caducará y se\n"
                    . "puede enviar una nueva desde el panel de administración de la\n"
                    . "instancia.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'pt' => [
            'subject' => 'Verifique seu pedido de adesão à Pluriverse',
            'body' => "Olá,\n\n"
                    . "Recebemos seu pedido para participar da Pluriverse a partir da\n"
                    . "instância de Telaris em {$hostname}. Confirme seu endereço de email\n"
                    . "visitando o link abaixo nas próximas 24 horas:\n\n"
                    . "  {$tokenUrl}\n\n"
                    . "Após a confirmação, quem tem acesso de administração vai revisar\n"
                    . "seu pedido e avisará quando sua instância estiver publicada na\n"
                    . "Pluriverse.\n\n"
                    . "Se não houver confirmação nesse prazo, o pedido vai expirar e um\n"
                    . "novo pode ser enviado pelo painel de administração da instância.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
        'fr' => [
            'subject' => 'Vérifie ta demande d\'adhésion à la Pluriverse',
            'body' => "Bonjour,\n\n"
                    . "Nous avons reçu ta demande d'adhésion à la Pluriverse depuis\n"
                    . "l'instance Telaris à {$hostname}. Confirme ton adresse courriel en\n"
                    . "visitant le lien ci-dessous dans les 24 heures qui viennent :\n\n"
                    . "  {$tokenUrl}\n\n"
                    . "Une fois confirmée, ta demande sera revue depuis le compte\n"
                    . "d'administration et tu recevras un avis dès que ton instance sera\n"
                    . "publiée dans la Pluriverse.\n\n"
                    . "Sans confirmation dans ce délai, la demande va expirer et une\n"
                    . "nouvelle peut être envoyée depuis le panneau d'administration de\n"
                    . "l'instance.\n\n"
                    . "Pluriverse - https://www.telaris.ca/\n",
        ],
    ];
    $tpl = $emailBodies[$emailLocale];
    try {
        pluriverse_send_mail($operatorEmail, $tpl['subject'], $tpl['body']);
    } catch (Throwable $e) {
        error_log("apply: ack mail to {$operatorEmail} failed (instance id={$instanceId}): " . $e->getMessage());
    }
}

http_response_code(201);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => 'pending',
    'instance_id' => $instanceId,
    'public_key_fingerprint' => $identity['public_key_fingerprint'],
    'message' => 'Request received. Check your email for a verification link; both the link and the pending request itself expire in 24 hours. After that, the operator can submit a fresh request from the instance admin panel.',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
