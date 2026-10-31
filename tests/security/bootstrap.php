<?php
// WRITE-CAPABLE: only the native runner's disposable socket/data directory may be used.
declare(strict_types=1);
$fixtureRoot=realpath($argv[1] ?? '');
if (!$fixtureRoot || !preg_match('~/campus-security-[A-Za-z0-9]+$~',$fixtureRoot)
    || !is_file($fixtureRoot.'/owned-fixture.json')) throw new RuntimeException('REFUSED: use the isolated security runner');
$manifest=json_decode(file_get_contents($fixtureRoot.'/owned-fixture.json'),true,512,JSON_THROW_ON_ERROR);
$socket=$fixtureRoot.'/mysql.sock';
if (($manifest['socket'] ?? null)!==$socket || ($manifest['database'] ?? null)!=='campus_security_test') throw new RuntimeException('REFUSED: invalid fixture manifest');
// No network host, default socket, application config, or existing database can be used.
$probe=new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$probe->query('SELECT @@datadir AS dir,@@socket AS socket,@@server_id AS server_id')->fetch();
if (realpath($identity['dir'])!==$fixtureRoot.'/data' || $identity['socket']!==$socket || (int)$identity['server_id']!==732104) throw new RuntimeException('REFUSED: unrecognized database server');
if ($probe->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='campus_connect'")->fetchColumn()!=0) throw new RuntimeException('REFUSED: existing application database present');
putenv('CAMPUS_CONFIG='.$fixtureRoot.'/config.php');
putenv('APP_ENV=development');putenv('CAMPUS_DEMO_RECOVERY=1');
require __DIR__.'/../../api/db.php';
if (config()['host']!=='localhost' || config()['socket']!==$socket || config()['database']!=='campus_security_test') throw new RuntimeException('REFUSED: unsafe configuration');
if (($argv[2] ?? '')==='initialize') {
    $probe->exec('CREATE DATABASE campus_security_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    db()->exec(file_get_contents(__DIR__.'/schema.sql'));
}
require __DIR__.'/../../api/accounts.php';
require __DIR__.'/../../api/mail.php';
if (!is_dir($fixtureRoot.'/sessions')) mkdir($fixtureRoot.'/sessions',0700);
ini_set('session.save_path',$fixtureRoot.'/sessions');
session_start();
