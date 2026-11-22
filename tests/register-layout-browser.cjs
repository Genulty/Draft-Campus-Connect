// Opt-in browser/API coverage. Only the disposable Student review fixture is accepted.
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {writeFileSync} = require('node:fs');
const {chromium} = require('playwright');
const socket = process.argv[2], base = process.env.REGISTER_BASE || 'http://127.0.0.1:3020';
assert.match(socket, /\/campus-student-review-db\.[A-Za-z0-9]+\/mysql\.sock$/);
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
const password = process.env.DEMO_PASSWORD;
assert.ok(password, 'Set DEMO_PASSWORD to the local fixture password.');
const sql = q => execFileSync('mysql', ['--no-defaults', '--socket='+socket, '-uroot', '-NBr', 'campus_connect'], {input:q,encoding:'utf8'}).trim();
assert.equal(sql('SELECT @@skip_networking AND @@server_id=732156'), '1');
const table = (v,t) => v.blocks.find(b => b.title === t);
const records = () => sql('SELECT * FROM Student_History WHERE student_ID IN (100001,100018) ORDER BY student_ID,CRN; SELECT * FROM Enrollment WHERE student_ID IN (100001,100018) ORDER BY student_ID,CRN');
const result = {base, checks:[], examples:[]};
(async () => {
 const before=records(); const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});
 try {
  async function login(id,role='Student') {
   const c=await browser.newContext();
   await c.route('**/*', r => r.request().url().startsWith(base+'/') ? r.continue() : r.abort());
   const reply=await c.request.post(base+'/api/login',{data:{email:String(id),role,password}});
   assert.equal(reply.status(),200,await reply.text()); return c;
  }
  const payload = async (c,path) => {const r=await c.request.get(base+'/api/views/'+path);assert.equal(r.status(),200,await r.text());return r.json();};
  const register=c=>payload(c,'register?semester=SP27');
  async function rejected(c,crn,pattern) {
   const before=sql(`SELECT * FROM Enrollment ORDER BY student_ID,CRN; SELECT CRN,available_Seats FROM Course_Section WHERE CRN=${Number(crn)}`);
   const view=await register(c);const b=table(view,"Sections you can't add");const row=b.rows.find(r=>r[0]===crn);
   assert.ok(row,`Blocked section ${crn} not listed`); assert.equal(b.action,undefined);assert.match(row.at(-1),pattern);
   const reply=await c.request.post(base+'/api/actions/add-section',{data:{CRN:crn}});assert.equal(reply.status(),422);
   const data=await reply.json();assert.equal(data.error,row.at(-1));
   const page=await c.newPage();await page.goto(base+'/#register?semester=SP27');
   const displayed=page.locator(`tr[data-crn="${crn}"]`);
   if(b.prerequisitesNeeded?.[crn]?.length)await displayed.getByText('Prerequisites needed',{exact:true}).waitFor();
   else await displayed.getByText(data.error,{exact:true}).waitFor();
   assert.equal(await displayed.getByRole('button').count(),0);await page.close();
   assert.equal(sql(`SELECT * FROM Enrollment ORDER BY student_ID,CRN; SELECT CRN,available_Seats FROM Course_Section WHERE CRN=${Number(crn)}`),before);
   return {view,block:b,row};
  }
  for (const [id,credits,standing] of [[100001,59,'Junior'],[100018,50,'Sophomore']]) {
   const c=await login(id);const audit=(await payload(c,'degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
   assert.equal(audit.credits.earned_provisional,credits);assert.equal(audit.profile.year,standing);
   const view=await register(c);for(const b of view.blocks.filter(b=>b.layout==='registration'&&b.columns.includes('Prerequisites'))) {
    assert.equal(b.columns[b.columns.indexOf('Requirement match')-1],'Prerequisites');assert.ok(b.rows.every(r=>r.length===b.columns.length));
   }
   const p=await c.newPage();await p.goto(base+'/#register?semester=SP27');await p.locator('.registration-table').first().waitFor();
   assert.equal(await p.getByRole('button',{name:'Try',exact:true}).count(),0);
   for(const width of [1440,1024,390]) {
    await p.setViewportSize({width,height:1000});
    assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`overflow at ${width}`);
    assert.ok(await p.locator('.registration-table').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)));
    if(width===390)assert.equal(await p.locator('.registration-table tbody td').first().evaluate(e=>getComputedStyle(e,'::before').content),'"Course"');
   }
   if(process.env.REGISTER_EVIDENCE_DIR)await p.screenshot({path:process.env.REGISTER_EVIDENCE_DIR+`/register-${id}-mobile.png`,fullPage:true});
   result.examples.push({id,credits,standing});await c.close();
  }
  result.checks.push('Reference standing, full row data, adjacent prerequisite/match fields, no Try, desktop/tablet/mobile without horizontal overflow');
  const admin=await login(300001,'Admin');
  async function create(major,name) {
   const r=await admin.request.post(base+'/api/actions/create-user',{data:{role:'Student',first_Name:'Layout',last_Name:name,gender:'Other',DOB:'2008-01-01',level:'Undergraduate',load:'Full-time',major_ID:major,password,confirm:password}});
   assert.equal(r.status(),200,await r.text());return Number((await r.json()).message.match(/\bID (\d+),/)[1]);
  }
  // Small explicit test-only profile: copy existing posted evidence to a NEW Biology student.
  // No reference transcript, catalog rule, credit value or 181-profile reconstruction is changed.
  const junior=await create('BIO-BS','PrerequisiteFixture');
  sql(`INSERT INTO Student_History(student_ID,CRN,course_ID,semester_ID,grade) SELECT ${junior},CRN,course_ID,semester_ID,grade FROM Student_History WHERE student_ID=100001`);
  const jc=await login(junior),jv=await register(jc),jb=table(jv,"Sections you can't add");
  const multi=jb.rows.find(r=>(jb.prerequisitesNeeded?.[r[0]]?.length||0)>=2);assert.ok(multi,'No real multi-prerequisite case');
  const crn=multi[0],needed=jb.prerequisitesNeeded[crn];
  const confirmed=multi[jb.columns.indexOf('Requirement match')];assert.match(confirmed,/Major requirement/);
  await rejected(jc,crn,/Missing prerequisite/);
  const jp=await jc.newPage();await jp.goto(base+'/#register?semester=SP27');
  const jr=jp.locator(`tr[data-crn="${crn}"]`);await jr.getByText('Prerequisites needed',{exact:true}).waitFor();
  await jr.getByText(confirmed,{exact:true}).waitFor();
  for(const req of needed)await jr.getByText(`${req.id} · ${req.title} (grade ${req.min} or better)`,{exact:true}).waitFor();
  await jp.setViewportSize({width:390,height:1000});assert.ok(await jp.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  result.examples.push({id:junior,case:'test-only copied 59-credit evidence; missing existing Biology prerequisites',crn,needed});
  result.checks.push('Multiple exact prerequisite codes, catalog titles and minimum grades; API refusal unchanged; no enrollment or seat mutation');
  for(const [id,crn,pattern,label] of [[100009,10611,/already passed/,'already passed'],[100019,10579,/hold/,'hold']]){
   const c=await login(id);await rejected(c,crn,pattern);await c.close();result.checks.push(label+' UI/API agreement');
  }
  const english=await login(100018),ev=await register(english),eb=table(ev,"Sections you can't add");
  const standing=eb.rows.find(r=>/at least a Junior/.test(r.at(-1)));assert.ok(standing);await rejected(english,standing[0],/you're a Sophomore/);await english.close();
  result.checks.push('Sophomore standing restriction uses earned credits');
  const fresh=await create('ECON-BS','FullTime'),fc=await login(fresh),fp=await fc.newPage();await fp.goto(base+'/#register?semester=SP27');
  result.schedule=[];
  for(const [crn,total]of [[10611,3],[10555,6],[10612,9],[10579,13]]) {
   const row=fp.locator(`tr[data-crn="${crn}"]`);await row.getByRole('button',{name:'Add',exact:true}).waitFor();
   const response=fp.waitForResponse(r=>r.url().endsWith('/api/actions/add-section'));
   await row.getByRole('button',{name:'Add',exact:true}).click();assert.equal((await response).status(),200);
   await fp.reload();const v=await register(fc);assert.equal(v.blocks.find(b=>b.type==='stats').items.find(i=>i.label==='Registered credits').value,`${total} of 16`);
   result.schedule.push({crn,total});
  }
  const enrolled=table(await register(fc),'My Spring 2027 registration');assert.ok(enrolled.rows.find(r=>r[0]===10579)[enrolled.columns.indexOf('Lab time')]);
  const original=await register(fc);for(const dept of ['BIO','ENG','']) {
   await fp.locator('select[name=dept]').selectOption(dept);await fp.waitForFunction(d=>document.querySelector('select[name=dept]')?.value===d,dept);
   const p=await payload(fc,'register?semester=SP27&dept='+dept);assert.deepEqual(table(p,'My Spring 2027 registration'),enrolled);
   assert.deepEqual(p.blocks.find(b=>b.type==='stats'),original.blocks.find(b=>b.type==='stats'));
  }
  const all=await register(fc);assert.ok(all.blocks.find(b=>b.type==='stats').items.some(i=>i.value==='Goal reached'));
  const conflict=table(all,"Sections you can't add").rows.find(r=>/same time/.test(r.at(-1)));assert.ok(conflict);await rejected(fc,conflict[0],/same time/);
  const addable=table(all,'Sections you can add'),extra=addable.rows.find(r=>r[addable.columns.indexOf('Credits')]===3);assert.ok(extra);
  assert.equal((await fc.request.post(base+'/api/actions/add-section',{data:{CRN:extra[0]}})).status(),200);
  const max=await register(fc);const capped=table(max,"Sections you can't add").rows.find(r=>/the limit/.test(r.at(-1)));assert.ok(capped);await rejected(fc,capped[0],/the limit/);
  result.checks.push('New full-time Economics: adds one at a time 3/6/9/13, linked lab retained, goal reached, filters preserve total, conflicts refused, 16-credit cap retained');
  const full=await create('ENG-BS','SeatFixture'),fullc=await login(full);
  const oldSeats=sql('SELECT available_Seats FROM Course_Section WHERE CRN=10611');
  try{sql('UPDATE Course_Section SET available_Seats=0 WHERE CRN=10611');await rejected(fullc,10611,/is full/);}
  finally{sql(`UPDATE Course_Section SET available_Seats=${Number(oldSeats)} WHERE CRN=10611`);}
  result.checks.push('Full section reason and rejection, controlled seat fixture restored');
  assert.equal(records(),before,'Reference transcripts/enrollments changed');
  if(process.env.REGISTER_EVIDENCE_DIR)writeFileSync(process.env.REGISTER_EVIDENCE_DIR+'/register-layout-results.json',JSON.stringify(result,null,2)+'\n');
  console.log('PASS Register layout browser/API: '+result.checks.join('; '));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
