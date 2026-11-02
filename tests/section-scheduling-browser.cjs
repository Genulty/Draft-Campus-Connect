// Actual Admin form and renderer with database-free PHP response fixtures.
// All assets/API calls are intercepted. No real section is created.
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async()=>{
  const root=path.resolve(__dirname,'..');
  const fixture=JSON.parse(execFileSync('php',[path.join(__dirname,'section-scheduling.php'),'--browser-fixture'],{encoding:'utf8'}));
  const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
  try {
    const page=await browser.newPage();const errors=[];const posted=[];const validationResponses=[];const validationConsole=[];
    page.on('pageerror',e=>errors.push(e.message));
    page.on('console',m=>{
      if(m.type()!=='error')return;
      // Chromium reports expected HTTP validation failures as resource errors.
      if(m.location().url==='http://isolated.invalid/api/actions/create-section'
        && m.text()==='Failed to load resource: the server responded with a status of 422 (Unprocessable Entity)') validationConsole.push(m.text());
      else errors.push(m.text());
    });
    await page.route('**/*',route=>{
      const url=new URL(route.request().url());
      const json=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
      if(url.hostname==='isolated.invalid'&&url.pathname.startsWith('/api/')) {
        if(url.pathname==='/api/me')return json({user:{id:1,name:'Fixture Admin',role:'Admin',email:'fixture@example.invalid'}});
        if(url.pathname==='/api/views')return json({views:[{id:'create-section',label:'Create section'}]});
        if(url.pathname==='/api/views/create-section') {
          const form=structuredClone(fixture.form);
          for(const field of form.fields)if(url.searchParams.has(field.name))field.value=url.searchParams.get(field.name);
          return json({title:'Create a course section',blocks:[form]});
        }
        if(url.pathname==='/api/actions/create-section') {
          const body=route.request().postDataJSON();posted.push(body);
          const result=fixture.cases[body.course_ID+'/'+body.time_Slot_ID];
          assert.ok(result,'Unexpected fixture selection');if(result.status===422)validationResponses.push(body);return json(result.body,result.status);
        }
        throw new Error('Unexpected API request');
      }
      const file=url.hostname==='isolated.invalid'?{'/':['public/index.html','text/html'],'/login.js':['public/login.js','application/javascript'],'/styles.css':['public/styles.css','text/css']}[url.pathname]:null;
      return route.fulfill(file?{body:readFileSync(path.join(root,file[0])),contentType:file[1]}:{body:'',contentType:'text/css'});
    });
    await page.goto('http://isolated.invalid/#create-section',{waitUntil:'domcontentloaded'});
    const form=page.locator('#page-blocks form');await form.waitFor();
    const text=await form.textContent();
    for(const guidance of ['5 to 10','two distinct meeting days','two 80-minute meetings','160 minutes weekly','lecture/lab details remain unresolved','once for 180 minutes','not restricted to once-weekly'])assert.ok(text.includes(guidance),`Missing guidance: ${guidance}`);
    const courses=await form.locator('[name=course_ID] option').allTextContents();
    assert.ok(courses.some(c=>c.includes('Undergraduate, 3 credits')));
    assert.ok(courses.some(c=>c.includes('Undergraduate, 4 credits')));
    assert.ok(courses.some(c=>c.includes('Graduate, 3 credits')));
    for(const [course,slot] of [['TEST101','1'],['TEST101','2'],['TEST101','3'],['TEST104','3'],['TEST104','2'],['TEST600','4'],['TEST600','1'],['TEST600','3']]) {
      await form.locator('[name=course_ID]').selectOption(course);
      await form.locator('[name=time_Slot_ID]').selectOption(slot);
      await form.locator('[name=faculty_ID]').selectOption(''); // No fabricated faculty data needed by renderer test.
      await form.locator('[name=max_Seats]').fill('5');
      await form.locator('button[type=submit]').click();
      const result=fixture.cases[course+'/'+slot];
      const message=result.body.error||result.body.message;
      await page.waitForFunction(message=>document.getElementById('page-blocks').textContent.includes(message)&&document.querySelector('#page-blocks form button')?.disabled===false,message);
      const flash=page.locator('#page-blocks .flash');
      assert.equal(await flash.locator('span').textContent(),message);
      assert.equal(await flash.getAttribute('class'),result.status===200?'flash ok':'flash err');
      assert.equal(await form.locator('[name=course_ID]').inputValue(),course);
      assert.equal(await form.locator('[name=time_Slot_ID]').inputValue(),slot);
      assert.equal(posted.at(-1).max_Seats,'5');
    }
    const count=posted.length;
    await form.locator('[name=max_Seats]').fill('4');await form.locator('button[type=submit]').click();
    assert.equal(posted.length,count,'Capacity constraint no longer blocks invalid browser submission');
    assert.equal(validationResponses.length,3);assert.equal(validationConsole.length,3);
    assert.deepEqual(errors,[]);
    console.log(`PASS: ${count} intercepted Admin submissions show the actual handler's scheduling success/errors, preserve selected values, display type/credits and matching guidance, and retain capacity validation; no JavaScript/unexpected console errors (3 expected HTTP 422 diagnostics). No real API or database writes.`);
  } finally {await browser.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1;});
