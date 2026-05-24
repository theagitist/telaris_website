<?php
declare(strict_types=1);

/**
 * OpenAPI 3.1 annotations for the Pluriverse federation surface
 * (the coord-side, on www.telaris.ca).
 *
 * Scanned by zircote/swagger-php at request time on
 * GET /api/pluriverse/openapi.json. The classes here exist only to carry
 * PHP 8 attributes; they are NEVER instantiated, called, or autoloaded by
 * the runtime handlers. The actual endpoints live in sibling handler
 * files (e.g. ../identity_handler.php).
 *
 * Spec: P2P federation plan v10 § Standards and crypto (line 482),
 *       § Instance-side endpoint catalogue (line 217 — note the catalogue
 *       lists this exact path on www.telaris.ca with a coord-variant
 *       response).
 *
 * The doc's `info.version` MUST match the runtime `protocol_version`
 * served by /api/pluriverse/identity. Bump both at the same time.
 *
 * Distinct from the instance-side OpenAPI doc: this one describes the
 * coordination surface (no editor / authored-galaxy endpoints), the
 * IdentityEnvelope schema uses the coord shape (kind=pluriverse-coord,
 * no telaris_version, no pluriverse_endpoint), and the license is AGPL
 * (the Pluriverse codebase) not GPL (the instance codebase).
 */

namespace Telaris\PluriversePortal\OpenApi;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.1.0',
    info: new OA\Info(
        version: '1.0',
        title: 'Telaris Pluriverse Coordination Protocol',
        description: 'Coordination surface exposed by the Pluriverse at www.telaris.ca, '
            . 'symmetric with the per-instance /api/pluriverse/* surface served by every '
            . 'Telaris instance. See P2P federation plan v10 for the full specification.',
        license: new OA\License(
            name: 'AGPL-3.0-or-later',
            identifier: 'AGPL-3.0-or-later'
        )
    ),
    servers: [
        new OA\Server(url: 'https://www.telaris.ca', description: 'The Pluriverse'),
    ],
    tags: [
        new OA\Tag(name: 'pluriverse-public', description: 'Public read endpoints (no signature required).'),
        new OA\Tag(name: 'pluriverse-meta', description: 'Protocol metadata and discovery.'),
        new OA\Tag(name: 'pluriverse-operators', description: 'Operator-facing endpoints (application, auth, dashboard).'),
    ]
)]
final class OpenApiDocument {}

#[OA\Schema(
    schema: 'CoordIdentityEnvelope',
    description: 'Identity envelope returned by GET /api/pluriverse/identity on the Pluriverse. '
        . 'Distinguished from the instance-side variant by `kind: "pluriverse-coord"`.',
    required: [
        'kind',
        'hostname',
        'label',
        'pluriverse_version',
        'protocol_version',
        'public_key',
        'public_key_fingerprint',
    ],
    properties: [
        new OA\Property(property: 'kind', type: 'string', enum: ['pluriverse-coord'], description: 'Identity kind; always "pluriverse-coord" on this surface.'),
        new OA\Property(property: 'hostname', type: 'string', example: 'www.telaris.ca'),
        new OA\Property(property: 'label', type: 'string', example: 'Pluriverse'),
        new OA\Property(property: 'pluriverse_version', type: 'string', example: '1.0', description: 'Semver version of the Pluriverse application software.'),
        new OA\Property(property: 'protocol_version', type: 'string', enum: ['1.0'], description: 'Pluriverse protocol version this Pluriverse speaks.'),
        new OA\Property(property: 'public_key', type: 'string', format: 'byte', description: 'Base64-encoded Ed25519 public key (32 bytes) of the Pluriverse coordination identity.'),
        new OA\Property(property: 'public_key_fingerprint', type: 'string', minLength: 22, maxLength: 22, description: 'Base64url-encoded first 16 bytes of SHA-256(public_key), no padding.'),
    ]
)]
final class CoordIdentityEnvelopeSchema {}

#[OA\Schema(
    schema: 'ProblemDetails',
    description: 'RFC 9457 Problem Details for HTTP APIs. Returned for every error response.',
    required: ['type', 'title', 'status', 'detail', 'instance', 'code'],
    properties: [
        new OA\Property(property: 'type', type: 'string', format: 'uri', example: 'https://www.telaris.ca/docs/errors/not_found'),
        new OA\Property(property: 'title', type: 'string', example: 'Not Found'),
        new OA\Property(property: 'status', type: 'integer', example: 404),
        new OA\Property(property: 'detail', type: 'string', description: 'Longer human-readable explanation.'),
        new OA\Property(property: 'instance', type: 'string', description: 'Request path on which the error occurred.'),
        new OA\Property(property: 'code', type: 'string', description: 'Stable machine-readable error identifier.', example: 'not_found'),
        new OA\Property(property: 'retry_after', type: 'integer', description: 'Optional. Present on 429 / 503 responses.', nullable: true),
    ]
)]
final class ProblemDetailsSchema {}

#[OA\Get(
    path: '/api/pluriverse/identity',
    operationId: 'getCoordIdentity',
    summary: 'Pluriverse coordination identity envelope.',
    description: 'Returns the Pluriverse\'s coordination identity: hostname, label, version, '
        . 'protocol version, base64 public key, fingerprint. Public-read; no authentication. '
        . 'Rate limit 60 req/min/IP. Peers TOFU this on first contact and verify Pluriverse-signed '
        . 'pushes (key events, relay-forwarded messages) against the cached key.',
    tags: ['pluriverse-public', 'pluriverse-meta'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Coordination identity envelope.',
            content: new OA\JsonContent(ref: '#/components/schemas/CoordIdentityEnvelope')
        ),
        new OA\Response(
            response: 405,
            description: 'Method not allowed (only GET is accepted).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded (60 req/min/IP).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 503,
            description: 'Pluriverse has not been provisioned with a coordination identity yet.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
    ]
)]
final class GetCoordIdentityEndpoint {}

#[OA\Schema(
    schema: 'ContactEntry',
    description: 'A free-form contact channel (Matrix, XMPP, IRC, etc.) declared by the operator. '
        . 'The Pluriverse stores the JSON-encoded list as a single secretbox-encrypted column; the '
        . 'service name is not constrained to a fixed enumeration so an operator can declare anything.',
    required: ['service', 'user_id'],
    properties: [
        new OA\Property(property: 'service', type: 'string', minLength: 1, maxLength: 64, example: 'matrix', description: 'Name of the service (free-form).'),
        new OA\Property(property: 'user_id', type: 'string', minLength: 1, maxLength: 256, example: '@operator:matrix.org', description: 'Operator handle within that service.'),
    ]
)]
final class ContactEntrySchema {}

#[OA\Schema(
    schema: 'ApplicationRequest',
    description: 'Body of POST /api/pluriverse/operators/apply. The Pluriverse fetches the instance\'s '
        . 'identity envelope at the supplied pluriverse_endpoint and captures the public key itself, '
        . 'so the form does NOT collect public_key. Fingerprint cross-check is performed locally.',
    required: ['hostname', 'url', 'pluriverse_endpoint', 'operator_email', 'label'],
    properties: [
        new OA\Property(property: 'hostname', type: 'string', minLength: 4, maxLength: 255, pattern: '^[a-z0-9][a-z0-9.-]*[a-z0-9]$', example: 'mocambos.example.com', description: 'DNS-style label, lowercase, no scheme.'),
        new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://mocambos.example.com', description: 'Canonical https:// URL of the instance. Host must equal hostname.'),
        new OA\Property(property: 'pluriverse_endpoint', type: 'string', format: 'uri', example: 'https://mocambos.example.com/api/pluriverse/identity', description: 'URL where the instance serves its identity envelope.'),
        new OA\Property(property: 'operator_email', type: 'string', format: 'email', maxLength: 254, description: 'Magic-link target. PII-encrypted at rest.'),
        new OA\Property(property: 'label', type: 'string', minLength: 1, maxLength: 255, example: 'Mocambos archive', description: 'Operator-chosen editorial label.'),
        new OA\Property(property: 'editorial_framing', type: 'string', maxLength: 2000, description: 'Short prose describing the instance\'s editorial focus. Optional.'),
        new OA\Property(property: 'publishable_slugs', type: 'array', items: new OA\Items(type: 'string', pattern: '^[a-z0-9][a-z0-9-]{0,127}$'), description: 'Galaxy slugs the operator intends to publish through the Pluriverse. Optional.'),
        new OA\Property(property: 'bridges', type: 'array', items: new OA\Items(type: 'string', enum: ['mocambos']), description: 'Bridges this instance speaks. Currently only "mocambos". Optional.'),
        new OA\Property(property: 'other_contacts', type: 'array', maxItems: 8, items: new OA\Items(ref: '#/components/schemas/ContactEntry'), description: 'Secondary contact channels. Optional. PII-encrypted at rest.'),
        new OA\Property(property: 'locale', type: 'string', enum: ['en', 'es', 'pt', 'fr'], description: 'Locale of the submission, used for the acknowledgement email. Defaults to en.'),
    ]
)]
final class ApplicationRequestSchema {}

#[OA\Schema(
    schema: 'ApplicationResponse',
    description: 'Successful response from POST /api/pluriverse/operators/apply.',
    required: ['status', 'instance_id', 'public_key_fingerprint', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['pending']),
        new OA\Property(property: 'instance_id', type: 'integer', example: 42),
        new OA\Property(property: 'public_key_fingerprint', type: 'string', minLength: 22, maxLength: 22, description: 'Fingerprint of the public key captured from the instance\'s identity envelope. The operator can compare this against their own bin/init-identity --check to confirm the Pluriverse stored the correct key.'),
        new OA\Property(property: 'message', type: 'string'),
    ]
)]
final class ApplicationResponseSchema {}

#[OA\Post(
    path: '/api/pluriverse/operators/apply',
    operationId: 'submitApplication',
    summary: 'Operator submits an application to join the Pluriverse.',
    description: 'Accepts an applicant\'s instance description, fetches its /api/pluriverse/identity '
        . 'envelope to verify it self-identifies as a Telaris instance, captures the public key + '
        . 'fingerprint, encrypts the operator email and any secondary contacts at rest, inserts a '
        . 'pending row, mints a one-hour single-use magic-link token, and emails the verification '
        . 'URL to the operator. The pending row auto-expires after 48 hours if the magic link is '
        . 'never followed. Rate limit 5 req/hour/IP.',
    tags: ['pluriverse-operators'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/ApplicationRequest')
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Application received; check email for verification link.',
            content: new OA\JsonContent(ref: '#/components/schemas/ApplicationResponse')
        ),
        new OA\Response(
            response: 400,
            description: 'Request body missing or not JSON.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 409,
            description: 'An application already exists for this hostname or email.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 413,
            description: 'Request body exceeds 16 KB.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 422,
            description: 'Validation failed, hostname mismatch, or identity envelope unverifiable.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded (5 req/hour/IP).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 500,
            description: 'Database error processing the application.',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
    ]
)]
final class SubmitApplicationEndpoint {}

#[OA\Get(
    path: '/api/pluriverse/openapi.json',
    operationId: 'getOpenApiSpec',
    summary: 'OpenAPI 3.1 spec for the Pluriverse coordination surface.',
    description: 'Returns the OpenAPI 3.1 specification for every /api/pluriverse/* endpoint '
        . 'served by the Pluriverse. Public-read; no authentication. Rate limit 60 req/min/IP. '
        . 'Cache-validate via the Last-Modified header.',
    tags: ['pluriverse-public', 'pluriverse-meta'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'OpenAPI 3.1 document.',
            content: new OA\JsonContent(type: 'object')
        ),
        new OA\Response(
            response: 304,
            description: 'Not Modified (when If-Modified-Since matches Last-Modified).'
        ),
        new OA\Response(
            response: 405,
            description: 'Method not allowed (only GET is accepted).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
        new OA\Response(
            response: 429,
            description: 'Rate limit exceeded (60 req/min/IP).',
            content: new OA\JsonContent(ref: '#/components/schemas/ProblemDetails')
        ),
    ]
)]
final class GetOpenApiEndpoint {}
