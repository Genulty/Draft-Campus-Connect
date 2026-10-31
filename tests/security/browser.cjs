// Recovery UI smoke tests with actual assets and simulated API responses only.
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async()=>{
  const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
  try {
    const page=await browser.newPage();const errors=[];
    page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
    const root=path.resolve(__dirname,'../..');
    await page.route('**/*',route=>{
      const url=new URL(route.request().url());
      const f=url.hostname==='isolated.invalid'?{'/':['public/index.html','text/html'],'/login.js':['public/login.js','application/javascript'],'/styles.css':['public/styles.css','text/css']}[url.pathname]:null;
      return route.fulfill(f?{body:readFileSync(path.join(root,f[0])),contentType:f[1]}:{body:'',contentType:'text/css'});
    });
    await page.addInitScript(()=>{
      window.apiPaths=[];
      window.fetch=async url=>{
        apiPaths.push(url);let status=200,data={};
        if(url==='/api/me')status=401;
        else if(url.startsWith('/api/major-browser'))data={blocks:[]};
        else if(url==='/api/login'){status=401;data={error:'Fixture login failed.',canReset:true};}
        else if(url==='/api/reset/request'){status=422;data={error:'Password recovery delivery is unavailable. Contact your administrator.'};}
        else if(url==='/api/reset/verify')data={message:'Verification accepted. Choose a new password.'};
        else if(url==='/api/reset/complete'||url==='/api/actions/complete-password-change')data={message:'Your password was changed. Sign in again with your new password.'};
        return {ok:status<400,status,json:async()=>data};
      };
    });
    await page.goto('http://isolated.invalid/',{waitUntil:'domcontentloaded'});
    await page.locator('.w-cta [data-go=login]').click();
    await page.locator('#login-form [name=email]').fill('fixture@example.invalid');
    await page.locator('#login-form [name=password]').fill('FixtureOnly123!');
    await page.locator('#login-form button[type=submit]').click();
    await page.locator('.reset-link').click();
    await page.locator('#reset-form button').click();
    await page.waitForFunction(()=>document.getElementById('reset-message').textContent.includes('delivery is unavailable'));
    await page.locator('#reset-view [data-show=login-view]').click();
    await page.locator('#have-code').click();await page.locator('#open-mail').click();
    assert.equal(await page.locator('#mail-view').isVisible(),true);
    assert.equal(await page.locator('input[name=DOB]').count(),0);
    assert.equal(await page.locator('#mail-form').count(),0);
    assert.ok(!(await page.evaluate(()=>apiPaths)).includes('/api/demo-mail'));
    await page.evaluate(()=>{location.hash='#reset?token='+ 'a'.repeat(64);});
    await page.waitForFunction(()=>document.getElementById('newpass-for').textContent.includes('Verification accepted'));
    await page.locator('#newpass-form [name=password]').fill('FixtureUI123!');
    await page.locator('#newpass-form [name=confirm]').fill('FixtureUI123!');
    await page.locator('#newpass-form button').click();
    await page.waitForFunction(()=>document.getElementById('message').textContent.includes('Sign in again'));
    assert.equal(await page.locator('#login-form [name=email]').inputValue(),'');
    await page.evaluate(()=>runAction('complete-password-change',{}));
    assert.equal(await page.locator('#login-page').isVisible(),true);
    assert.ok((await page.locator('#message').textContent()).includes('Sign in again'));
    assert.deepEqual(errors,[]);
    console.log('PASS: recovery UI reports unavailable delivery, removes ID/DOB mailbox access, handles identity-free reset links, and returns to sign-in after password completion; no console errors. Simulated API only.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
