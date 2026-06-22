<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DB-backed tests for the self-service instance control plane (Phase 3):
 * request intake + PII at rest, the confirm/cap/ban gates, the operational
 * settings knobs, and the approve -> seal -> enqueue path including the 3f
 * federation auto-trust placeholder instances row.
 *
 * Runs against the isolated `telaris_pluriverse_test` database (see
 * tests/bootstrap.php), MAIL_DRY_RUN on. Each test uses unique label/email
 * values so the shared (once-reset) test DB never collides across cases.
 */
final class SelfServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/db_federation.php';
    }

    /** A unique, RFC-1035-shaped label fragment for this test run. */
    private function uniqLabel(string $prefix = 't'): string
    {
        return $prefix . substr(bin2hex(random_bytes(6)), 0, 10);
    }

    private function uniqEmail(): string
    {
        return 'op-' . bin2hex(random_bytes(5)) . '@example.com';
    }

    // ---- request intake + PII round-trip ----

    public function testRequestInsertAndPiiRoundTrip(): void
    {
        $email = $this->uniqEmail();
        $id = db_insert_instance_request([
            'label'             => $this->uniqLabel(),
            'site_name'         => 'My Galaxy Map',
            'site_tagline'      => 'A relational atlas',
            'editorial_framing' => 'Community knowledge for a study group',
            'locale'            => 'es',
            'federate'          => true,
            'operator_name'     => 'Alex Rivera',
            'operator_email'    => $email,
            'request_ip'        => '203.0.113.7',
        ]);
        $this->assertGreaterThan(0, $id);

        $row = db_get_instance_request_by_id($id, true);
        $this->assertIsArray($row);
        $this->assertSame('pending_confirmation', $row['status']);
        $this->assertSame('es', $row['locale']);
        $this->assertSame($email, $row['operator_email']);     // decrypted
        $this->assertSame('Alex Rivera', $row['operator_name']); // decrypted
        $this->assertSame('My Galaxy Map', $row['site_name']);

        // PII must NOT be returned without the flag.
        $sealed = db_get_instance_request_by_id($id, false);
        $this->assertArrayNotHasKey('operator_email', $sealed);
    }

    public function testInsertRequiresEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        db_insert_instance_request(['label' => $this->uniqLabel(), 'site_name' => 'x', 'operator_email' => '']);
    }

    // ---- confirm transition (atomic guard) ----

    public function testConfirmTransitionIsAtomic(): void
    {
        $id = db_insert_instance_request([
            'label' => $this->uniqLabel(), 'site_name' => 'x', 'operator_email' => $this->uniqEmail(),
        ]);
        $this->assertTrue(db_transition_instance_request($id, 'pending_confirmation', 'confirmed', 'test', ['confirmed_at' => true]));
        // Second attempt from the same expected state must not change anything.
        $this->assertFalse(db_transition_instance_request($id, 'pending_confirmation', 'confirmed', 'test'));
        $this->assertSame('confirmed', db_get_instance_request_by_id($id)['status']);
    }

    // ---- label-in-use gate ----

    public function testLabelInUse(): void
    {
        $label = $this->uniqLabel();
        $this->assertFalse(db_label_in_use($label));
        db_insert_instance_request(['label' => $label, 'site_name' => 'x', 'operator_email' => $this->uniqEmail()]);
        $this->assertTrue(db_label_in_use($label));

        // A rejected request frees its label.
        $rejId = db_insert_instance_request(['label' => $this->uniqLabel('r'), 'site_name' => 'x', 'operator_email' => $this->uniqEmail()]);
        $rejLabel = db_get_instance_request_by_id($rejId)['label'];
        $this->assertTrue(db_label_in_use($rejLabel));
        db_transition_instance_request($rejId, 'pending_confirmation', 'rejected', 'test');
        $this->assertFalse(db_label_in_use($rejLabel));
    }

    // ---- per-operator cap counting ----

    public function testActiveRequestCountExcludesTerminalNegative(): void
    {
        $email = $this->uniqEmail();
        $lh = federation_pii_lookup_hash($email);
        $this->assertSame(0, db_count_active_requests_by_lookup_hash($lh));

        $a = db_insert_instance_request(['label' => $this->uniqLabel(), 'site_name' => 'x', 'operator_email' => $email]);
        db_insert_instance_request(['label' => $this->uniqLabel(), 'site_name' => 'x', 'operator_email' => $email]);
        $this->assertSame(2, db_count_active_requests_by_lookup_hash($lh));

        // Rejecting one drops it out of the count.
        db_transition_instance_request($a, 'pending_confirmation', 'rejected', 'test');
        $this->assertSame(1, db_count_active_requests_by_lookup_hash($lh));
    }

    // ---- operator ban ----

    public function testOperatorBanLifecycle(): void
    {
        $lh = federation_pii_lookup_hash($this->uniqEmail());
        $this->assertFalse(db_operator_is_banned($lh));
        db_add_operator_ban($lh, 'spam', 'admin@test');
        $this->assertTrue(db_operator_is_banned($lh));
        // Re-adding the same ban is idempotent (UNIQUE on lookup hash).
        db_add_operator_ban($lh, 'spam again', 'admin@test');
        $this->assertTrue(db_operator_is_banned($lh));
        $this->assertTrue(db_remove_operator_ban($lh, 'admin@test'));
        $this->assertFalse(db_operator_is_banned($lh));
        $this->assertFalse(db_remove_operator_ban($lh, 'admin@test')); // already gone
    }

    // ---- settings + gates ----

    public function testSelfServiceSettingsAndGates(): void
    {
        pluriverse_setting_set('self_service_open', '0');
        $this->assertFalse(db_self_service_is_open());
        pluriverse_setting_set('self_service_open', '1');
        $this->assertTrue(db_self_service_is_open());

        pluriverse_setting_set('self_service_operator_cap', '7');
        $this->assertSame(7, db_self_service_operator_cap());
        // Floor of 1 even if a bad value is stored.
        pluriverse_setting_set('self_service_operator_cap', '0');
        $this->assertSame(1, db_self_service_operator_cap());

        // Restore the safe default (closed) so a leaked test DB stays closed.
        pluriverse_setting_set('self_service_open', '0');
    }

    // ---- approve: seal + enqueue + 3f pending instance ----

    public function testApproveSealsEnqueuesAndCreatesPendingFederationInstance(): void
    {
        // The handoff seal needs the shared operator-handoff key; skip cleanly
        // where it is not readable (e.g. CI without the host key).
        try {
            pluriverse_orrery_handoff_seal(['email' => 'probe@example.com']);
        } catch (Throwable $e) {
            $this->markTestSkipped('operator-handoff key not available: ' . $e->getMessage());
        }

        $email = $this->uniqEmail();
        $label = $this->uniqLabel('f');
        $id = db_insert_instance_request([
            'label' => $label, 'site_name' => 'Federated Map', 'editorial_framing' => 'open atlas',
            'locale' => 'pt', 'federate' => true, 'operator_name' => 'Sam Cruz', 'operator_email' => $email,
        ]);
        // Must be 'confirmed' before approve will act.
        db_transition_instance_request($id, 'pending_confirmation', 'confirmed', 'test', ['confirmed_at' => true]);

        $jobId = db_approve_instance_request($id, 'admin@test');
        $this->assertIsInt($jobId);
        $this->assertGreaterThan(0, $jobId);

        // Request flipped to approved + linked to a (pending) instance.
        $req = db_get_instance_request_by_id($id);
        $this->assertSame('approved', $req['status']);
        $this->assertNotNull($req['instance_id']);
        $instanceId = (int)$req['instance_id'];

        // Job payload: sealed operator + the instance_id for the worker backfill.
        $job = db_get_provisioning_job_by_id($jobId);
        $this->assertSame('provision', $job['job_type']);
        $this->assertSame($label, $job['label']);
        $payload = json_decode((string)$job['payload'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('operator_sealed', $payload);
        $this->assertNotSame('', $payload['operator_sealed']);
        $this->assertSame($instanceId, (int)$payload['instance_id']);
        $this->assertTrue((bool)$payload['federate']);
        // The sealed blob must NOT carry the email in clear text.
        $this->assertStringNotContainsString($email, (string)$job['payload']);

        // 3f placeholder instances row: pending, empty key, not yet published.
        $inst = getDB()->query("SELECT admission_status, length(public_key) AS pk, hostname, locale FROM instances WHERE id = " . $instanceId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $inst['admission_status']);
        $this->assertSame(0, (int)$inst['pk']);
        $this->assertStringEndsWith('.' . pluriverse_wildcard_base(), (string)$inst['hostname']);
        $this->assertSame('pt', $inst['locale']);

        // A pending row must be excluded from the session-eligible listing.
        $lh = federation_pii_lookup_hash($email);
        $this->assertCount(0, db_get_instances_by_email_lookup_hash($lh, true));
        $this->assertCount(1, db_get_instances_by_email_lookup_hash($lh, false));

        // Double-approve is a no-op (status already moved past 'confirmed').
        $this->assertNull(db_approve_instance_request($id, 'admin@test'));
    }

    public function testApproveNonFederateSkipsInstanceRow(): void
    {
        try {
            pluriverse_orrery_handoff_seal(['email' => 'probe@example.com']);
        } catch (Throwable $e) {
            $this->markTestSkipped('operator-handoff key not available: ' . $e->getMessage());
        }

        $email = $this->uniqEmail();
        $id = db_insert_instance_request([
            'label' => $this->uniqLabel('s'), 'site_name' => 'Standalone', 'federate' => false,
            'operator_email' => $email,
        ]);
        db_transition_instance_request($id, 'pending_confirmation', 'confirmed', 'test', ['confirmed_at' => true]);

        $jobId = db_approve_instance_request($id, 'admin@test');
        $this->assertIsInt($jobId);

        $req = db_get_instance_request_by_id($id);
        $this->assertSame('approved', $req['status']);
        $this->assertNull($req['instance_id']); // no federation row for a standalone instance

        $payload = json_decode((string)db_get_provisioning_job_by_id($jobId)['payload'], true);
        $this->assertFalse((bool)$payload['federate']);
        $this->assertArrayNotHasKey('instance_id', $payload);

        // No instances row was created for this operator.
        $lh = federation_pii_lookup_hash($email);
        $this->assertCount(0, db_get_instances_by_email_lookup_hash($lh, false));
    }
}
