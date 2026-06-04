<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for Pluriverse HTTP endpoint-handler tests.
 *
 * The federation handlers (relay_handler, peer_blacklist_notice_handler,
 * key_events_handler, ...) are flat scripts that read $_SERVER / $_GET /
 * php://input and emit via http_response_code() + header() + echo, then
 * `return` at top level (they are designed to be `include`d by router.php).
 *
 * To exercise them without a live web server this harness:
 *
 *   1. Feeds a request body through php://input via a scoped stream wrapper
 *      (php:// is unregistered + re-registered only for the duration of the
 *      include, then restored, so json/echo/output-buffering are untouched).
 *   2. Populates $_SERVER / $_GET to look like an nginx+fpm request.
 *   3. Runs the handler under output buffering and reads back the response
 *      status (http_response_code() works in the CLI SAPI) and body.
 *
 * It also provides Ed25519 HTTP-Signature signing (so a request can be made
 * to verify as a known published instance) and a raw `instances` seeder.
 *
 * Header VALUES cannot be observed in the CLI SAPI (headers_list() is empty,
 * no Xdebug here), so assertions are on status code + JSON body, which is
 * where every handler's branch decisions surface anyway.
 */
abstract class PluriverseHandlerTestCase extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/inc/federation/problem.php';
        require_once dirname(__DIR__, 2) . '/inc/federation/http_sig.php';
        require_once dirname(__DIR__, 2) . '/inc/federation/identity.php';
        require_once dirname(__DIR__, 2) . '/inc/db_federation.php';
    }

    /**
     * Run a handler against a simulated request.
     *
     * @param string               $handlerRelPath e.g. 'inc/federation/relay_handler.php'
     * @param array<string,string> $server         extra $_SERVER entries (HTTP_*, CONTENT_*, ...)
     * @param string               $body           raw request body (php://input)
     * @param array<string,string> $get            $_GET entries
     * @return array{status:int, body:string, json:array|null}
     */
    protected function dispatch(string $handlerRelPath, array $server = [], string $body = '', array $get = []): array
    {
        $handler = dirname(__DIR__, 2) . '/' . ltrim($handlerRelPath, '/');

        // Reset request-scoped globals each call so tests don't bleed.
        $_GET = $get;
        $_POST = [];
        $_SERVER = array_merge([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'www.telaris.ca',
            'HTTPS' => 'on',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => '203.0.113.7',
        ], $server);

        TestInputStream::$data = $body;
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', TestInputStream::class);

        http_response_code(200); // reset the process-global before each run
        ob_start();
        try {
            // A handler's top-level `return` returns from the include; wrap so
            // it never short-circuits the test method.
            (static function () use ($handler): void {
                require $handler;
            })();
        } finally {
            $out = ob_get_clean();
            stream_wrapper_restore('php');
        }

        $status = http_response_code();
        $json = null;
        if ($out !== '' && $out[0] === '{') {
            $decoded = json_decode($out, true);
            if (is_array($decoded)) $json = $decoded;
        }
        return ['status' => is_int($status) ? $status : 0, 'body' => $out, 'json' => $json];
    }

    // ---- crypto fixtures --------------------------------------------------

    /** @return array{0:string,1:string} [secretKey, publicKey] */
    protected function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [sodium_crypto_sign_secretkey($kp), sodium_crypto_sign_publickey($kp)];
    }

    /**
     * Produce the $_SERVER signing headers for a signed POST request to a
     * federation endpoint, signed with $secretKey under keyid host:fingerprint.
     *
     * @return array<string,string>
     */
    protected function signedServer(
        string $path,
        string $body,
        string $secretKey,
        string $keyid,
        string $tag,
        array $extraParams = []
    ): array {
        $url = 'https://www.telaris.ca' . $path;
        $headers = [
            'Host' => 'www.telaris.ca',
            'Date' => gmdate('D, d M Y H:i:s') . ' GMT',
            'Content-Type' => 'application/json',
        ];
        $signed = federation_http_sig_sign(
            ['method' => 'POST', 'target_uri' => $url, 'headers' => $headers, 'body' => $body],
            $secretKey,
            array_merge(['keyid' => $keyid, 'tag' => $tag, 'nonce' => federation_http_sig_generate_nonce()], $extraParams)
        );
        return [
            'REQUEST_METHOD'        => 'POST',
            'REQUEST_URI'           => $path,
            'HTTP_HOST'             => 'www.telaris.ca',
            'HTTPS'                 => 'on',
            'HTTP_DATE'             => $headers['Date'],
            'CONTENT_TYPE'          => 'application/json',
            'CONTENT_LENGTH'        => (string)strlen($body),
            'HTTP_CONTENT_DIGEST'   => $signed['content_digest'] ?? federation_http_sig_content_digest($body),
            'HTTP_SIGNATURE_INPUT'  => $signed['signature_input'],
            'HTTP_SIGNATURE'        => $signed['signature'],
        ];
    }

    /** keyid string "<hostname>:<fingerprint-of-publicKey>". */
    protected function keyidFor(string $hostname, string $publicKey): string
    {
        return $hostname . ':' . federation_compute_fingerprint($publicKey);
    }

    // ---- DB fixtures ------------------------------------------------------

    /**
     * Insert a minimal `instances` row so signer/recipient resolution passes.
     * operator_email_enc / lookup_hash carry throwaway bytes (only the relay
     * happy path decrypts them, which the harness never reaches because the
     * downstream POST is unreachable in CLI).
     *
     * @return int instance id
     */
    protected function seedInstance(
        string $hostname,
        string $publicKey,
        string $label,
        string $status = 'published',
        string $url = ''
    ): int {
        db_ensure_instances_table();
        $pdo = getDB();
        $pdo->prepare('DELETE FROM instances WHERE hostname = :h OR label = :l')
            ->execute([':h' => $hostname, ':l' => $label]);
        $stmt = $pdo->prepare("
            INSERT INTO instances
                (hostname, url, pluriverse_endpoint, public_key, operator_email_enc,
                 operator_email_lookup_hash, label, locale, admission_status)
            VALUES
                (:h, :u, :ep, :pk, :enc, :lh, :lbl, 'en', :st)
        ");
        $stmt->execute([
            ':h'   => $hostname,
            ':u'   => $url !== '' ? $url : 'https://' . $hostname . '/',
            ':ep'  => 'https://' . $hostname . '/api/pluriverse',
            ':pk'  => $publicKey,
            ':enc' => random_bytes(48),
            ':lh'  => random_bytes(32),
            ':lbl' => $label,
            ':st'  => $status,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** Build a JWS Compact Serialization with the given header/payload (NOT verifiable; relay only peeks). */
    protected function fakeJws(array $header, array $payload): string
    {
        $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        return $b64((string)json_encode($header)) . '.'
             . $b64((string)json_encode($payload)) . '.'
             . $b64('not-a-real-signature');
    }
}

/**
 * php://input stream wrapper that returns a fixed buffer. Registered only for
 * the duration of a dispatch() call. PHP requires the full wrapper surface.
 */
final class TestInputStream
{
    public static string $data = '';
    private int $pos = 0;
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$data, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$data);
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        switch ($whence) {
            case SEEK_SET: $this->pos = $offset; break;
            case SEEK_CUR: $this->pos += $offset; break;
            case SEEK_END: $this->pos = strlen(self::$data) + $offset; break;
            default: return false;
        }
        return true;
    }

    /** @return array<int|string,int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$data)];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
    }
}
