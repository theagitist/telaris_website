<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves the test harness is isolated: every getDB() points at the throwaway
 * `pluriverse_test` database (NOT the live `pluriverse`), and the schema
 * self-builds there via db_ensure_*. This is the safety guard that lets
 * DB-backed handler tests run without risking real federation data.
 */
final class DbHarnessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/inc/db_federation.php';
    }

    public function testConnectedToIsolatedTestDatabase(): void
    {
        $db = getDB()->query('SELECT current_database()')->fetchColumn();
        $this->assertSame('pluriverse_test', $db, 'tests must never touch the live pluriverse DB');
    }

    public function testEnsureBuildsSchemaInTestDb(): void
    {
        db_ensure_anomaly_log_table();
        $exists = getDB()->query("SELECT to_regclass('anomaly_log')")->fetchColumn();
        $this->assertSame('anomaly_log', $exists, 'db_ensure_* materializes tables in the test DB');
    }

    public function testWritesLandInTestDb(): void
    {
        $pdo = getDB();
        $pdo->exec('CREATE TABLE IF NOT EXISTS _harness_probe (id INT PRIMARY KEY, note VARCHAR(32))');
        $pdo->exec("INSERT INTO _harness_probe (id, note) VALUES (1, 'ok') ON CONFLICT (id) DO UPDATE SET note = 'ok'");
        $this->assertSame('ok', $pdo->query('SELECT note FROM _harness_probe WHERE id = 1')->fetchColumn());
        $pdo->exec('DROP TABLE _harness_probe');
    }
}
