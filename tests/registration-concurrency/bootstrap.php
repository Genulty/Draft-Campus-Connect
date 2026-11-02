<?php
// Never connect from the host, and never accept general application DB settings.
declare(strict_types=1);
const TEST_DATABASE = 'registration_concurrency_test';
const TEST_HOST = 'registration-db';
const TEST_SERVER_NAME = 'campus-registration-isolated';
if (!is_file('/.dockerenv') || getenv('REGISTRATION_TEST_ACK') !== 'ISOLATED_DISPOSABLE_DATABASE_ONLY') {
    fwrite(STDERR, "REFUSED: this write-capable suite requires its opt-in disposable Docker environment.\n");
    exit(77);
}
$noConfig = '/__registration_test_no_application_config__';
if (is_readable($noConfig)) throw new RuntimeException('Unexpected application config override');
putenv('CAMPUS_CONFIG=' . $noConfig);
putenv('DB_HOST=' . TEST_HOST);
putenv('DB_PORT=3306');
putenv('DB_NAME=' . TEST_DATABASE);
putenv('DB_USER=registration_fixture');
putenv('DB_PASSWORD=disposable-fixture-only');
require __DIR__ . '/../../api/db.php';
require __DIR__ . '/../../api/actions.php';
if (config()['host'] !== TEST_HOST || config()['database'] !== TEST_DATABASE || config()['user'] !== 'registration_fixture') {
    throw new RuntimeException('Unexpected connection configuration');
}
$identity = one('SELECT DATABASE() AS db_name, @@hostname AS server_name');
if ($identity['db_name'] !== TEST_DATABASE || $identity['server_name'] !== TEST_SERVER_NAME) {
    throw new RuntimeException('Refusing an unrecognized test database/server');
}
if (one('SELECT @@autocommit AS enabled')['enabled'] != 1) throw new RuntimeException('Autocommit required before test transactions');
