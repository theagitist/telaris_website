<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Pluriverse server suite.
 *
 *  1. Force mail into dry-run so no test ever contacts an SMTP relay.
 *  2. Load app config (DB_* constants + inc/db.php). No DB connection happens
 *     at require time; the first getDB() does.
 *  3. Route EVERY getDB() call to the isolated `pluriverse_test` database via
 *     the pluriverse_db_reset_for_testing() seam, so the suite can never touch
 *     the live `pluriverse` data (the lesson from the instance suite deleting
 *     a real peer row). Mirrors getDB()'s own PDO options.
 */

define('MAIL_DRY_RUN', true);

require dirname(__DIR__) . '/config.php';

$__telaris_test_port = defined('DB_PORT') && DB_PORT !== '' ? DB_PORT : '3306';
$__telaris_test_pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, $__telaris_test_port, 'pluriverse_test'),
    DB_USER,
    defined('DB_PASS') ? DB_PASS : '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
pluriverse_db_reset_for_testing($__telaris_test_pdo);
