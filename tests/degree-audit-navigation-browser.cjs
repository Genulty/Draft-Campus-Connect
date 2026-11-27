// Live local browser verification recovered from the progression UI walkthrough.
// Only SELECTs and authenticated read-only requests; no fixture reconstruction.
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
const base=process.env.AUDIT_BASE || 'http://127.0.0.1:3044';
assert.ok(process.env.DB_SOCKET,'Use a disposable database socket');
const sql=q=>execFileSync('mysql',['--no-defaults','--socket='+process.env.DB_SOCKET,'-uroot','-NBr','campus_connect','-e',q],{encoding:'utf8'}).trim();
const snapshot=()=>sql('SELECT * FROM Student ORDER BY student_ID; SELECT * FROM Student_History ORDER BY student_ID,CRN; SELECT * FROM Enrollment ORDER BY student_ID,CRN; SELECT * FROM Student_Major ORDER BY student_ID,major_ID; SELECT * FROM Student_Minor ORDER BY student_ID,minor_ID');
(async()=>{
 const before=snapshot();const errors=[];const browser=await chromium.launch({headless:true,...(process.env.CHROME_PATH?{executablePath:process.env.CHROME_PATH}:{})});
 const body=async r=>{assert.equal(r.status(),200);return r.json();};
 let linksTested=0;
 try{
  const cases=[...new Set([100001,100018,Number(sql('SELECT MIN(student_ID) FROM Student_Minor')),Number(sql('SELECT student_ID FROM Student_Major GROUP BY student_ID HAVING COUNT(*)>1 LIMIT 1'))].filter(Boolean))];
  for(const id of cases){
   const context=await browser.newContext();await context.route("**/*",r=>r.request().url().startsWith(base+"/")?r.continue():r.abort());
   await body(await context.request.post(base+'/api/login',{data:{email:String(id),role:'Student',password:process.env.DEMO_PASSWORD||'Campus123!'}}));
   const view=await body(await context.request.get(base+'/api/views/degree-audit'));
   const audit=view.blocks.find(b=>b.type==='degree-audit').audit;
   const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
   for(const width of [1440,390]){
    await page.setViewportSize({width,height:844});
    await page.goto(base+'/#degree-audit');
    const links=page.locator('a.audit-checklist-link');await links.first().waitFor();
    assert.equal(await links.count(),audit.degree_requirements.checklist.length);
    for(let i=0;i<await links.count();i++){
     const link=links.nth(i),target=await link.getAttribute('aria-controls');assert.ok(target);
     assert.equal(await page.locator('[id="'+target+'"]').count(),1,'Exact unique section destination');
     await page.evaluate(id=>{const t=document.getElementById(id);if(t.tagName==='DETAILS')t.open=false;t.querySelectorAll('details').forEach(d=>d.open=false);},target);
     await link.focus();await page.keyboard.press('Enter');
     await page.waitForFunction(id=>document.activeElement?.id===id,target);
     await page.waitForFunction(id=>{const r=document.getElementById(id).getBoundingClientRect();return r.top<innerHeight&&r.bottom>0;},target);
     assert.ok(await page.evaluate(id=>{const t=document.getElementById(id);return(t.tagName!=='DETAILS'||t.open)&&[...t.querySelectorAll('details')].every(d=>d.open);},target));
     assert.equal(page.url(),base+'/#degree-audit');linksTested++;
    }
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   }
   const touchContext=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,storageState:await context.storageState()});
   await touchContext.route("**/*",r=>r.request().url().startsWith(base+"/")?r.continue():r.abort());const touch=await touchContext.newPage();await touch.goto(base+'/#degree-audit');const taps=touch.locator('a.audit-checklist-link');await taps.first().waitFor();
   for(let i=0;i<await taps.count();i++){
    const link=taps.nth(i),target=await link.getAttribute('aria-controls');await link.tap();
    await touch.waitForFunction(id=>document.activeElement?.id===id,target);linksTested++;
   }
   await touchContext.close();await context.close();
  }
  const context=await browser.newContext();await context.route("**/*",r=>r.request().url().startsWith(base+"/")?r.continue():r.abort());await body(await context.request.post(base+'/api/login',{data:{email:'100001',role:'Student',password:process.env.DEMO_PASSWORD||'Campus123!'}}));
  const preview=await context.newPage();await preview.goto(base+'/#degree-audit?preview_major=ECON-BS');
  const link=preview.locator('a.audit-checklist-link[aria-controls="audit-program-Major-ECON-BS"]');await link.waitFor();await link.click();
  await preview.waitForFunction(()=>document.activeElement?.id==='audit-program-Major-ECON-BS');assert.ok(preview.url().includes('preview_major=ECON-BS'));
  await context.close();assert.deepEqual(errors,[]);assert.equal(snapshot(),before);
  console.log('PASS '+linksTested+' exact checklist destinations: desktop/mobile keyboard + touch, major/minor/second-major, missing-rule sections, preview retained; academic records unchanged.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
