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
