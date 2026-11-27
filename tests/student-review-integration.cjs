const assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {writeFileSync}=require('node:fs');
const {chromium}=require('playwright');
const socket=process.argv[2];
assert.match(socket,/\/campus-student-review-db\.[A-Za-z0-9]+\/mysql\.sock$/);
const sql=q=>execFileSync('mysql',['--no-defaults','--protocol=socket','--socket='+socket,'-uroot','-NBr','campus_connect'],{input:q,encoding:'utf8'}).trim();
assert.equal(sql('SELECT @@skip_networking AND @@server_id=732156'),'1');
const base='http://127.0.0.1:3012';
const data=async(r,status=200)=>{assert.equal(r.status(),status,await r.text());return r.json();};
async function run(){
 const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});
 try{
 const admin=await browser.newContext();
 await data(await admin.request.post(base+'/api/login',{data:{email:'300001',role:'Admin',password:process.env.DEMO_PASSWORD}}));
 const created=await data(await admin.request.post(base+'/api/actions/create-user',{data:{
 role:'Student',first_Name:'Review',last_Name:'Economics',gender:'Other',DOB:'2008-01-01',
 level:'Undergraduate',load:'Full-time',major_ID:'ECON-BS',password:process.env.DEMO_PASSWORD,confirm:process.env.DEMO_PASSWORD}}));
 const id=Number(created.message.match(/\bID (\d+),/)[1]);
 const c=await browser.newContext();
 await c.route('**/*',r=>r.request().url().startsWith(base+'/')?r.continue():r.abort());
 await data(await c.request.post(base+'/api/login',{data:{email:String(id),role:'Student',password:process.env.DEMO_PASSWORD}}));
 const view=async name=>data(await c.request.get(base+'/api/views/'+name));
 const audit=(await view('degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
 assert.equal(audit.profile.enrollment_status,'Full-time');
 assert.equal(audit.credits.earned_provisional,0);
 assert.equal(audit.degree_requirements.status,'Rules unavailable');
 const p=await c.newPage();
 await p.goto(base+'/#register?semester=SP27');
 const plans=[];
 for(const [crn,credits]of[[10611,3],[10555,3],[10612,3],[10579,4]]){
  const table=p.locator('.table-card').filter({has:p.getByRole('heading',{name:'Sections you can add',exact:true})});
  const row=table.getByRole('row').filter({has:p.getByRole('cell',{name:String(crn),exact:true})});
  await row.getByRole('cell').filter({hasText:'Draft Gen Ed match'}).waitFor();
  const request=p.waitForResponse(r=>r.url().endsWith('/api/actions/add-section'));
  await row.getByRole('button',{name:'Add',exact:true}).click();
  await data(await request);
  const total=plans.reduce((n,c)=>n+c.credits,0)+credits;
  plans.push({crn,credits,total});
  await p.reload();
  await p.getByText(total+' of 16',{exact:true}).waitFor();
 }
 const before=await view('register?semester=SP27');
 for(const dept of['ECON','BIO','ENG','']){
  await p.locator('select[name=dept]').selectOption(dept);
  await p.waitForFunction(d=>document.querySelector('select[name=dept]')?.value===d,dept);
  const after=await view('register?semester=SP27&dept='+dept);
  assert.deepEqual(after.blocks.find(b=>b.type==='stats'),before.blocks.find(b=>b.type==='stats'));
  assert.deepEqual(after.blocks.find(b=>b.title==='My Spring 2027 registration'),before.blocks.find(b=>b.title==='My Spring 2027 registration'));
 }
 await p.locator('select[name=unmet]').selectOption('1');
 await p.waitForURL(/unmet=1/);
 await p.locator('select[name=unmet]').selectOption('');
 await p.waitForFunction(()=>document.querySelector('select[name=unmet]')?.value==='');
 assert.deepEqual((await view('register?semester=SP27')).blocks,before.blocks);
 const final=(await view('degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
 assert.equal(final.degree_requirements.status,'Rules unavailable');
 assert.equal(final.credits.earned_provisional,0);
 await p.goto(base+'/#degree-audit');
 await p.getByText('Rules unavailable',{exact:true}).first().waitFor();
 const snapshot=sql('SELECT * FROM Student_History WHERE student_ID='+id+';SELECT * FROM Enrollment WHERE student_ID='+id+' ORDER BY CRN');
 await p.reload();
 assert.equal(sql('SELECT * FROM Student_History WHERE student_ID='+id+';SELECT * FROM Enrollment WHERE student_ID='+id+' ORDER BY CRN'),snapshot);
 writeFileSync(__dirname+'/../../missing-rules-fulltime-result.json',JSON.stringify({id,major:'ECON-BS',target:'Rules unavailable',earned:0,plans},null,2)+'\n');
 console.log('PASS: Admin-created Economics student '+id+' reached 13 credits through labeled Gen Ed options; missing program credit rules remained Rules unavailable after refresh; filters preserved registrations and totals.');
 }finally{await browser.close();}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
