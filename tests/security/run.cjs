// Native disposable InnoDB server. No production config, default socket, network DB,
// existing data directory, or campus_connect database can be used by the PHP harness.
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync } = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const root = path.resolve(__dirname, '../..');
  const dir = mkdtempSync(path.join(os.tmpdir(), 'campus-security-'));
  // Canonical path is required because macOS /tmp aliases /private/tmp.
  const realDir = require('node:fs').realpathSync(dir);
  const mysqld = process.env.MYSQLD_BIN || 'mysqld';
  let database; const web = [];
  function php(file, action) {
    return execFileSync('php',[path.join(__dirname,file),realDir,action],{cwd:root,encoding:'utf8',env:{...process.env,APP_ENV:'development',CAMPUS_DEMO_RECOVERY:'1'},timeout:90000});
  }
  try {
    writeFileSync(path.join(realDir,'owned-fixture.json'),JSON.stringify({socket:realDir+'/mysql.sock',database:'campus_security_test'}),{mode:0o600});
    writeFileSync(path.join(realDir,'config.php'),`<?php return ['host'=>'localhost','socket'=>'${realDir}/mysql.sock','user'=>'root','password'=>'','database'=>'campus_security_test'];\n`,{mode:0o600});
    execFileSync(mysqld,['--no-defaults','--initialize-insecure',`--datadir=${realDir}/data`,`--log-error=${realDir}/initialize.log`],{stdio:'pipe',timeout:60000});
    database=spawn(mysqld,['--no-defaults',`--datadir=${realDir}/data`,`--socket=${realDir}/mysql.sock`,`--pid-file=${realDir}/mysql.pid`,`--log-error=${realDir}/mysql.log`,'--skip-networking','--mysqlx=OFF','--skip-log-bin','--server-id=732104','--innodb-buffer-pool-size=32M'],{stdio:'ignore'});
    let ready=false;
    for(let i=0;i<150;i++) {
      if(database.exitCode!==null) throw new Error('Disposable mysqld exited during startup');
      if(existsSync(path.join(realDir,'mysql.sock'))) {try {php('bootstrap.php','probe');ready=true;break;} catch {}}
      await delay(100);
    }
    assert.ok(ready,'Disposable MySQL startup timeout');
    php('bootstrap.php','initialize');
    console.log(execFileSync(mysqld,['--version'],{encoding:'utf8'}).trim());
    if(!process.argv.includes('--registration-only')) process.stdout.write(php('suite.php','test'));
    php('suite.php','seed');
    async function server(development) {
      const port=await new Promise((resolve,reject)=>{const s=net.createServer();s.on('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
      const child=spawn('php',['-d',`session.save_path=${realDir}/sessions`,'-S',`127.0.0.1:${port}`,'-t','public','api/index.php'],{cwd:root,env:{...process.env,CAMPUS_CONFIG:realDir+'/config.php',APP_ENV:development?'development':'production',CAMPUS_DEMO_RECOVERY:'1'},stdio:['ignore','ignore','pipe']});
      web.push(child);let log='';child.stderr.on('data',b=>{log+=b;});
      for(let i=0;i<100 && !log.includes('Development Server');i++) await delay(20);
      assert.ok(log.includes('Development Server'),'Disposable PHP server failed to start');
      async function call(endpoint,body,cookie='') {
        const res=await fetch(`http://127.0.0.1:${port}/api/${endpoint}`,{method:body===undefined?'GET':'POST',headers:{'Content-Type':'application/json',...(cookie?{Cookie:cookie}:{})},body:body===undefined?undefined:typeof body==='string'?body:JSON.stringify(body)});
        const newCookie=res.headers.get('set-cookie')?.split(';')[0] || cookie;
        return {status:res.status,data:await res.json(),cookie:newCookie};
      }
      return {call,log:()=>log};
    }
    const production=await server(false);
    async function login(api,id,role,password='FixtureOnly123!') {
      const initial=await api.call('me');
      const result=await api.call('login',{email:String(id),password,role},initial.cookie);
      assert.equal(result.status,200);assert.notEqual(result.cookie,initial.cookie,'Sign-in did not regenerate session ID');
      assert.deepEqual(Object.keys(result.data),['user']);return result.cookie;
    }
    const cookies={};
    for(const [i,role] of ['Student','Faculty','Admin','StatDept'].entries()) cookies[role]=await login(production,i+1,role);
    let cases=0;
    for(const endpoint of ['add-section','drop-section']) {
      assert.equal((await production.call('actions/'+endpoint,{CRN:90006})).status,401);cases++;
      for(const role of ['Faculty','Admin','StatDept']) {assert.equal((await production.call('actions/'+endpoint,{CRN:90006},cookies[role])).status,403);cases++;}
      for(const value of [null,'',0,-1,[],true,90006.5,'90006junk','9e4','90006.0',' 90006','9999999999999999999999999']) {
        assert.equal((await production.call('actions/'+endpoint,{CRN:value},cookies.Student)).status,422);cases++;
      }
      for(const body of [{},'null','{bad json']) {assert.equal((await production.call('actions/'+endpoint,body,cookies.Student)).status,422);cases++;}
    }
    console.log(`PASS: ${cases} registration-router regression checks with genuine credential-bound fixture sessions (401/403/422).`);
    if(!process.argv.includes('--registration-only')) {
      assert.equal((await production.call('views')).status,401);
      assert.equal((await production.call('views/admin-overview',undefined,cookies.Student)).status,404);
      assert.equal((await production.call('views',undefined,cookies.Admin)).status,200);
      for(const [endpoint,body] of [['reset/request',{login:'1'}],['reset/verify',{login:'1',code:'123456'}],['reset/complete',{password:'FixtureOnly123!',confirm:'FixtureOnly123!'}],['demo-mail',{user_ID:1,DOB:'2000-01-01'}],['actions/request-password-change',{current:'FixtureOnly123!'}]]) {
        assert.equal((await production.call(endpoint,body,cookies.Student)).status,422);
      }
      const second=await login(production,1,'Student');
      php('suite.php','rotate');
      assert.equal((await production.call('me',undefined,cookies.Student)).status,401);
      assert.equal((await production.call('me',undefined,second)).status,401);
      php('suite.php','lock-admin');assert.equal((await production.call('views',undefined,cookies.Admin)).status,401);
      php('suite.php','seed');
      const development=await server(true);
      assert.equal((await development.call('demo-mail',{user_ID:1,DOB:'2000-01-01'})).status,422);
      let own=await login(development,1,'Student');const otherSession=await login(development,1,'Student');
      assert.equal((await development.call('demo-mail',{user_ID:2,DOB:'2000-01-01'},own)).status,422);
      assert.equal((await development.call('actions/request-password-change',{current:'FixtureOnly123!'},own)).status,200);
      let mailbox=await development.call('demo-mail',{},own);assert.equal(mailbox.status,200);
      let code=mailbox.data.messages[0].body.match(/code is: ([0-9]{6})/)[1];
      const wrong=code==='000000'?'111111':'000000';
      for(let i=0;i<6;i++) assert.equal((await development.call('reset/verify',{login:'1',code:wrong})).status,422);
      assert.equal((await development.call('reset/verify',{login:'1',code})).status,422);
      assert.equal((await development.call('actions/request-password-change',{current:'FixtureOnly123!'},own)).status,200);
      mailbox=await development.call('demo-mail',{},own);code=mailbox.data.messages[0].body.match(/code is: ([0-9]{6})/)[1];
      const verified=await development.call('actions/verify-password-code',{code},own);assert.equal(verified.status,200);assert.notEqual(verified.cookie,own);own=verified.cookie;
      const completed=await development.call('actions/complete-password-change',{password:'FixtureHTTP123!',confirm:'FixtureHTTP123!'},own);
      assert.equal(completed.status,200);assert.notEqual(completed.cookie,own);
      assert.equal((await development.call('me',undefined,otherSession)).status,401);
      assert.equal((await development.call('me',undefined,completed.cookie)).status,401);
      await login(development,1,'Student','FixtureHTTP123!');
      for(const api of [development,production]) assert.ok(!/code is:|#reset\?token=|FixtureOnly123!|FixtureHTTP123!/.test(api.log()),'Sensitive content logged');
      console.log('PASS: actual HTTP production fail-closed recovery, owner-only local demo, counter enforcement, role permissions, regenerated session IDs, password-change/lock revocation across sessions, and sanitized logs.');
    }
  } finally {
    for(const child of web) if(child.exitCode===null) child.kill('SIGTERM');
    if(database && database.exitCode===null) {
      database.kill('SIGTERM');
      for(let i=0;i<100 && database.exitCode===null;i++) await delay(100);
      if(database.exitCode===null) {database.kill('SIGKILL');await new Promise(resolve=>database.once('exit',resolve));}
    }
    rmSync(realDir,{recursive:true,force:true});
  }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
