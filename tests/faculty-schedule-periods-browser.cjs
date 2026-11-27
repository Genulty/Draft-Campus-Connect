// Real local HTTP + SELECT-only meeting oracle. Never adds/changes any record.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const base = process.env.FACULTY_BASE || 'http://127.0.0.1:3036';
const socket = process.env.DB_SOCKET;
assert.ok(socket, 'Pass the disposable database socket');
const sql = q => JSON.parse(execFileSync('mysql', ['--no-defaults', '--socket=' + socket, '-uroot', '-NBr', 'campus_connect', '-e', q], { encoding: 'utf8' }));
const meetings = sql(`SELECT JSON_ARRAYAGG(JSON_OBJECT('semester',semester,'crn',CRN,'period',period_ID,'day',day_ID,'room',room_ID,'building',building_ID,'kind',kind)) FROM (
 SELECT cs.semester_ID semester,cs.CRN,tp.period_ID,td.day_ID,cs.room_ID,r.building_ID,'Lecture' kind
 FROM Course_Section cs JOIN Time_Slot_Period tp ON tp.time_Slot_ID=cs.time_Slot_ID JOIN Time_Slot_Day td ON td.time_Slot_ID=cs.time_Slot_ID JOIN Room r ON r.room_ID=cs.room_ID
 UNION ALL SELECT cs.semester_ID,cs.CRN,tp.period_ID,td.day_ID,lab.lab_ID,r.building_ID,'Lab'
 FROM Course_Section cs JOIN Course_Section_Lab lab ON lab.CRN=cs.CRN JOIN Time_Slot_Period tp ON tp.time_Slot_ID=lab.time_Slot_ID JOIN Time_Slot_Day td ON td.time_Slot_ID=lab.time_Slot_ID JOIN Room r ON r.room_ID=lab.lab_ID
) m WHERE semester IN ('FA26','SP27')`);
(async () => {
 const browser = await chromium.launch({headless:true,...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
 try {
  const context = await browser.newContext();
  const login = await context.request.post(base+'/api/login',{data:{email:'200001',role:'Faculty',password:process.env.DEMO_PASSWORD || 'Campus123!'}});
  assert.equal(login.status(),200,'Disposable Faculty login');
  const page = await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  const select = name => page.locator('#page-blocks select[name="'+name+'"]');
  async function change(name,value) {
   const response=page.waitForResponse(r=>r.url().includes('/api/views/master-schedule') && r.request().method()==='GET');
   await select(name).selectOption(String(value)); const r=await response; assert.equal(r.status(),200); const data=await r.json();
   await page.waitForFunction(({name,value})=>document.querySelector('#page-blocks select[name="'+name+'"]')?.value===value,{name,value:String(value)});
   const table=data.blocks.find(b=>b.type==='table');
   const expected=table.rows.map(r=>String(r[table.columns.indexOf('CRN')]));
   await page.waitForFunction(expected=>JSON.stringify([...document.querySelectorAll('#page-blocks tbody tr')].map(r=>r.cells[0]?.textContent.trim()))===JSON.stringify(expected),expected);
   return {data,expected};
  }
  await page.goto(base+'/#master-schedule?semester=FA26'); await select('period').waitFor();
  let checks=0;
  for (const width of [1440,390]) {
   await page.setViewportSize({width,height:950});
   for (const semester of ['FA26','SP27']) {
    if(await select('semester').inputValue()!==semester) await change('semester',semester);
    const term=meetings.filter(m=>m.semester===semester);
    const expected=[...new Set(term.map(m=>Number(m.period)))].sort((a,b)=>a-b);
    assert.deepEqual((await select('period').locator('option').evaluateAll(o=>o.slice(1).map(x=>Number(x.value)))),expected);
    for(const id of expected) {
     const {expected:actual}=await change('period',id);
     assert.deepEqual([...new Set(actual)].sort(),[...new Set(term.filter(m=>Number(m.period)===id).map(m=>String(m.crn)))].sort());checks++;
    }
    const lab=term.find(m=>m.kind==='Lab'); assert.ok(lab);
    await change('period',lab.period);await change('day',lab.day);await change('room',lab.room);
    const {expected:actual}=await change('building',lab.building);
    assert.deepEqual([...new Set(actual)].sort(),[...new Set(term.filter(m=>String(m.period)===String(lab.period)&&m.day===lab.day&&m.room===lab.room&&m.building===lab.building).map(m=>String(m.crn)))].sort());
    const cleared=page.waitForResponse(r=>r.url().includes('/api/views/master-schedule'));
    await page.getByRole('button',{name:'Clear filters',exact:true}).click();const response=await cleared;assert.equal(response.status(),200);
    await page.waitForFunction(()=>['period','day','room','building'].every(name=>document.querySelector('select[name="'+name+'"]')?.value===''));
    assert.equal(await select('semester').inputValue(),semester);
    const restored=await response.json();assert.equal(restored.blocks.find(b=>b.type==='table').rows.length,new Set(term.map(m=>String(m.crn))).size);
    console.log(JSON.stringify({width,semester,periods:expected,labCRN:lab.crn,clear:'semester retained'}));
   }
  }
  assert.deepEqual(errors,[]);console.log('PASS real Faculty browser/API: '+checks+' period selections, exact lecture/lab CRNs, semester updates, combined meeting filters and Clear Filters at desktop/mobile.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
