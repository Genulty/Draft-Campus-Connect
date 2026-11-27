async function revealSection(page, crn) {
  const row = page.locator(`tr[data-crn="${crn}"]`);
  await row.waitFor({state:'attached'});
  const group = page.locator('details.registration-course-group').filter({has:row});
  if (await group.count() && !(await group.evaluate(e=>e.open))) await group.locator(':scope > summary').click();
  return group;
}
// Fresh private fixture only; creates a normal full-time schedule, then reversible hold/seat probes.
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {writeFileSync}=require('node:fs');
const {chromium}=require('playwright');
const socket=process.argv[2],base=process.env.REGISTER_BASE||'http://127.0.0.1:3023',password=process.env.DEMO_PASSWORD;
assert.match(socket,/\/campus-student-review-db\.[A-Za-z0-9]+\/mysql\.sock$/);assert.match(base,/^http:\/\/(127\.0\.0\.1|localhost):\d+$/);assert.ok(password);
const sql=q=>execFileSync('mysql',['--no-defaults','--socket='+socket,'-uroot','-NBr','campus_connect'],{input:q,encoding:'utf8'}).trim();assert.equal(sql('SELECT @@skip_networking AND @@server_id=732156'),'1');
const table=(v,t)=>v.blocks.find(b=>b.title===t);
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});let crn=null,student=null,originalSeats=null,restrictionId=null;
 try{
  async function login(id,role='Student'){const c=await browser.newContext({hasTouch:true});await c.route('**/*',r=>r.request().url().startsWith(base+'/')?r.continue():r.abort());const q=await c.request.post(base+'/api/login',{data:{email:String(id),role,password}});assert.equal(q.status(),200,await q.text());return c;}
  const admin=await login(300001,'Admin');const created=await admin.request.post(base+'/api/actions/create-user',{data:{role:'Student',first_Name:'Restriction',last_Name:'Probe',gender:'Other',DOB:'2008-01-01',level:'Undergraduate',load:'Full-time',major_ID:'ECON-BS',password,confirm:password}});assert.equal(created.status(),200);student=Number((await created.json()).message.match(/\bID (\d+),/)[1]);
  const c=await login(student),view=async()=>{const r=await c.request.get(base+'/api/views/register?semester=SP27');assert.equal(r.status(),200,await r.text());return r.json();};
  for(const code of [10611,10555,10612,10579]){const r=await c.request.post(base+'/api/actions/add-section',{data:{CRN:code}});assert.equal(r.status(),200,await r.text());}
  const v=await view(),can=table(v,'Sections you can add');const extra=can.rows.find(r=>r[can.columns.indexOf('Credits')]===3);assert.ok(extra);assert.equal((await c.request.post(base+'/api/actions/add-section',{data:{CRN:extra[0]}})).status(),200);
  const atCap=await view(),blocked=table(atCap,"Sections you can't add");const needed=['standing','prerequisites','credits','conflict'];
  const choice=blocked.rows.find(r=>needed.every(code=>blocked.restrictions[r[0]].some(x=>x.code===code)));assert.ok(choice,'Need an actual section with independent program, standing, prerequisite, cap and meeting blocks');crn=choice[0];originalSeats=Number(sql('SELECT available_Seats FROM Course_Section WHERE CRN='+crn));
  sql(require('node:fs').readFileSync(require('node:path').join(__dirname,'fixtures/program-access-schema.sql'),'utf8'));
  const target=choice[blocked.columns.indexOf('Course')];assert.match(target,/^[A-Z]+[0-9]+$/);
  restrictionId=Number(sql(`INSERT INTO Course_Program_Restriction(course_ID,major_ID) VALUES('${target}','CS-BS'); SELECT LAST_INSERT_ID();`));
  sql(`INSERT INTO Student_Hold VALUES(${student},2,'2026-12-17'); UPDATE Course_Section SET available_Seats=0 WHERE CRN=${crn}`);
  const current=await view(),b=table(current,"Sections you can't add"),items=b.restrictions[crn],expected=[...needed,'program','hold','full'];for(const code of expected)assert.ok(items.some(i=>i.code===code),code+' was hidden by an earlier restriction');assert.equal(b.action,undefined);
  const state=()=>sql(`SELECT * FROM Enrollment WHERE student_ID=${student} ORDER BY CRN; SELECT CRN,available_Seats FROM Course_Section WHERE CRN=${crn}`),before=state();const refused=await c.request.post(base+'/api/actions/add-section',{data:{CRN:crn}});assert.equal(refused.status(),422);assert.equal((await refused.json()).error,b.rows.find(r=>r[0]===crn).at(-1));assert.equal(state(),before);
  const p=await c.newPage();await p.goto(base+'/#register?semester=SP27');await revealSection(p,crn);const row=p.locator(`tr[data-crn="${crn}"]`);await row.locator('.registration-reasons').waitFor();
  assert.equal(await p.getByRole('columnheader',{name:'Reason / Restriction',exact:true}).count()>0,true);assert.equal(await row.getByRole('button').count(),0);
  await row.locator('.registration-reasons').getByText(items[0].summary,{exact:true}).waitFor();assert.equal(await row.locator('details').getAttribute('open'),null);
  await row.locator('summary').focus();await p.keyboard.press('Enter');assert.notEqual(await row.locator('details').getAttribute('open'),null);assert.equal(await row.locator('.registration-api-reason').textContent(),b.rows.find(r=>r[0]===crn).at(-1));
  for(const item of items)await row.locator('details strong').getByText(item.summary,{exact:true}).waitFor();
  const prereqs=items.find(i=>i.code==='prerequisites').prerequisites;for(const req of prereqs)await row.getByText(`${req.id} · ${req.title} (grade ${req.min} or better)`,{exact:true}).waitFor();
  for(const conflict of items.find(i=>i.code==='conflict').conflicts)assert.ok((await row.locator('details').textContent()).includes('CRN '+conflict.CRN));
  await p.setViewportSize({width:390,height:900});assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await row.locator('summary').tap();assert.equal(await row.locator('details').getAttribute('open'),null);await row.locator('summary').tap();assert.notEqual(await row.locator('details').getAttribute('open'),null);
  if(process.env.REGISTER_EVIDENCE_DIR)writeFileSync(process.env.REGISTER_EVIDENCE_DIR+'/restriction-probe.json',JSON.stringify({student,crn,restrictions:items,raw:b.rows.find(r=>r[0]===crn).at(-1),schedule:table(current,'My Spring 2027 registration')},null,2)+'\n');
  console.log('PASS: seven simultaneous independent reasons, concise header/summaries, collapsed full explanations with exact prerequisites and conflicting CRNs, keyboard/mobile toggles, unchanged API refusal/seats/schedule, normal adds to 16.');
 }finally{if(restrictionId!==null)sql(`DELETE FROM Course_Program_Restriction WHERE restriction_ID=${restrictionId}`);if(crn!==null)sql(`UPDATE Course_Section SET available_Seats=${originalSeats} WHERE CRN=${crn}`);if(student!==null)sql(`DELETE FROM Student_Hold WHERE student_ID=${student} AND hold_ID=2`);await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
