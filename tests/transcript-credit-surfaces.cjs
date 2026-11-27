// Read-only HTTP consistency check. Does not create or change student records.
const assert=require('node:assert/strict');
const {request}=require('playwright');
const base=process.env.TRANSCRIPT_BASE||'http://127.0.0.1:3043';
(async()=>{
 for(const [id,earned,standing] of [[100001,59,'Junior'],[100018,50,'Sophomore']]){
  const c=await request.newContext({baseURL:base});
  try{
   const login=await c.post('/api/login',{data:{email:String(id),role:'Student',password:process.env.DEMO_PASSWORD||'Campus123!'}});assert.equal(login.status(),200);
   const view=async name=>{const r=await c.get('/api/views/'+name);assert.equal(r.status(),200);return r.json();};
   for(let refresh=0;refresh<2;refresh++){
    const transcript=await view('transcript'),audit=(await view('degree-audit')).blocks.find(b=>b.type==='degree-audit').audit;
    const overview=(await view('overview')).blocks.find(b=>b.type==='student-overview');
    assert.equal(Number(transcript.blocks.find(b=>b.type==='stats').items.find(i=>i.label==='Credits earned').value),earned);
    assert.equal(audit.credits.earned_provisional,earned);assert.equal(audit.profile.year,standing);assert.equal(overview.provisional_credits,earned);
   }
   console.log('PASS '+id+': Transcript, Degree Audit, Overview agree at '+earned+' / '+standing+' after repeated reads.');
  }finally{await c.dispose();}
 }
})().catch(e=>{console.error(e);process.exitCode=1;});
