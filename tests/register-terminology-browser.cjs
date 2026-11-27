// Read-only walkthrough; safe on the running disposable demo. No course adds.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const base = process.env.REGISTER_BASE || 'http://127.0.0.1:3020';
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
(async () => {
 const browser = await chromium.launch({executablePath: process.env.CHROME_PATH});
 try {
  const c = await browser.newContext();
  await c.route('**/*', r => r.request().url().startsWith(base+'/') ? r.continue() : r.abort());
  assert.equal((await c.request.post(base+'/api/login',{data:{email:'100001',role:'Student',password:process.env.DEMO_PASSWORD}})).status(),200);
  const get = async path => {const r=await c.request.get(base+path);assert.equal(r.status(),200);return r.json();};
  const view=await get('/api/views/register?semester=SP27&dept=&unmet='),audit=(await get('/api/views/degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
  const groups=view.blocks.flatMap(b=>b.courseGroups||[]),group=code=>groups.find(g=>g.code===code);
  const bio=group('BIO220'),history=group('HIST110');
  assert.equal(bio.match,'Applies to: Liberal Arts Requirement');assert.equal(bio.matches[0].draft,audit.liberal_arts.draft);
  const gen=audit.gen_ed.find(r=>r.options.some(o=>o.code==='HIST110'));
  assert.equal(history.match,'Applies to: General Education — '+gen.name);assert.equal(history.matches[0].draft,true);
  // 100001's remaining major courses are already planned, so correctly have no
  // unmet-major recommendation. Find a real unplanned confirmed requirement.
  let confirmed;
  for (const id of [100004,100005,100006,100007,100008]) {
   assert.equal((await c.request.post(base+'/api/login',{data:{email:String(id),role:'Student',password:process.env.DEMO_PASSWORD}})).status(),200);
   const other=await get('/api/views/register?semester=SP27&dept=&unmet=');
   const candidate=other.blocks.flatMap(b=>b.courseGroups||[]).find(g=>g.matches.some(m=>m.kind==='Major Requirement'&&!m.draft));
   if(candidate){const otherAudit=(await get('/api/views/degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
    const required=otherAudit.programs.flatMap(p=>p.requirements).find(r=>r.code===candidate.code);
    assert.equal(candidate.match,'Applies to: Major Requirements — '+required.title);confirmed={id,group:candidate};break;}
  }
  assert.ok(confirmed,'No real unplanned major requirement found');
  await c.request.post(base+'/api/login',{data:{email:'100001',role:'Student',password:process.env.DEMO_PASSWORD}});
  const p=await c.newPage();await p.goto(base+'/#register?semester=SP27&dept=&unmet=');
  const bg=p.locator('details[data-course="BIO220"]');await bg.waitFor();
  const badge=bg.locator(':scope > summary .registration-provisional');await badge.waitFor();
  assert.equal(await badge.getAttribute('role'),'note');assert.match(await badge.getAttribute('aria-label'),/not been academically approved.*graduation eligibility/);
  await badge.focus();await badge.evaluate(e=>{if(document.activeElement!==e)throw Error('Approval indicator cannot receive keyboard focus');});
  assert.equal(await p.locator('details[data-course="BIO115"] > summary .registration-match-label').textContent(),'Requirement match unavailable');
  if(!(await bg.evaluate(e=>e.open)))await bg.locator(':scope > summary').click();
  assert.equal(await bg.locator('.registration-course-content .registration-match').count(),0);
  assert.equal(await bg.getByText('Linked lab',{exact:true}).count(),0);
  await bg.getByText('Lab',{exact:true}).waitFor();await bg.getByText('Lecture',{exact:true}).waitFor();
  assert.equal(await bg.locator('.registration-meeting-fields').count(),2);
  for(const field of ['Days','Time','Location'])assert.equal(await bg.locator('.registration-meeting-fields dt').filter({hasText:new RegExp('^'+field+'$')}).count(),2);
  const hg=p.locator('details[data-course="HIST110"]');if(!(await hg.evaluate(e=>e.open)))await hg.locator(':scope > summary').click();
  const more=hg.locator('.registration-other-matches');await more.locator('summary').click();
  await more.getByText('Applies to: Liberal Arts Requirement',{exact:true}).waitFor();assert.ok(await more.locator('.registration-provisional').count()>0);
  for(const width of [1440,390]){await p.setViewportSize({width,height:960});await bg.scrollIntoViewIfNeeded();assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   if(process.env.REGISTER_EVIDENCE_DIR)await p.screenshot({path:process.env.REGISTER_EVIDENCE_DIR+'/terminology-'+width+'.png'});
  }
  await p.goto(base+'/#degree-audit');
  for(const name of ['Major Requirements','General Education Requirements','Liberal Arts Requirement'])await p.getByRole('heading',{name,exact:true}).waitFor();
  await c.request.post(base+'/api/login',{data:{email:String(confirmed.id),role:'Student',password:process.env.DEMO_PASSWORD}});
  await p.goto(base+'/#register?semester=SP27&dept=&unmet=');
  const confirmedSummary=p.locator(`details[data-course="${confirmed.group.code}"] > summary`);
  await confirmedSummary.waitFor();assert.equal(await confirmedSummary.locator('.registration-match-label').textContent(),confirmed.group.match);
  assert.equal(await confirmedSummary.locator('.registration-provisional').count(),0);
  console.log(`Confirmed mapping: student ${confirmed.id}, ${confirmed.group.code}, ${confirmed.group.match}`);
  console.log('PASS actual Register/Degree Audit names, confirmed/provisional metadata and accessible explanations, multiple and absent matches, lecture/lab fields, keyboard focus, desktop/mobile; no academic writes.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
