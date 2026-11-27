// Student acceptance using Chromium, published API routes, and a private disposable fixture.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const [base, recovery, socket] = process.argv.slice(2);
const password = 'Campus123!';
const db = sql => execFileSync('mysql', ['--no-defaults','--protocol=socket',`--socket=${socket}`,'-uroot',
   '--batch','--raw','--skip-column-names','campus_connect'],{input:sql,encoding:'utf8'}).trim();
assert.match(socket, /^\/.*\/campus-student-acceptance\.[A-Za-z0-9]+\/mysql\.sock$/);
assert.equal(db(`SELECT @@socket='${socket}' AND @@skip_networking=1`),'1');
const table = (data, title) => data.blocks.find(b => b.type === 'table' && b.title.startsWith(title));
const action = (context, name, data, origin=base) => context.request.post(origin+'/api/actions/'+name,{data});
async function get(context, path, status=200, origin=base) {
  const res=await context.request.get(origin+'/api/views/'+path);
  assert.equal(res.status(),status,`${path} ${await res.text()}`); return res.json();
}
async function expectResponse(res,status,match) {
  assert.equal(res.status(),status,await res.text());
  const data=await res.json();
  if(match) assert.match(data.error ?? data.message,match);
  return data;
}
async function login(context,id,origin=base,pass=password,status=200) {
  return expectResponse(await context.request.post(origin+'/api/login',
    {data:{email:String(id),password:pass,role:'Student'}}),status);
}
const schedule = v=>table(v,'My Spring 2027 registration').rows;
const credit = v=>v.blocks.find(b=>b.type==='stats').items.find(i=>i.label==='Registered credits').value;
const audit = v=>v.blocks.find(b=>b.type==='degree-audit').audit;

async function run() {
  const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});
  const context = async (id,origin=base) => {
    const c=await browser.newContext();
    await c.route('**/*',route=>route.request().url().startsWith(origin+'/')?route.continue():route.abort());
    if(id) await login(c,id,origin);
    return c;
  };
  const errors=[];
  async function check(name,fn) {
    try { await fn(); console.log('PASS', name); }
    catch(error){errors.push(name);console.error('FAIL',name,error);}
  }
  async function open(page, view, heading) {
    await page.goto(base+'/#'+view);
    await page.getByRole('heading',{name:heading,exact:true}).first().waitFor();
  }
  try {
    await check('UC-01 and UC-05 browser login opens own Overview; anonymous views denied',async()=>{
      const c=await context();
      for(const route of ['overview','schedule','grades','transcript','degree-audit','holds-advisors','account','programs']) await get(c,route,401);
      const page=await c.newPage();
      await page.goto(base+'/');
      await page.locator('.w-cta [data-go=login]').click();
      await page.locator('#login-form [name=email]').fill('100014');
      await page.locator('#login-form [name=password]').fill(password);
      await page.locator('#login-form button[type=submit]').click();
      await page.locator('#me-id').getByText('ID 100014',{exact:true}).waitFor();
      await page.locator('#page-blocks .student-overview').waitFor();
      await page.reload();
      await page.locator('#page-blocks .student-overview').waitFor();
      await c.close();
    });

    await check('UC-02 invalid login denied, account locked after three failures',async()=>{
      const c=await context();
      for(let i=0;i<3;i++) await login(c,100080,base,'FixtureWrong123!',401);
      assert.match((await login(c,100080,base,password,423)).error,/locked/);
      await get(c,'overview',401);
      assert.equal(db('SELECT CONCAT(no_Of_Tries,":",lock_Var) FROM Login WHERE user_ID=100080'),'3:1');
      await c.close();
    });

    await check('UC-06/07/24/26/27 Add, reload, next schedule, cancel or confirm Drop, closed/no-enrollment rejection',async()=>{
      const c=await context(100014);
      const page=await c.newPage();
      await open(page,'schedule','My schedule');
      await page.getByRole('heading',{name:'Fall 2026 (current semester)',exact:true}).waitFor();
      assert.ok(table(await get(c,'schedule'),'Fall 2026').rows.length);
      assert.equal(table(await get(c,'schedule'),'Spring 2027').rows.length,0);
      const before=await get(c,'register');
      assert.equal(credit(before),'0 of 16');
      await open(page,'register','Register for Spring 2027');
      assert.equal(await page.locator('select[name=dept]').inputValue(),'');
      const canAdd=page.locator('.table-card').filter({has:page.getByRole('heading',{name:'Sections you can add',exact:true})});
      const findRow=card=>card.getByRole('row').filter({has:page.getByRole('cell',{name:'10579',exact:true})});
      const clicked=page.waitForResponse(r=>r.url().endsWith('/api/actions/add-section'));
      await findRow(canAdd).getByRole('button',{name:'Add',exact:true}).click();
      await expectResponse(await clicked,200,/Added BIO110/);
      await page.reload();
      const mySchedule=page.locator('.table-card').filter({has:page.getByRole('heading',{name:'My Spring 2027 registration',exact:true})});
      const drop=findRow(mySchedule).getByRole('button',{name:'Drop',exact:true});
      await drop.waitFor();
      assert.equal(credit(await get(c,'register')),'4 of 16');
      const next=table(await get(c,'schedule'),'Spring 2027');
      assert.equal(next.rows[0][0],10579);
      assert.ok(next.rows[0][next.columns.indexOf('Lab time')]);
      let writes=0;
      page.on('request',r=>{if(r.url().endsWith('/api/actions/drop-section')) writes++;});
      const denied=page.waitForEvent('dialog');
      const cancelClick=drop.click(); await (await denied).dismiss(); await cancelClick;
      assert.equal(writes,0);
      assert.equal(credit(await get(c,'register')),'4 of 16');
      const confirmed=page.waitForEvent('dialog');
      const reply=page.waitForResponse(r=>r.url().endsWith('/api/actions/drop-section'));
      const acceptClick=drop.click(); await (await confirmed).accept(); await acceptClick;
      await expectResponse(await reply,200,/Dropped BIO110/);
      await page.reload();
      await open(page,'schedule','My schedule');
      assert.equal(table(await get(c,'schedule'),'Spring 2027').rows.length,0);
      assert.deepEqual(schedule(await get(c,'register')),schedule(before));
      assert.equal(credit(await get(c,'register')),'0 of 16');
      await expectResponse(await action(c,'drop-section',{CRN:10579}),422,/not registered/);
      const closed=Number(db("SELECT e.CRN FROM Enrollment e JOIN Course_Section cs USING (CRN) WHERE e.student_ID=100014 AND cs.semester_ID='FA26' LIMIT 1"));
      assert.ok(closed);
      await expectResponse(await action(c,'drop-section',{CRN:closed}),422,/drop period/);
      await c.close();
    });

    await check('UC-25 S-F3–S-F12 server rejects holds, prerequisites, levels, cap, seats, passed, conflict, and closed Add',async()=>{
      const guards=[
        [100019,10579,/hold/],
        [100012,10934,/Missing prerequisite HIST115/],
        [100014,Number(db("SELECT cs.CRN FROM Course_Section cs JOIN Course c USING(course_ID) WHERE cs.semester_ID='SP27' AND c.course_Type='Graduate' LIMIT 1")),/Undergraduates cannot/],
        [Number(db("SELECT s.student_ID FROM Student s WHERE s.student_Type='Graduate' AND s.student_ID NOT IN (SELECT student_ID FROM Student_Hold) ORDER BY s.student_ID LIMIT 1")),10579,/Graduate students cannot/],
        [100009,10611,/already passed ENG110/],
        [100014,Number(db("SELECT CRN FROM Course_Section WHERE semester_ID='FA26' LIMIT 1")),/add period/],
      ];
      for(const [id,crn,reason] of guards) {
        assert.ok(id && crn);
        const c=await context(id);
        const before=await get(c,'register');
        const originalSeat=db(`SELECT available_Seats FROM Course_Section WHERE CRN=${crn}`);
        await expectResponse(await action(c,'add-section',{CRN:crn}),422,reason);
        assert.deepEqual(schedule(await get(c,'register')),schedule(before));
        assert.equal(db(`SELECT available_Seats FROM Course_Section WHERE CRN=${crn}`),originalSeat);
        await c.close();
      }
      const c=await context(100014);
      const holdTypes = db('SELECT hold_ID, hold_Type FROM Hold ORDER BY hold_ID').split(String.fromCharCode(10));
      assert.equal(holdTypes.length,4);
      for (const row of holdTypes) {
        const [holdId, holdType] = row.split(String.fromCharCode(9));
        assert.ok(Number.isSafeInteger(Number(holdId)));
        db("INSERT INTO Student_Hold (student_ID,hold_ID,hold_Date) VALUES (100014," + Number(holdId) + ",CURDATE())");
        await expectResponse(await action(c,'add-section',{CRN:10579}),422,new RegExp(holdType+' hold','i'));
        db("DELETE FROM Student_Hold WHERE student_ID=100014 AND hold_ID=" + Number(holdId));
      }
      const reserve=db('SELECT available_Seats FROM Course_Section WHERE CRN=10579;');
      db('UPDATE Course_Section SET available_Seats=0 WHERE CRN=10579;');
      await expectResponse(await action(c,'add-section',{CRN:10579}),422,/is full/);
      db(`UPDATE Course_Section SET available_Seats=${Number(reserve)} WHERE CRN=10579;`);
      await expectResponse(await action(c,'add-section',{CRN:10555}),200);
      await expectResponse(await action(c,'add-section',{CRN:10926}),422,/same time/);
      await expectResponse(await action(c,'add-section',{CRN:10566}),200);
      db("UPDATE Undergraduate_Student SET undergraduate_Student_Type='Part-time' WHERE student_ID=100014;");
      await expectResponse(await action(c,'add-section',{CRN:10597}),422,/limit for part-time students is 8/);
      assert.equal(credit(await get(c,'register')),'6 of 8');
      db("UPDATE Undergraduate_Student SET undergraduate_Student_Type='Full-time' WHERE student_ID=100014;");
      await c.close();
      // The live Spring Chromium test separately reaches 16 and verifies full-time rejection.
    });

    await check('UC-08/09/10/11/12 holds, grades, transcript, draft audit, advisors and empty states',async()=>{
      const held=await context(100019), rich=await context(100001), empty=await context(100014);
      assert.ok(table(await get(held,'holds-advisors'),'Active holds').rows.some(row=>row[0]==='Academic'));
      assert.equal(table(await get(empty,'holds-advisors'),'Active holds').rows.length,0);
      const advisors=table(await get(rich,'holds-advisors'),'My advisors').rows;
      assert.ok(advisors.length>=1 && advisors.length<=2 && advisors.every(row=>row[3]?.endsWith('@campus.edu')));
      const emptyGrades=await get(empty,'grades');
      assert.equal(emptyGrades.blocks[1].items[0].value,'No grades yet');
      const gradeView=await get(rich,'grades?semester=FA24');
      assert.ok(gradeView.blocks[2].rows.some(row=>/^[A-D][+-]?$/.test(row[3])));
      assert.ok(table(await get(rich,'transcript'),'Completed courses').rows.length);
      assert.equal(table(await get(empty,'transcript'),'Completed courses').rows.length,0);
      const a=audit(await get(rich,'degree-audit'));
      const distinctPassingCredits = Number(db(`SELECT COALESCE(SUM(credits), 0) FROM (
        SELECT DISTINCT c.course_ID, c.course_Credits AS credits
          FROM Student_History h JOIN Course c ON c.course_ID=h.course_ID
         WHERE h.student_ID=100001 AND h.grade IN ('A','A-','B+','B','B-','C+','C','C-','D+','D')
      ) AS earned`));
      assert.equal(distinctPassingCredits,59);
      assert.equal(a.credits.earned_provisional,distinctPassingCredits);
      assert.equal(a.gen_ed.length,7);
      const draftStatuses = {
        diversity: 'Completed', proficiency: 'Completed', major_electives: 'Not started', free_electives: 'Completed',
      };
      assert.equal(a.degree_requirements.draft, true);
      for (const [key, status] of Object.entries(draftStatuses)) {
        assert.equal(a[key].draft, true, key);
        assert.equal(a[key].status, status, key);
      }
      const page=await rich.newPage();
      for(const [view,title] of [['holds-advisors','Holds & advisors'], ['grades','Semester grades'],['transcript','Unofficial transcript'],['degree-audit','Degree Audit']]) await open(page,view,title);
      for(const c of [held,rich,empty]) await c.close();
    });

    await check('UC-13–21 catalog/program browsing and prerequisites by ID/title with both no-result cases',async()=>{
      const c=await context(100014);
      const catalog=await get(c,'catalog?dept=MATH');
      assert.equal(table(catalog,'University departments').rows.length,10);
      assert.ok(table(catalog,'Courses in this department').rows.some(row=>row[0]==='MATH110'));
      assert.equal((await get(c,'catalog?dept=INVALID')).blocks[1].filters[0].value,'BIO');
      const majors=await get(c,'programs?program=major:CS-BS');
      assert.equal(table(majors,'Majors offered').rows.length,16);
      assert.equal(table(majors,'Minors offered').rows.length,10);
      assert.ok(table(majors,'Course requirements:').rows.some(row=>row[0]==='CS110'));
      const minor=await get(c,'programs?program=minor:PSY-MIN');
      assert.ok(table(minor,'Course requirements:').rows.some(row=>row[0]==='PSY110'));
      const fallback=await get(c,'programs?program=INVALID');
      assert.equal(fallback.blocks[2].filters[0].value,'major:BIO-BS');
      for(const term of ['HIST220','United%20States%20History']) {
        const data=await get(c,'prereq-search?course='+term);
        assert.deepEqual(table(data,'Prerequisites').rows[0].filter((_,i)=>[0,2,4].includes(i)),['HIST220','HIST115','D']);
      }
      for(const [term,message] of [['NOT-A-COURSE',/No course matches/],['CS110',/no prerequisites/]]) {
        const data=await get(c,'prereq-search?course='+term);
        assert.match(data.blocks.find(b=>b.type==='message').message,message);
      }
      const page=await c.newPage();
      await open(page,'catalog','Course catalog');
      await open(page,'programs','Majors & minors');
      await open(page,'prereq-search','Prerequisite search');
      await page.getByLabel('Course ID or title').fill('HIST220');
      await page.getByRole('button',{name:'Search',exact:true}).click();
      await page.getByRole('cell',{name:'HIST115',exact:true}).waitFor();
      await c.close();
    });

    await check('UC-22 S-F14/15/16/17/18 address persistence, immutable ID/email, another student’s reads and writes',async()=>{
      const a=await context(100014), b=await context(100001);
      const before=await get(b,'account');
      const own=await get(a,'account');
      assert.deepEqual(await get(a,'account?student_ID=100001&user_ID=100001'), own);
      assert.equal(audit(await get(a,'degree-audit?student_ID=100001')).profile.id,100014);
      assert.notDeepEqual(await get(a,'transcript?student_ID=100001'),await get(b,'transcript'));
      const page=await a.newPage();
      await open(page,'account','My account');
      await expectResponse(await action(a,'update-address',{state:'???'}),422,/state/i);
      await expectResponse(await action(a,'update-address',{street:'22 Review Place',state:'NY',city:'Hempstead',zip_Code:'11550',user_ID:100001,user_Email:'changed@example.invalid'}),200,/address was updated/i);
      await page.reload();
      await page.getByText('22 Review Place, Hempstead, NY 11550',{exact:true}).waitFor();
      assert.deepEqual(await get(b,'account'),before);
      assert.deepEqual((await get(a,'account')).blocks[0].items.slice(1,3),own.blocks[0].items.slice(1,3));
      await expectResponse(await action(b,'drop-section',{CRN:10555,student_ID:100014}),422,/not registered/);
      assert.ok(schedule(await get(a,'register')).some(row=>row[0]===10555));
      for(const route of ['record-grade','record-attendance']) await expectResponse(await action(a,route,{student_ID:100001}),403);
      await a.close(); await b.close();
    });

    await check('UC-28–41 major/minor add, change, drop, cap, invalid and undeclared; persists after reload',async()=>{
      const c=await context(100014);
      const page=await c.newPage();
      await open(page,'my-programs','My majors & minors');
      const programs=()=>get(c,'my-programs');
      const majors=async()=>table(await programs(),'My majors').rows.map(row=>row[0]);
      const minors=async()=>table(await programs(),'My minor').rows.map(row=>row[0]);
      assert.deepEqual(await majors(),['MATH-BS']);
      await expectResponse(await action(c,'add-major',{major_ID:'UNKNOWN'}),422,/does not exist/);
      await expectResponse(await action(c,'add-major',{major_ID:'CS-MS'}),422,/graduate program/);
      const addForm=page.locator('form').filter({has:page.getByRole('heading',{name:'Add a major',exact:true})});
      await addForm.locator('select[name=major_ID]').selectOption('CS-BS');
      const reply=page.waitForResponse(r=>r.url().endsWith('/api/actions/add-major'));
      await addForm.getByRole('button',{name:'Add major',exact:true}).click();
      await expectResponse(await reply,200,/second major/);
      await page.reload();
      await page.getByRole('cell',{name:'CS-BS',exact:true}).waitFor();
      assert.deepEqual(await majors(),['MATH-BS','CS-BS']);
      assert.equal(table(await get(c,'holds-advisors'),'My advisors').rows.length,2);
      await expectResponse(await action(c,'add-major',{major_ID:'ECON-BS'}),422,/most allowed/);
      await expectResponse(await action(c,'change-major',{from_ID:'BIO-BS',major_ID:'ECON-BS'}),422,/only change/);
      await expectResponse(await action(c,'drop-major',{major_ID:'BIO-BS'}),422,/only drop/);
      const before=audit(await get(c,'degree-audit'));
      await expectResponse(await action(c,'change-major',{from_ID:'MATH-BS',major_ID:'ECON-BS'}),200,/was changed/);
      await page.reload();
      await page.getByRole('cell',{name:'ECON-BS',exact:true}).waitFor();
      const after=audit(await get(c,'degree-audit'));
      assert.equal(after.programs.find(p=>p.is_primary).id,'ECON-BS');
      assert.deepEqual(after.credits,before.credits);
      await expectResponse(await action(c,'drop-major',{major_ID:'CS-BS'}),200,/was dropped/);
      assert.deepEqual(await majors(),['ECON-BS']);
      await expectResponse(await action(c,'drop-major',{major_ID:'ECON-BS'}),422,/at least one major/);
      await expectResponse(await action(c,'add-minor',{minor_ID:'UNKNOWN'}),422,/does not exist/);
      await expectResponse(await action(c,'add-minor',{minor_ID:'ECON-MIN'}),422,/department of your major/);
      await expectResponse(await action(c,'add-minor',{minor_ID:'PSY-MIN'}),200,/added/);
      assert.deepEqual(await minors(),['PSY-MIN']);
      await expectResponse(await action(c,'change-minor',{from_ID:'ENG-MIN',minor_ID:'BIO-MIN'}),422,/only change/);
      await expectResponse(await action(c,'change-minor',{from_ID:'PSY-MIN',minor_ID:'BIO-MIN'}),200,/was changed/);
      await page.reload();
      await page.getByRole('cell',{name:'BIO-MIN',exact:true}).waitFor();
      assert.deepEqual(await minors(),['BIO-MIN']);
      await expectResponse(await action(c,'drop-minor',{minor_ID:'PSY-MIN'}),422,/only drop/);
      await expectResponse(await action(c,'drop-minor',{minor_ID:'BIO-MIN'}),200,/was dropped/);
      await page.reload();
      await page.getByText('You have not declared a minor.',{exact:true}).waitFor();
      assert.deepEqual(await minors(),[]);
      await c.close();
    });

    await check('UC-03/04/23 standard recovery fails closed; local owner-only verification and password update',async()=>{
      const c=await context(100081);
      await expectResponse(await c.request.post(base+'/api/reset/request',{data:{login:'100081'}}),422,/delivery is unavailable/);
      await expectResponse(await action(c,'request-password-change',{current:password}),422,/delivery is unavailable/);
      const owner=await context(100081,recovery);
      const visitor=await context(null,recovery);
      await expectResponse(await action(owner,'request-password-change',{current:'WrongAccept123!'},recovery),422);
      await expectResponse(await action(owner,'request-password-change',{current:password},recovery),200);
      const content=(await get(owner,'inbox',200,recovery)).blocks.find(b=>b.type==='mail').messages[0].body;
      const code=content.match(/code is: ([0-9]{6})/)[1];
      const wrong=code==='111111'?'222222':'111111';
      await expectResponse(await visitor.request.post(recovery+'/api/demo-mail',{data:{user_ID:100081}}),422);
      await expectResponse(await visitor.request.post(recovery+'/api/reset/verify',{data:{login:'100081',code:wrong}}),422,/invalid/);
      await expectResponse(await visitor.request.post(recovery+'/api/reset/complete',
        {data:{password:'NewLocalAcceptance123!',confirm:'NewLocalAcceptance123!'}}),422,/invalid/);
      await expectResponse(await action(owner,'verify-password-code',{code},recovery),200);
      await expectResponse(await action(owner,'complete-password-change',
        {password:'NewLocalAcceptance123!',confirm:'NewLocalAcceptance123!'},recovery),200,/Sign in again/);
      await get(c,'account',401);
      await login(visitor,100081,recovery,password,401);
      await login(visitor,100081,recovery,'NewLocalAcceptance123!');
      await c.close(); await owner.close(); await visitor.close();
    });

    await check('UC-03 conditional fixture: locked account remains unable to read mail; fixture-delivered reset link works once',async()=>{
      const c=await context(null,recovery);
      await expectResponse(await c.request.post(recovery+'/api/reset/request',{data:{login:'100080'}}),200);
      await expectResponse(await c.request.post(recovery+'/api/demo-mail',{data:{user_ID:100080}}),422);
      const mail=db("SELECT body FROM Email_Message WHERE user_ID=100080 ORDER BY message_ID DESC LIMIT 1");
      const token=mail.match(/token=([a-f0-9]{64})/)[1];
      const page=await c.newPage();
      await page.goto(recovery+'/#reset?token='+token);
      await page.locator('#newpass-form [name=password]').fill('LocalVerifiedReset123!');
      await page.locator('#newpass-form [name=confirm]').fill('LocalVerifiedReset123!');
      const complete=page.waitForResponse(r=>r.url().endsWith('/api/reset/complete'));
      await page.locator('#newpass-form button').click();
      await expectResponse(await complete,200,/Sign in again/);
      await page.locator('#login-form [name=email]').waitFor();
      await expectResponse(await c.request.post(recovery+'/api/reset/verify',{data:{token}}),422,/invalid/);
      await login(c,100080,recovery,'LocalVerifiedReset123!');
      assert.equal(db('SELECT CONCAT(no_Of_Tries,":",lock_Var) FROM Login WHERE user_ID=100080'),'0:0');
      await c.close();
    });
  } finally {await browser.close();}
  if(errors.length) process.exitCode=1;
}
run().catch(e=>{console.error(e);process.exitCode=1;});
