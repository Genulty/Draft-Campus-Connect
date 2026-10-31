// Actual PHP router, disposable sessions, deliberately unreachable database endpoint.
// Only unauthorized requests and malformed student CRNs are sent. No valid student action.
const { spawn, execFileSync } = require('node:child_process');
const { mkdtempSync, writeFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');
(async () => {
  const dir=mkdtempSync(path.join(tmpdir(),'campus-windows-api-'));
  let server;
  try {
    writeFileSync(path.join(dir,'config.php'), "<?php return ['host'=>'127.0.0.1','port'=>1,'user'=>'isolated','password'=>'','database'=>'not_campus_connect'];\n");
    const sessions={};
    for (const role of ['Student','Admin','Faculty','StatDept']) {
      const id='windowtest'+role.toLowerCase(); sessions[role]=id;
      execFileSync('php',['-r', 'ini_set("session.save_path",$argv[1]); session_name("campus_connect"); session_id($argv[2]); session_start(); $_SESSION["user"]=["id"=>1,"role"=>$argv[3]]; session_write_close();',dir,id,role]);
    }
    const port=await new Promise((resolve,reject)=>{const s=net.createServer();s.on('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
    server=spawn('php',['-d',`session.save_path=${dir}`,'-S',`127.0.0.1:${port}`,'-t','public','api/index.php'],{cwd:path.resolve(__dirname,'..'),env:{...process.env,CAMPUS_CONFIG:path.join(dir,'config.php')},stdio:['ignore','ignore','pipe']});
    await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('PHP server start timeout')),5000);server.once('error',reject);server.once('exit',code=>reject(new Error(`PHP server exited: ${code}`)));server.stderr.on('data',chunk=>{if(chunk.toString().includes('Development Server')){clearTimeout(timer);resolve();}});});
    let cases=0;
    async function request(endpoint,role,body,status) {
      const res=await fetch(`http://127.0.0.1:${port}/api/actions/${endpoint}`,{method:'POST',headers:{'Content-Type':'application/json',...(role?{Cookie:`campus_connect=${sessions[role]}`}:{})},body:typeof body==='string'?body:JSON.stringify(body)});
      assert.equal(res.status,status,await res.text()); cases++;
    }
    for (const endpoint of ['add-section','drop-section']) {
      await request(endpoint,null,{CRN:90006},401);
      for (const role of ['Admin','Faculty','StatDept']) await request(endpoint,role,{CRN:90006},403);
      for (const value of [null,'',0,-1,[],true,90006.5,'90006junk','9e4','90006.0',' 90006','9999999999999999999999999']) await request(endpoint,'Student',{CRN:value},422);
      for (const body of [{},'null','{bad json']) await request(endpoint,'Student',body,422);
    }
    console.log(`PASS: ${cases} actual-router HTTP checks: anonymous 401, non-student 403, missing/malformed CRNs and invalid JSON 422. Disposable sessions; MySQL destination forced to unreachable port 1.`);
  } finally {
    if (server && server.exitCode===null) {server.kill();await new Promise(resolve=>server.once('exit',resolve));}
    rmSync(dir,{recursive:true,force:true});
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
