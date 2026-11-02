<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
function expect(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); }
function rejected(callable $fn): void {
    try { $fn(); throw new RuntimeException('Expected rejection'); } catch (RuleError $e) { expect(!str_contains($e->getMessage(),'code is:'),'Sensitive error'); }
}
// These credentials belong only to fictional accounts in the verified disposable server.
function seed(): void {
    run('DELETE FROM Audit_Log');run('DELETE FROM Password_Reset');run('DELETE FROM Email_Message');run('DELETE FROM Login');run('DELETE FROM Admin');run('DELETE FROM User');
    foreach (['Student','Faculty','Admin','StatDept'] as $i=>$role) {
        run('INSERT INTO User(user_ID,first_Name,last_Name,user_Type,DOB) VALUES (?,?,?,?,?)',[$i+1,'Fixture',$role,$role,'2000-01-01']);
        run('INSERT INTO Login(user_ID,user_Email,user_Password,user_Type,no_Of_Tries,lock_Var) VALUES (?,?,?,?,0,0)',[$i+1,strtolower($role).'@fixture.invalid',password_hash('FixtureOnly123!',PASSWORD_DEFAULT),$role]);
    }
    run("INSERT INTO Admin VALUES (3,'Read-Write')");$_SESSION=[];
}
function resetFixture(): array {
    run('UPDATE Login SET no_Of_Tries=0,lock_Var=0 WHERE user_ID=1');
    transaction(fn()=>startReset(1,'Forgot'));
    $mail=one('SELECT body FROM Email_Message WHERE user_ID=1 ORDER BY message_ID DESC LIMIT 1')['body'];
    expect((bool)preg_match('/code is: ([0-9]{6})/',$mail,$code),'Missing fixture code');
    expect((bool)preg_match('/token=([0-9a-f]{64})/',$mail,$token),'Missing fixture token');
    $reset=one('SELECT * FROM Password_Reset WHERE user_ID=1 ORDER BY reset_ID DESC LIMIT 1');
    return [$reset,$code[1],$token[1]];
}
function concurrent(string $mode,int $n,int $resetId=0,string $code=''): array {
    global $fixtureRoot;
    $barrier=$fixtureRoot.'/barrier-'.bin2hex(random_bytes(5));mkdir($barrier,0700);$processes=[];
    for ($i=0;$i<$n;$i++) {
        $cmd=[PHP_BINARY,__DIR__.'/worker.php',$fixtureRoot,$mode,$barrier,(string)$i,(string)$resetId,$code];
        $pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        expect(is_resource($proc),'Could not start worker');fclose($pipes[0]);$processes[]=[$proc,$pipes];
    }
    $until=microtime(true)+15;
    while (count(glob($barrier.'/ready-*'))<$n && microtime(true)<$until) usleep(1000);
    expect(count(glob($barrier.'/ready-*'))===$n,'Worker readiness timeout');touch($barrier.'/go');
    $results=[];
    foreach ($processes as [$proc,$pipes]) {
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        expect(proc_close($proc)===0 && $err==='','Worker failed');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    return $results;
}
if (($argv[2] ?? '')==='seed') { seed(); exit; }
if (($argv[2] ?? '')==='lock-admin') { run('UPDATE Login SET lock_Var=1 WHERE user_ID=3');exit; }
if (($argv[2] ?? '')==='rotate') {
    [$r,$code]=resetFixture();checkCode(1,$code);$_SESSION=['reset_user'=>1,'reset_verified'=>(int)$r['reset_ID']];
    finishReset(['password'=>'FixtureRotated123!','confirm'=>'FixtureRotated123!']);exit;
}
seed();[$r,$correct,$token]=resetFixture();$wrong=$correct==='000000'?'111111':'000000';
for ($i=1;$i<=RESET_MAX_TRIES;$i++) {
    rejected(fn()=>checkCode(1,$wrong));
    expect((int)one('SELECT attempts FROM Password_Reset WHERE reset_ID=?',[$r['reset_ID']])['attempts']===$i,'Failed attempts rolled back');
}
rejected(fn()=>checkCode(1,$correct));rejected(fn()=>verifyResetLink(['token'=>$token]));
[$r,$correct,$token]=resetFixture();run('UPDATE Password_Reset SET expires_At=NOW() WHERE reset_ID=?',[$r['reset_ID']]);
rejected(fn()=>checkCode(1,$correct));rejected(fn()=>verifyResetLink(['token'=>$token]));
[$r,$correct,$token]=resetFixture();run('UPDATE Password_Reset SET used_At=NOW() WHERE reset_ID=?',[$r['reset_ID']]);
rejected(fn()=>checkCode(1,$correct));rejected(fn()=>verifyResetLink(['token'=>$token]));
foreach ([null,[],true,123456,'12345',"123456\n",' 123456','1e0000'] as $bad) {
    [$r]=resetFixture();rejected(fn()=>checkCode(1,$bad));
    expect((int)one('SELECT attempts FROM Password_Reset WHERE reset_ID=?',[$r['reset_ID']])['attempts']===1,'Malformed attempt not counted');
}
foreach ([null,[],true,'bad',str_repeat('a',65)] as $bad) rejected(fn()=>verifyResetLink(['token'=>$bad]));
[$r,$correct,$token]=resetFixture();$oldSession=session_id();
$link=verifyResetLink(['token'=>$token]);expect(session_id()!==$oldSession && array_keys($link)===['message'],'Link verification did not regenerate or leaked identity');
rejected(fn()=>finishReset(['password'=>'FixtureLink123!','confirm'=>'FixtureLink123!'],2));
finishReset(['password'=>'FixtureLink123!','confirm'=>'FixtureLink123!']);rejected(fn()=>verifyResetLink(['token'=>$token]));
[$r,$correct]=resetFixture();checkCode(1,$correct);$_SESSION=['reset_user'=>1,'reset_verified'=>(int)$r['reset_ID']];
$wrong=$correct==='000000'?'111111':'000000';for($i=0;$i<5;$i++) rejected(fn()=>checkCode(1,$wrong));
rejected(fn()=>finishReset(['password'=>'FixtureLimit123!','confirm'=>'FixtureLimit123!']));
run("UPDATE Admin SET security_Level='Read-Only' WHERE admin_ID=3");rejected(fn()=>adminSendReset(3,['login'=>'1']));
run("UPDATE Admin SET security_Level='Read-Write' WHERE admin_ID=3");adminSendReset(3,['login'=>'1']);
expect((int)one('SELECT COUNT(*) n FROM Audit_Log')['n']===1,'Admin reset audit lost');

[$r,$correct,$oldToken]=resetFixture();[$new,$newCode]=resetFixture();
rejected(fn()=>checkCode(1,$correct));rejected(fn()=>verifyResetLink(['token'=>$oldToken]));expect((int)checkCode(1,$newCode)['reset_ID']===(int)$new['reset_ID'],'New request invalid');
[$r,$correct]=resetFixture();$wrong=$correct==='000000'?'111111':'000000';
$results=concurrent('wrong-code',12,(int)$r['reset_ID'],$wrong);
expect(count(array_filter($results,fn($r)=>$r['result']==='rejected'))===12,'Wrong code accepted');
expect((int)one('SELECT attempts FROM Password_Reset WHERE reset_ID=?',[$r['reset_ID']])['attempts']===5,'Concurrent limit bypass or lost attempts');rejected(fn()=>checkCode(1,$correct));
concurrent('issue',6);expect((int)one('SELECT COUNT(*) n FROM Password_Reset WHERE user_ID=1 AND used_At IS NULL AND expires_At>NOW()')['n']===1,'Concurrent issuance left multiple active requests');
[$r,$correct]=resetFixture();checkCode(1,$correct);
$before=one('SELECT user_Password FROM Login WHERE user_ID=1')['user_Password'];
$results=concurrent('finish',2,(int)$r['reset_ID']);
expect(count(array_filter($results,fn($r)=>$r['result']==='ok'))===1,'Reset consumption was not single-use');
$_SESSION=['user'=>['id'=>1,'role'=>'Student'],'credential_fingerprint'=>credentialFingerprint($before)];
expect(authenticatedSession($_SESSION['user'])===null,'Old password session survived');
seed();$hash=one('SELECT user_Password FROM Login WHERE user_ID=1')['user_Password'];
$_SESSION=['user'=>['id'=>1,'role'=>'Student'],'credential_fingerprint'=>credentialFingerprint($hash)];
expect(authenticatedSession($_SESSION['user'])!==null,'Valid session rejected');
run("UPDATE Login SET user_Type='Faculty' WHERE user_ID=1");expect(authenticatedSession($_SESSION['user'])===null,'Changed role session survived');
run("UPDATE Login SET user_Type='Student' WHERE user_ID=1");
$_SESSION=['user'=>['id'=>1,'role'=>'Student'],'credential_fingerprint'=>credentialFingerprint($hash)];

run('UPDATE Login SET lock_Var=1 WHERE user_ID=1');expect(authenticatedSession($_SESSION['user'])===null,'Locked session survived');
$_SESSION=['user'=>['id'=>1,'role'=>'Student']];expect(authenticatedSession($_SESSION['user'])===null,'Unbound legacy session accepted');
$otherHash=one('SELECT user_Password FROM Login WHERE user_ID=2')['user_Password'];
run('DELETE FROM User WHERE user_ID=2');
$_SESSION=['user'=>['id'=>2,'role'=>'Faculty'],'credential_fingerprint'=>credentialFingerprint($otherHash)];
expect(authenticatedSession($_SESSION['user'])===null,'Deleted account session survived');

seed();$results=concurrent('wrong-login',8);
expect((int)one('SELECT no_Of_Tries FROM Login WHERE user_ID=1')['no_Of_Tries']===5,'Concurrent login attempts lost');
expect(signIn(['email'=>'1','password'=>'FixtureOnly123!','role'=>'Student'])[0]===423,'Locked account signed in');
seed();[$status,$body]=signIn(['email'=>'1','password'=>'FixtureOnly123!','role'=>'Faculty']);expect($status===403,'Wrong-role login accepted');
[$status,$body]=signIn(['email'=>'1','password'=>'FixtureOnly123!','role'=>'Student']);expect($status===200 && array_keys($body)===['user'],'Login leaked internal credential binding');
rejected(fn()=>demoMail(['user_ID'=>2,'DOB'=>'2000-01-01']));rejected(fn()=>demoMail(['user_ID'=>[]]));
$_SESSION=[];rejected(fn()=>demoMail(['user_ID'=>1,'DOB'=>'2000-01-01']));

[$redact,$secretCode,$secretToken]=resetFixture();
putenv('APP_ENV=production');foreach(inbox(1) as $message) {
    expect(!str_contains($message['body'],$secretCode) && !str_contains($message['body'],$secretToken),'Legacy recovery mail exposed in production');
}
putenv('APP_ENV=production');$before=(int)one('SELECT COUNT(*) n FROM Password_Reset')['n'];
foreach ([fn()=>requestForgotReset(['login'=>'1']),fn()=>verifyForgotCode(['login'=>'1','code'=>'123456']),fn()=>verifyResetLink(['token'=>str_repeat('a',64)]),fn()=>demoMail(['user_ID'=>1]),fn()=>finishReset(['password'=>'Fixture123!','confirm'=>'Fixture123!'])] as $fn) rejected($fn);
expect((int)one('SELECT COUNT(*) n FROM Password_Reset')['n']===$before,'Disabled recovery wrote records');
putenv('APP_ENV=development');seed();
echo "PASS: native InnoDB reset-attempt durability/limits, malformed/expired/used/superseded requests, 12 simultaneous wrong-code attempts, 6 simultaneous issuances, 2 simultaneous completions (one succeeds), 8 simultaneous failed logins, session revocation/locks/legacy rejection, role checks, unauthorized inbox, and production recovery shutdown.\n";
