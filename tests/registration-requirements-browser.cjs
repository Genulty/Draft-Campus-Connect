async function revealSection(page, crn) {
  const row = page.locator(`tr[data-crn="${crn}"]`);
  await row.waitFor({state:'attached'});
  const group = page.locator('details.registration-course-group').filter({has:row});
  if (await group.count() && !(await group.evaluate(e=>e.open))) await group.locator(':scope > summary').click();
  return group;
}
const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('playwright');
const socket=process.argv[2];
assert.match(socket,/\/campus-student-review-db\.[A-Za-z0-9]+\/mysql\.sock$/);
const sql=q=>execFileSync('mysql',['--no-defaults','--protocol=socket','--socket='+socket,'-uroot','-NBr','campus_connect'],{input:q,encoding:'utf8'}).trim();
assert.equal(sql('SELECT @@skip_networking AND @@server_id=732156'),'1');
const base=process.env.REGISTER_BASE || 'http://127.0.0.1:3012',password=process.env.DEMO_PASSWORD;
assert.match(base, /^http:\/\/(localhost|127\.0\.0\.1):\d+$/);
assert.ok(password);
const data=async(res,status=200)=>{assert.equal(res.status(),status,await res.text());return res.json();};
const table=(v,name)=>v.blocks.find(b=>b.title===name);
const recordState=()=>sql('SELECT * FROM Student ORDER BY student_ID; SELECT * FROM Student_History ORDER BY student_ID,CRN;'+
 'SELECT * FROM Student_Major ORDER BY student_ID,major_ID; SELECT * FROM Enrollment ORDER BY student_ID,CRN;'+
 'SELECT CRN,available_Seats FROM Course_Section ORDER BY CRN');
let newCrn;
async function run(){
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});
 try{
  const login=async(id,role='Student')=>{
   const c=await browser.newContext();
   await c.route('**/*',r=>r.request().url().startsWith(base+'/')?r.continue():r.abort());
   await data(await c.request.post(base+'/api/login',{data:{email:String(id),role,password}}));
   return c;
  };
  const c=await login(100072);
  const view=async(query='')=>data(await c.request.get(base+'/api/views/register?semester=SP27'+query));
  const count=Number(sql("SELECT COUNT(*) FROM Enrollment e JOIN Course_Section cs USING(CRN) WHERE e.student_ID=100072 AND cs.semester_ID='SP27'"));
  if(count===0){
   for(const crn of [10555,10597,10927])await data(await c.request.post(base+'/api/actions/add-section',{data:{CRN:crn}}));
  }else assert.equal(count,3,'Use the untouched or already-prepared nine-credit fixture');
  const before=recordState();
  const initial=await view();
  const stats=initial.blocks.find(b=>b.type==='stats');
  assert.equal(stats.items.find(i=>i.label==='Registered credits').value,'9 of 16');
  const blocked=table(initial,"Sections you can't add");
  const at=blocked.columns.indexOf('Requirement match');
  assert.equal(blocked.columns[at-1],'Prerequisites');
  for(const crn of [10931,10932]){
   const row=blocked.rows.find(r=>r[0]===crn);
   assert.equal(row[at],'Applies to: General Education — World History and Cultures');
   assert.match(row.at(-1),/same time/);
  }
  for(const b of initial.blocks.filter(b=>b.type==='table'&&b.columns.includes('Requirement match'))){
   for(const r of b.rows.filter(r=>r[b.columns.indexOf('Course')]==='MATH110'))
    assert.equal(r[b.columns.indexOf('Requirement match')],null,'Already enrolled category was relabeled');
  }
  const p=await c.newPage(),errors=[];
  p.on('pageerror',e=>errors.push(e.message));
  await p.goto(base+'/#register?semester=SP27');
  const cards=title=>p.locator('.table-card').filter({has:p.getByRole('heading',{name:title,exact:true})});
  await cards("Sections you can't add").getByText('Applies to: General Education — World History and Cultures',{exact:true}).first().waitFor();
  for(const width of [1440,390]){
   await p.setViewportSize({width,height:900});
   assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
   assert.equal(await p.locator('.registration-course-summary .registration-match').first().evaluate(e=>getComputedStyle(e).whiteSpace),'normal');
  }
  await p.setViewportSize({width:1440,height:900});
  await p.getByRole('combobox',{name:'Requirement matches',exact:true}).selectOption('1');
  await p.waitForURL(/unmet=1/);
  await p.getByText('No addable sections match an unmet requirement for this term.',{exact:true}).waitFor();
  const filtered=await view('&unmet=1');
  assert.deepEqual(table(filtered,'My Spring 2027 registration'),table(initial,'My Spring 2027 registration'));
  assert.deepEqual(filtered.blocks.find(b=>b.type==='stats'),stats);
  for(const b of filtered.blocks.filter(b=>b.type==='table'&&b.columns.includes('Requirement match')))
   assert.ok(b.rows.every(r=>r[b.columns.indexOf('Requirement match')]));
  await p.reload();
  assert.equal(await p.getByRole('combobox',{name:'Requirement matches',exact:true}).inputValue(),'1');
  await p.locator('select[name=dept]').selectOption('HIST');
  await p.waitForURL(/dept=HIST/);
  await cards('Sections you can add').getByRole('button',{name:'Show all departments',exact:true}).click();
  await p.waitForFunction(()=>document.querySelector('select[name=dept]')?.value===''&&document.querySelector('select[name=unmet]')?.value==='1');
  for(const crn of [10931,10932]){
   const refused=await data(await c.request.post(base+'/api/actions/add-section',{data:{CRN:crn}}),422);
   assert.equal(refused.error,blocked.rows.find(r=>r[0]===crn).at(-1));
  }
  assert.equal(recordState(),before,'Reading/filtering changed academic records or registrations');
  await p.getByRole('combobox',{name:'Requirement matches',exact:true}).selectOption('');
  await p.waitForFunction(()=>document.querySelector('select[name=unmet]')?.value==='');
  const off=await view();
  assert.deepEqual(table(off,'Sections you can add'),table(initial,'Sections you can add'));
  assert.equal((await view('&unmet[]=1')).blocks[0].filters.find(f=>f.name==='unmet').value,'');

  const other=await login(100001);
  const otherView=await data(await other.request.get(base+'/api/views/register?semester=SP27'));
  for(const b of otherView.blocks.filter(b=>b.columns?.includes('Requirement match'))){
   const code=b.columns.indexOf('Course'),label=b.columns.indexOf('Requirement match');
   for(const r of b.rows.filter(r=>['BIO110','PHYS110'].includes(r[code])))assert.ok(!(r[label]||'').includes('Natural Sciences'));
   for(const r of b.rows.filter(r=>r[code]==='CS445'))assert.equal(r[label],null,'Completed major requirement was relabeled');
  }
  const math=await login(100014);
  const mathView=await data(await math.request.get(base+'/api/views/register?semester=SP27'));
  const mathCourses=table(mathView,'Sections you can add');
  assert.match(mathCourses.rows.find(r=>r[mathCourses.columns.indexOf('Course')]==='MATH110')[mathCourses.columns.indexOf('Requirement match')],
   /Applies to: Major Requirements.*Calculus I/);
  assert.deepEqual(await view('&student_ID=100001&preview_major=CS-BS'),initial,'Another student or preview parameter changed the signed-in matches');

  const admin=await login(300001,'Admin');
  const form={semester_ID:'SP27',course_ID:'HIST110',faculty_ID:200071,time_Slot_ID:2,room_ID:'ACB-104',max_Seats:10};
  await data(await admin.request.post(base+'/api/actions/create-section',{data:{...form,check_only:true}}));
  const created=await data(await admin.request.post(base+'/api/actions/create-section',{data:form}));
  newCrn=Number(created.message.match(/CRN (\d+)/)[1]);
  const alternate=table(await view('&unmet=1'),'Sections you can add');
  assert.equal(alternate.rows.find(r=>r[0]===newCrn)[alternate.columns.indexOf('Requirement match')],
   'Applies to: General Education — World History and Cultures');
  await p.reload();
  await revealSection(p,newCrn);
  const row=cards('Sections you can add').locator(`tr[data-crn="${newCrn}"]`);
  const pending=p.waitForResponse(r=>r.url().endsWith('/api/actions/add-section'));
  await row.getByRole('button',{name:'Add',exact:true}).click();
  await data(await pending);
  await p.reload();
  const covered=await view();
  for(const b of covered.blocks.filter(b=>b.columns?.includes('Requirement match'))){
   for(const r of b.rows.filter(r=>r[b.columns.indexOf('Course')]==='HIST110'))
    assert.equal(r[b.columns.indexOf('Requirement match')],null,'Same-term requirement was still recommended');
  }
  assert.equal(covered.blocks.find(b=>b.type==='stats').items.find(i=>i.label==='Registered credits').value,'12 of 16');
  await data(await c.request.post(base+'/api/actions/drop-section',{data:{CRN:newCrn}}));
  await p.reload();
  assert.deepEqual(errors,[]);
  console.log('PASS Chromium/API: personal draft and major labels, unchanged conflict refusals, filter on/off/refresh and department composition, completed and same-term suppression, desktop/mobile wrapping, isolated account parameters, and a valid new alternate matched and added normally.');
 }finally{
  await browser.close();
  if(newCrn){
   assert.equal(sql('SELECT COUNT(*) FROM Enrollment WHERE CRN='+newCrn),'0');
   sql("DELETE FROM Course_Section WHERE CRN="+newCrn+" AND course_ID='HIST110' AND faculty_ID=200071 AND semester_ID='SP27'");
  }
 }
}
run().catch(e=>{console.error(e);process.exitCode=1;});
