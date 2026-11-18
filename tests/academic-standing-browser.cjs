const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const socket = process.argv[2];
assert.ok(socket, 'Supply the dedicated senior database socket.');
assert.match(socket,/\/campus-standing-review-db\.[A-Za-z0-9]+\/mysql\.sock$/);
const sql = query => execFileSync('mysql',['--no-defaults','--protocol=socket','--socket='+socket,'-uroot','-NBr','campus_connect'],
  {input:query,encoding:'utf8'}).trim();
const rows = query => sql(query).split('\n').filter(Boolean).map(r=>r.split('\t'));
assert.equal(sql('SELECT @@skip_networking AND @@server_id=732157'),'1');
const base='http://127.0.0.1:3013';
const {createHash}=require('node:crypto');
const referenceRecords = () => createHash('sha256').update(sql(
  'SELECT * FROM Student ORDER BY student_ID;'+
  'SELECT * FROM Student_History ORDER BY student_ID,CRN;'+
  'SELECT * FROM Enrollment ORDER BY student_ID,CRN;'+
  'SELECT * FROM Course_Section ORDER BY CRN;'
)).digest('hex');
const referenceBefore=referenceRecords();
const grades=['F','D','D+','C-','C','C+','B-','B','B+','A-','A'];
const taken=rows('SELECT h.course_ID,h.CRN,h.semester_ID,sem.start_Date,c.course_Credits,h.grade FROM Student_History h '+
  'JOIN Semester sem USING(semester_ID) JOIN Enrollment e ON e.student_ID=h.student_ID AND e.CRN=h.CRN AND e.grade=h.grade AND e.course_ID=h.course_ID '+
  'JOIN Course_Section cs ON cs.CRN=h.CRN AND cs.semester_ID=h.semester_ID AND cs.course_ID=h.course_ID '+
  'JOIN Course c ON c.course_ID=h.course_ID WHERE h.student_ID=199088 ORDER BY sem.start_Date,h.course_ID')
  .map(([code,crn,term,date,credits,grade])=>({code,crn,term,date,credits:Number(credits),grade}));
assert.equal(taken.length,30);
assert.equal(taken.length,Number(sql('SELECT COUNT(*) FROM Student_History WHERE student_ID=199088')));
assert.equal(new Set(taken.map(c=>c.code)).size,30);
const prereqs=rows('SELECT course_ID,prerequisite_Course_ID,min_Grade_Req FROM Course_Prerequisite');
const totals={};
let credits=0;
for(const term of [...new Set(taken.map(c=>c.term))]){
  const courses=taken.filter(c=>c.term===term);
  const level=credits>=88?4:credits>=57?3:credits>=32?2:1;
  for(const course of courses){
    assert.ok(grades.indexOf(course.grade)>0);
    assert.ok(Number(course.code.match(/\d/)[0])<=level,course.code);
    for(const [,needed,minimum] of prereqs.filter(p=>p[0]===course.code)){
      assert.ok(taken.some(c=>c.code===needed && c.date<course.date && grades.indexOf(c.grade)>=grades.indexOf(minimum)),course.code);
    }
  }
  totals[term]=courses.reduce((sum,c)=>sum+c.credits,0);
  assert.ok(totals[term]<=16);
  credits+=totals[term];
}
assert.equal(credits,92);
assert.deepEqual(Object.values(totals),[16,16,15,15,15,15]);
const current=['10442','10901','10902','10903'];
const allCrns=[...taken.map(c=>c.crn),...current];
const meetings=rows('SELECT m.CRN,m.semester_ID,m.faculty_ID,m.room_ID,d.day_ID,p.start_Time,p.end_Time '+
  'FROM (SELECT CRN,semester_ID,faculty_ID,room_ID,time_Slot_ID FROM Course_Section UNION ALL '+
  'SELECT cs.CRN,cs.semester_ID,cs.faculty_ID,l.lab_ID,l.time_Slot_ID FROM Course_Section_Lab l JOIN Course_Section cs USING(CRN)) m '+
  'JOIN Time_Slot_Day d USING(time_Slot_ID) JOIN Time_Slot_Period tp USING(time_Slot_ID) JOIN Period p USING(period_ID)')
  .map(([crn,term,faculty,room,day,start,end])=>({crn,term,faculty,room,day,start,end}));
const clash=(a,b)=>a.term===b.term && a.day===b.day && a.start<b.end && b.start<a.end;
for(const m of meetings.filter(m=>allCrns.includes(m.crn))){
  for(const n of meetings.filter(n=>n.crn!==m.crn && clash(m,n))){
    assert.ok(!allCrns.includes(n.crn),'Student overlap '+m.crn+'/'+n.crn);
    if(m.room===n.room || m.faculty===n.faculty){
      assert.ok(!current.includes(m.crn) && Number(m.crn)<19000,'Room or faculty overlap '+m.crn+'/'+n.crn);
      console.log('Pre-existing historical resource conflict',m.crn,n.crn);
    }
  }
}
assert.equal(sql('SELECT COUNT(*) FROM Course_Section cs LEFT JOIN Lecture r ON r.lecture_ID=cs.room_ID '+
  'LEFT JOIN Lab l ON l.lab_ID=cs.room_ID WHERE cs.CRN IN ('+allCrns+') AND (cs.available_Seats<0 OR cs.available_Seats<>cs.max_Seats- '+
  '(SELECT COUNT(*) FROM Enrollment e WHERE e.CRN=cs.CRN) OR cs.max_Seats>COALESCE(r.seats,l.work_Stations,0))'),'0');
assert.equal(sql('SELECT COUNT(*) FROM Course_Section_Lab link JOIN Lab lab ON lab.lab_ID=link.lab_ID JOIN Course_Section cs USING(CRN) '+
  'WHERE cs.CRN IN ('+allCrns+') AND cs.max_Seats>lab.work_Stations'),'0');
assert.equal(sql('SELECT student_Year FROM Student WHERE student_ID=199088'),'Freshman');
assert.equal(sql('SELECT student_Year FROM Student WHERE student_ID=100001'),'Senior');

async function data(res,status=200){
  assert.equal(res.status(),status,await res.text());
  return res.json();
}
async function run(){
  const browser=await chromium.launch({executablePath:process.env.CHROME_PATH});
  try{
    const login=async(id,role)=>{
      const c=await browser.newContext();
      await c.route('**/*',r=>r.request().url().startsWith(base+'/')?r.continue():r.abort());
      await data(await c.request.post(base+'/api/login',{data:{email:String(id),role,password:process.env.DEMO_PASSWORD}}));
      return c;
    };
    const view=async(c,path)=>data(await c.request.get(base+'/api/views/'+path));
    const admin=await login(300001,'Admin');
    for(const [id,year,total] of [[199088,'Senior',92],[100001,'Junior',59],[100018,'Sophomore',50]]){
      const student=await login(id,'Student');
      const audit=(await view(student,'degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
      assert.equal(audit.profile.year,year);
      assert.equal(audit.credits.earned_provisional,total);
      assert.match((await view(student,'overview')).subtitle,new RegExp(year));
      const acc=(await view(admin,'account?student='+id)).blocks.find(b=>b.title==='Student Account Information');
      const fields=Object.fromEntries(acc.rows);
      assert.equal(fields['Student year'],year);
      assert.equal(fields['Earned credits (provisional)'],total);
      const p=await student.newPage();
      await p.goto(base+'/#degree-audit');
      await p.locator('.audit-profile-grid div').filter({hasText:'Academic year'}).getByText(year,{exact:true}).waitFor();
      await p.locator('.audit-overall-credit .stat').filter({hasText:'Earned Credits (Provisional)'}).getByText(String(total),{exact:true}).waitFor();
      await p.reload();
      await p.locator('.audit-profile-grid div').filter({hasText:'Academic year'}).getByText(year,{exact:true}).waitFor();
      const a=await admin.newPage();
      await a.goto(base+'/#account?student='+id);
      await a.getByRole('row').filter({hasText:'Student year'}).getByText(year,{exact:true}).waitFor();
      await a.getByRole('row').filter({hasText:'Earned credits (provisional)'}).getByText(String(total),{exact:true}).waitFor();
      const register=await view(admin,'admin-register?semester=FA26&student_ID='+id);
      const stats=register.blocks.find(b=>b.type==='stats').items;
      assert.equal(stats.find(x=>x.label==='Year').value,year);
      assert.equal(stats.find(x=>x.label==='Earned credits (provisional)').value,total);
      await a.goto(base+'/#admin-register?semester=FA26&student_ID='+id);
      await a.locator('.stat').filter({has:a.locator('.stat-label').getByText('Year',{exact:true})}).getByText(year,{exact:true}).waitFor();
      await a.reload();
      await a.locator('.stat').filter({has:a.locator('.stat-label').getByText('Year',{exact:true})}).getByText(year,{exact:true}).waitFor();
      if(id===100001){
        const r=await data(await student.request.post(base+'/api/actions/add-section',{data:{CRN:10548}}),422);
        assert.match(r.error,/at least a Senior.*Junior/);
        const staff=await data(await admin.request.post(base+'/api/actions/register-student',{data:{student_ID:id,CRN:10548}}),422);
        assert.equal(staff.error,r.error);
      }
      if(id===199088){
        assert.equal(audit.credits.in_progress,12);
        assert.equal(stats.find(x=>x.label==='Fall 2026 enrolled credits').value,12);
        const earnedStat=a.locator('.stat').filter({hasText:'Earned credits (provisional)'});
        await earnedStat.getByText('92',{exact:true}).waitFor();
        await a.locator('.stat').filter({hasText:'Fall 2026 enrolled credits'}).getByText('12',{exact:true}).waitFor();
        assert.equal(audit.credits.future_registered,0);
        assert.equal(audit.degree_requirements.credits_required,120);
        assert.equal(audit.degree_requirements.credits_applied,92);
        assert.equal(audit.degree_requirements.draft,true);
        const degree = p.locator('section.audit-section').filter({
          has:p.getByRole('heading',{name:'Degree Requirements',exact:true}),
        });
        await degree.locator('details').evaluate(el => { el.open = true; });
        assert.equal(audit.degree_requirements.draft,true, 'The existing credit target stays draft.');
        const major=audit.programs.find(x=>x.is_primary);
        assert.equal(major.id,'CS-BS');
        assert.deepEqual(major.requirements.filter(r=>r.status==='Completed').map(r=>r.code),
          ['CS110','CS115','CS220','CS225','CS330','CS335','CS340','MATH360']);
        assert.deepEqual(major.requirements.filter(r=>r.status==='In progress').map(r=>r.code),['CS455','CS460','CS470','CS480']);
        assert.deepEqual(major.requirements.filter(r=>r.status==='Not completed').map(r=>r.code),['CS445','CS450']);
        for (const [code, status] of [['CS480','In progress'],['CS445','Not completed'],['CS450','Not completed']]) {
          const section = p.locator('section.audit-section').filter({has:p.getByRole('heading',{name:'Major Requirements',exact:true})});
          const row = section.getByRole('row').filter({has:p.getByRole('cell',{name:code,exact:true})});
          await row.getByRole('cell',{name:status,exact:true}).waitFor();
        }
        const transcript=await view(student,'transcript');
        const history=transcript.blocks.find(b=>b.title==='Completed courses');
        assert.equal(history.rows.length,30);
        assert.equal(history.rows.reduce((sum,row)=>sum+Number(row[history.columns.indexOf('Credits')]),0),92);

        const spring=await view(student,'register?semester=SP27');
        assert.ok(spring.blocks.find(b=>b.title==='Sections you can add').rows.some(r=>r[0]===10548));
        console.log(JSON.stringify({totals,credits,major:major.requirements.map(r=>[r.code,r.status]),current:audit.in_progress.map(x=>[x.code,x.crn]),
          draftBlocks:Object.fromEntries(['degree_requirements','liberal_arts','major_electives','free_electives','diversity','proficiency'].map(k=>[k,audit[k].status])),
          genEd:audit.gen_ed.map(r=>[r.name,r.status])}));
      }
      await a.close();
      await student.close();
    }
    const teacher=await login(200003,'Faculty');
    const teaching=await view(teacher,'teaching?semester=FA26');
    const roster=teaching.blocks.find(b=>b.title==='Roster: CS480 (CRN 10902)');
    const senior=roster.rows.find(r=>r[roster.columns.indexOf('Student ID')]===199088);
    const reference=roster.rows.find(r=>r[roster.columns.indexOf('Student ID')]===100001);
    assert.equal(senior[roster.columns.indexOf('Year')],'Senior');
    assert.equal(reference[roster.columns.indexOf('Year')],'Junior');
    const advisees=await view(teacher,'advisees');
    for(const t of advisees.blocks.filter(b=>b.columns?.includes('Year'))){
      for(const row of t.rows){
        const id=row[t.columns.indexOf('Student ID')];
        const student=await login(id,'Student');
        const own=(await view(student,'degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
        assert.equal(row[t.columns.indexOf('Year')],own.profile.year);
        await student.close();
      }
    }
    const page=await teacher.newPage();
    await page.goto(base+'/#teaching?semester=FA26');
    const rosterCard=page.locator('.table-card').filter({has:page.getByRole('heading',{name:'Roster: CS480 (CRN 10902)',exact:true})});
    await rosterCard.getByRole('row').filter({has:page.getByRole('cell',{name:'199088',exact:true})}).getByText('Senior',{exact:true}).waitFor();
    await rosterCard.getByRole('row').filter({has:page.getByRole('cell',{name:'100001',exact:true})}).getByText('Junior',{exact:true}).waitFor();
    await page.reload();
    await rosterCard.getByRole('row').filter({has:page.getByRole('cell',{name:'100001',exact:true})}).getByText('Junior',{exact:true}).waitFor();
    await teacher.close();
    const graduateId=Number(sql("SELECT MIN(student_ID) FROM Student WHERE student_Type='Graduate'"));
    const grad=await login(graduateId,'Student');
    const ga=(await view(grad,'degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
    const gradYear=sql('SELECT student_Year FROM Student WHERE student_ID='+graduateId);
    assert.equal(ga.profile.year,gradYear);
    const gradRegister=(await view(admin,'admin-register?semester=FA26&student_ID='+graduateId)).blocks.find(b=>b.type==='stats').items;
    assert.equal(gradRegister.find(x=>x.label==='Year').value,gradYear);
    const graduate=(await view(admin,'account?student='+graduateId)).blocks.find(b=>b.title==='Student Account Information');
    assert.equal(Object.fromEntries(graduate.rows)['Student year'],gradYear);
    assert.equal(referenceRecords(),referenceBefore);
    console.log('PASS Chromium/API: Student and Admin standing, graduate preservation, and matching Student/Admin denial for Junior attempting a senior-level section.');
  }finally{await browser.close();}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
