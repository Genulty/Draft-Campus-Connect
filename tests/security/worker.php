<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$mode=$argv[2];$barrier=$argv[3];$worker=$argv[4];
if (!str_starts_with($barrier,$fixtureRoot.'/barrier-')) throw new RuntimeException('Unsafe barrier path');
touch($barrier.'/ready-'.$worker);$until=microtime(true)+15;
while (!is_file($barrier.'/go') && microtime(true)<$until) usleep(1000);
if (!is_file($barrier.'/go')) throw new RuntimeException('Barrier timeout');
try {
    if ($mode==='wrong-code') checkCode(1,$argv[6]);
    elseif ($mode==='issue') transaction(fn()=>startReset(1,'Forgot'));
    elseif ($mode==='finish') {
        $_SESSION=['reset_user'=>1,'reset_verified'=>(int)$argv[5]];
        finishReset(['password'=>'FixtureRotated123!','confirm'=>'FixtureRotated123!']);
    } elseif ($mode==='wrong-login') {
        [$status]=signIn(['email'=>'1','password'=>'incorrect','role'=>'Student']);echo json_encode(['result'=>$status]);exit;
    } else throw new RuntimeException('Unknown worker');
    echo json_encode(['result'=>'ok']);
} catch (RuleError $e) { echo json_encode(['result'=>'rejected']); }
