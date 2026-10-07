<?php
// MySQL connection (PDO) and small query helpers shared by the whole API.
// Connection settings come from /etc/campus-connect/config.php on the server
// (written by deploy/lightsail-setup.sh), then environment variables, then local defaults.
declare(strict_types=1);

// A broken rule or bad input. The message is shown to the user (HTTP 422).
class RuleError extends Exception {}

function fail(string $message): never
{
    throw new RuleError($message);
}

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = getenv('CAMPUS_CONFIG') ?: '/etc/campus-connect/config.php';
        $fromFile = is_readable($file) ? require $file : [];
        $config = [
            'host' => $fromFile['host'] ?? (getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (int) ($fromFile['port'] ?? (getenv('DB_PORT') ?: 3306)),
            'user' => $fromFile['user'] ?? (getenv('DB_USER') ?: 'campus'),
            'password' => $fromFile['password'] ?? (getenv('DB_PASSWORD') ?: 'campus'),
            'database' => $fromFile['database'] ?? (getenv('DB_NAME') ?: 'campus_connect'),
        ];
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config();
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4",
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements: numbers come back as numbers
            ]
        );
    }
    return $pdo;
}

// Runs a prepared statement. Every value is sent as a parameter, never pasted into the SQL.
function run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach (array_values($params) as $i => $value) {
        $stmt->bindValue($i + 1, $value, match (true) {
            is_int($value) => PDO::PARAM_INT,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        });
    }
    $stmt->execute();
    return $stmt;
}

// All rows as associative arrays.
function rows(string $sql, array $params = []): array
{
    return run($sql, $params)->fetchAll();
}

// The first row, or null.
function one(string $sql, array $params = []): ?array
{
    $row = run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

// Runs $work inside a transaction: everything is saved, or nothing is.
function transaction(callable $work): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
