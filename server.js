#!/usr/bin/env node
'use strict';
/*
  Project Tracker v4 - server kecil untuk jaringan kantor.
  Tanpa dependensi (hanya modul bawaan Node.js 18 atau lebih baru).
  Data: data/tracker.json (ditulis atomik), data/users.json, data/files/, backup harian.
*/
const http=require('http'),fs=require('fs'),path=require('path'),os=require('os'),crypto=require('crypto'),vm=require('vm'),zlib=require('zlib');
const APP_DIR=__dirname,VERSION='4.0.0';
const strip=s=>String(s).replace(/^\uFEFF/,'');

/* ---------- konfigurasi ---------- */
const CFG_FILE=process.env.PT_CONFIG||path.join(APP_DIR,'config.json');
const DEFCFG={port:3001,host:'0.0.0.0',dataDir:'data',backupDir:'',extraBackupDir:'',backupKeepDays:60,maxUploadMB:100,sessionDays:30};
function loadConfig(){
  let c={};
  try{c=JSON.parse(strip(fs.readFileSync(CFG_FILE,'utf8')));}
  catch(e){
    if(e.code==='ENOENT'){try{fs.writeFileSync(CFG_FILE,JSON.stringify(DEFCFG,null,2));}catch(_){}}
    else{console.error('config.json tidak valid: '+e.message);process.exit(1);}
  }
  const cfg=Object.assign({},DEFCFG,c);
  if(process.env.PT_PORT)cfg.port=Number(process.env.PT_PORT);
  if(process.env.PT_DATA)cfg.dataDir=process.env.PT_DATA;
  return cfg;
}
const CFG=loadConfig();
const rp=p=>path.isAbsolute(p)?p:path.join(APP_DIR,p);
const DATA=rp(CFG.dataDir),FILES=path.join(DATA,'files');
const BACKUP=CFG.backupDir?rp(CFG.backupDir):path.join(DATA,'backup');
const EXTRA=CFG.extraBackupDir?rp(CFG.extraBackupDir):'';
fs.mkdirSync(FILES,{recursive:true});

/* ---------- log ---------- */
const LOGF=path.join(DATA,'server.log');
try{if(fs.statSync(LOGF).size>5*1024*1024)fs.renameSync(LOGF,LOGF+'.old');}catch(e){}
const ts=()=>{const d=new Date(),p=n=>String(n).padStart(2,'0');return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());};
function log(){const line='['+ts()+'] '+Array.from(arguments).join(' ');console.log(line);try{fs.appendFileSync(LOGF,line+'\n');}catch(e){}}
const ymd=d=>d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');

/* ---------- kode bersama dengan klien ---------- */
const S=vm.runInNewContext(fs.readFileSync(path.join(APP_DIR,'public','shared.js'),'utf8')+'\n;({normDB,mergeDB,sig})',{});

/* ---------- tulis file dengan aman ---------- */
function sleep(ms){try{Atomics.wait(new Int32Array(new SharedArrayBuffer(4)),0,0,ms);}catch(e){}}
function renameRetry(a,b){
  for(let i=0;;i++){
    try{fs.renameSync(a,b);return;}
    catch(e){if(i>=8||!/EPERM|EBUSY|EACCES/.test(e.code||''))throw e;sleep(60);}
  }
}
function writeAtomic(file,data,keepPrev){
  const tmp=file+'.tmp';
  const fd=fs.openSync(tmp,'w');
  try{fs.writeSync(fd,data);fs.fsyncSync(fd);}finally{fs.closeSync(fd);}
  if(keepPrev&&fs.existsSync(file)){try{fs.copyFileSync(file,file.replace(/\.json$/,'.prev.json'));}catch(e){}}
  renameRetry(tmp,file);
}
const readJSON=f=>JSON.parse(strip(fs.readFileSync(f,'utf8')));

/* ---------- data project ---------- */
const TRACKER=path.join(DATA,'tracker.json');
let STATE={rev:0,db:null};
function saveState(){writeAtomic(TRACKER,JSON.stringify({rev:STATE.rev,savedAt:Date.now(),db:STATE.db}),true);}
function loadState(){
  const prev=TRACKER.replace(/\.json$/,'.prev.json');
  const exists=fs.existsSync(TRACKER);
  const tryFile=f=>{try{const o=readJSON(f);if(o&&o.db){return{rev:Number(o.rev)||0,db:S.normDB(o.db)};}}catch(e){if(e.code!=='ENOENT')log('Gagal membaca',path.basename(f)+':',e.message);}return null;};
  let st=tryFile(TRACKER);
  if(!st&&exists){
    try{fs.copyFileSync(TRACKER,path.join(DATA,'tracker.corrupt-'+Date.now()+'.json'));}catch(e){}
    log('PERINGATAN: tracker.json rusak. Mencoba pulih dari salinan sebelumnya / backup.');
    st=tryFile(prev);
    if(st)log('Dipulihkan dari tracker.prev.json');
    else{
      let names=[];try{names=fs.readdirSync(BACKUP).filter(n=>/^tracker-\d{4}-\d{2}-\d{2}\.json$/.test(n)).sort().reverse();}catch(e){}
      for(const n of names){st=tryFile(path.join(BACKUP,n));if(st){log('Dipulihkan dari backup',n);break;}}
    }
    if(!st){log('GAGAL pulih otomatis. Hentikan server dan hubungi admin. File rusak disalin ke tracker.corrupt-*.json');process.exit(2);}
  }
  if(!st){st={rev:0,db:S.normDB({})};STATE=st;saveState();log('Database baru dibuat.');}
  STATE=st;
}

/* ---------- backup ---------- */
let lastBackupRev=-1,lastBackupAt=0;
function pruneDir(dir){
  const lim=Date.now()-CFG.backupKeepDays*864e5;
  try{fs.readdirSync(dir).forEach(n=>{const m=/^(?:tracker|users)-(\d{4})-(\d{2})-(\d{2})\.json$/.exec(n);if(m&&new Date(+m[1],+m[2]-1,+m[3]).getTime()<lim){try{fs.unlinkSync(path.join(dir,n));}catch(e){}}});}catch(e){}
}
function runBackup(force){
  if(!force&&(lastBackupRev===STATE.rev||Date.now()-lastBackupAt<30*60*1000))return [];
  const day=ymd(new Date()),done=[];
  [BACKUP,EXTRA].filter(Boolean).forEach(dir=>{
    try{
      fs.mkdirSync(dir,{recursive:true});
      const f1=path.join(dir,'tracker-'+day+'.json');
      writeAtomic(f1,JSON.stringify({rev:STATE.rev,savedAt:Date.now(),db:STATE.db}),false);done.push(f1);
      const f2=path.join(dir,'users-'+day+'.json');
      writeAtomic(f2,JSON.stringify({users:USERS.users}),false);done.push(f2);
      pruneDir(dir);
    }catch(e){log('Backup gagal ke',dir+':',e.message);}
  });
  lastBackupRev=STATE.rev;lastBackupAt=Date.now();
  return done.map(f=>path.basename(path.dirname(f))+'/'+path.basename(f));
}

/* ---------- pengguna & sesi ---------- */
const USERS_F=path.join(DATA,'users.json'),SESS_F=path.join(DATA,'sessions.json');
let USERS={users:[]},SESS={};
function loadUsers(){
  try{USERS=readJSON(USERS_F);if(!Array.isArray(USERS.users))USERS={users:[]};}catch(e){if(e.code!=='ENOENT')log('users.json bermasalah:',e.message);USERS={users:[]};}
  try{SESS=readJSON(SESS_F);}catch(e){SESS={};}
  const now=Date.now();for(const k in SESS)if(!SESS[k]||SESS[k].exp<now)delete SESS[k];
}
const saveUsers=()=>writeAtomic(USERS_F,JSON.stringify(USERS,null,1),true);
const saveSess=()=>{try{writeAtomic(SESS_F,JSON.stringify(SESS),false);}catch(e){log('sessions.json gagal ditulis:',e.message);}};
const hashPin=(pin,salt)=>crypto.scryptSync(String(pin),Buffer.from(salt,'hex'),32).toString('hex');
function mkUser(name,pin,role){const salt=crypto.randomBytes(16).toString('hex');return{name:name,role:['admin','user','viewer','contributor'].includes(role)?role:'user',salt:salt,hash:hashPin(pin,salt),createdAt:Date.now()};}
function checkPin(u,pin){
  const a=Buffer.from(hashPin(pin,u.salt),'hex'),b=Buffer.from(u.hash,'hex');
  return a.length===b.length&&crypto.timingSafeEqual(a,b);
}
const findUser=n=>USERS.users.find(u=>u.name.toLowerCase()===String(n||'').trim().toLowerCase());
const pubUser=u=>({name:u.name,role:u.role});
function validName(n){n=String(n||'').trim();return n.length>=2&&n.length<=40&&!/[\u0000-\u001f<>"'\\\/]/.test(n);}
function validPin(p){p=String(p||'');return p.length>=4&&p.length<=64;}
const sha=t=>crypto.createHash('sha256').update(t).digest('hex');
function newSession(name){const tok=crypto.randomBytes(32).toString('hex');SESS[sha(tok)]={name:name,exp:Date.now()+CFG.sessionDays*864e5};saveSess();return tok;}
function parseCookies(req){const o={};String(req.headers.cookie||'').split(';').forEach(p=>{const i=p.indexOf('=');if(i>0)o[p.slice(0,i).trim()]=p.slice(i+1).trim();});return o;}
function authUser(req){
  const tok=parseCookies(req).pt_session;if(!tok)return null;
  const s=SESS[sha(tok)];if(!s||s.exp<Date.now())return null;
  const u=USERS.users.find(x=>x.name===s.name);return u||null;
}
const cookieHdr=(tok,maxAge)=>'pt_session='+tok+'; Path=/; HttpOnly; SameSite=Lax; Max-Age='+maxAge;
const FAILS={};
function lockInfo(key){const f=FAILS[key];return f&&f.until>Date.now()?Math.ceil((f.until-Date.now())/1000):0;}
function noteFail(key){const f=FAILS[key]||(FAILS[key]={n:0,until:0});f.n++;if(f.n>=5){f.until=Date.now()+5*60*1000;f.n=0;}}

/* ---------- HTTP util ---------- */
const MIME={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.json':'application/json'};
const INLINE={'.pdf':'application/pdf','.png':'image/png','.jpg':'image/jpeg','.jpeg':'image/jpeg','.gif':'image/gif','.webp':'image/webp','.mp4':'video/mp4','.webm':'video/webm','.mov':'video/quicktime','.m4v':'video/x-m4v','.txt':'text/plain; charset=utf-8'};
function baseHeaders(extra){return Object.assign({'X-Content-Type-Options':'nosniff','X-Frame-Options':'DENY','Referrer-Policy':'same-origin','Cache-Control':'no-store'},extra||{});}
function sendJSON(req,res,code,obj,extra){
  let body=Buffer.from(JSON.stringify(obj));
  const h=baseHeaders(Object.assign({'Content-Type':'application/json; charset=utf-8'},extra||{}));
  if(body.length>1024&&/\bgzip\b/.test(req.headers['accept-encoding']||'')){body=zlib.gzipSync(body);h['Content-Encoding']='gzip';}
  h['Content-Length']=body.length;res.writeHead(code,h);res.end(body);
}
const fail=(req,res,code,msg,extra)=>sendJSON(req,res,code,{error:msg},extra);
function readBody(req,limit){
  return new Promise((resolve,reject)=>{
    const ch=[];let n=0;
    req.on('data',c=>{n+=c.length;if(n>limit){reject(Object.assign(new Error('Data terlalu besar'),{status:413}));req.destroy();return;}ch.push(c);});
    req.on('end',()=>resolve(Buffer.concat(ch)));req.on('error',reject);
  });
}
async function readJSONBody(req,limit){
  const b=await readBody(req,limit||40*1024*1024);
  if(!b.length)return{};
  try{return JSON.parse(b.toString('utf8'));}catch(e){throw Object.assign(new Error('Format data tidak valid'),{status:400});}
}
const isLocal=req=>['127.0.0.1','::1','::ffff:127.0.0.1'].includes(req.socket.remoteAddress);

/* ---------- real-time (Server-Sent Events) ---------- */
const CLIENTS=new Set();
const online=()=>Array.from(new Set(Array.from(CLIENTS).map(c=>c.user))).sort();
function broadcast(by){
  const msg='event: state\ndata: '+JSON.stringify({rev:STATE.rev,by:by||'',online:online()})+'\n\n';
  CLIENTS.forEach(c=>{try{c.res.write(msg);}catch(e){CLIENTS.delete(c);}});
}
setInterval(()=>{CLIENTS.forEach(c=>{try{c.res.write(': ping\n\n');}catch(e){CLIENTS.delete(c);}});},20000).unref();

/* ---------- sinkronisasi ---------- */
const sigAll=db=>S.sig(db)+'|'+(db.settings.settingsAt||0);
function cleanProjects(arr){
  if(!Array.isArray(arr))return[];
  return arr.filter(p=>p&&typeof p==='object'&&typeof p.id==='string'&&p.id.length>0&&p.id.length<=100).slice(0,5000);
}
function cleanImprovements(arr){
  if(!Array.isArray(arr))return[];
  return arr.filter(x=>x&&typeof x==='object'&&typeof x.id==='string'&&x.id.length>0&&x.id.length<=100).slice(0,5000);
}
function doSync(user,body){
  const push=body.push||{},cur=STATE.db;
  const isAdm=user.role==='admin';
  const projs=cleanProjects(push.projects);
  projs.forEach(p=>{p.updatedBy=user.name;});
  const improvements=[];
  cleanImprovements(push.improvements).forEach(x=>{
    const existing=(cur.improvements||[]).find(item=>item.id===x.id);
    if(user.role==='contributor'){
      if(existing&&existing.submittedBy!==user.name)return;
      if(existing){
        ['submittedBy','status','decision','piOwner','piFeedback','piSuggestion','piAction','piReason','feedbackLog','projectId'].forEach(k=>{
          if(Object.prototype.hasOwnProperty.call(existing,k))x[k]=existing[k];else delete x[k];
        });
      }else{
        Object.assign(x,{submittedBy:user.name,status:'Baru',decision:'Pending',piOwner:'',piFeedback:'',piSuggestion:'',piAction:'',piReason:'',feedbackLog:[],projectId:''});
      }
    }else if(existing){
      ['title','department','submittedBy','submittedAt','type','issue','background','purpose','goals','savingMp','qualityMetric','attachments','links'].forEach(k=>{
        if(Object.prototype.hasOwnProperty.call(existing,k))x[k]=existing[k];else delete x[k];
      });
    }
    x.updatedBy=user.name;improvements.push(x);
  });
  const deleted={};
  if(push.deleted&&typeof push.deleted==='object')for(const k in push.deleted){const v=Number(push.deleted[k]);if(isFinite(v)&&v>0)deleted[k]=v;}
  let rejected=false;
  if(push.settings&&!isAdm)rejected=true;
  const b=S.normDB({settings:(isAdm&&push.settings&&typeof push.settings==='object')?push.settings:cur.settings,projects:projs,improvements:improvements,deleted:deleted});
  const before=sigAll(cur),prevMap={};cur.projects.forEach(p=>{prevMap[p.id]=p.updatedAt;});
  const merged=S.mergeDB(cur,b);
  const changed=sigAll(merged)!==before;
  if(changed){
    STATE.db=merged;STATE.rev++;
    saveState();
    const names=merged.projects.filter(p=>prevMap[p.id]!==p.updatedAt).map(p=>(p.code||p.id)+(prevMap[p.id]===undefined?' (baru)':''));
    const gone=Object.keys(deleted).filter(k=>cur.deleted[k]===undefined||cur.deleted[k]<deleted[k]);
    log(user.name,'menyimpan:',names.join(', ')||'-',gone.length?('| dihapus: '+gone.length):'',(isAdm&&push.settings)?'| pengaturan':'');
    runBackup(false);
    broadcast(user.name);
  }
  const pushed=projs.length>0||improvements.length>0||Object.keys(deleted).length>0||!!push.settings;
  if(!changed&&!rejected&&!pushed&&Number(body.rev)===STATE.rev)return{rev:STATE.rev,unchanged:true};
  const out={rev:STATE.rev,projects:STATE.db.projects,improvements:STATE.db.improvements,deleted:STATE.db.deleted,settingsAt:STATE.db.settings.settingsAt||0};
  if(rejected||(STATE.db.settings.settingsAt||0)!==(Number(body.sa)||0)){out.settings=STATE.db.settings;if(rejected)out.forceSettings=true;}
  return out;
}

/* ---------- file quotation ---------- */
function safeName(n){return String(n||'file').replace(/[^\w.\-() ]+/g,'_').replace(/^\.+/,'_').slice(0,120)||'file';}
function handleUpload(req,res,user){
  const limit=CFG.maxUploadMB*1024*1024;
  const orig=safeName(decodeURIComponent(String(req.headers['x-filename']||'file')));
  const rawPrefix=decodeURIComponent(String(req.headers['x-prefix']||'PRJ'));
  if(user.role==='contributor'&&!/^IMP-[A-Za-z0-9]+$/.test(rawPrefix))return fail(req,res,403,'Akses Kontributor hanya dapat mengunggah lampiran Improvement');
  const pre=safeName(rawPrefix).replace(/\s+/g,'_').slice(0,30);
  const name=pre+'_'+Date.now().toString(36)+'_'+orig;
  const target=path.join(FILES,name),tmp=target+'.part';
  let n=0,aborted=false;
  const ws=fs.createWriteStream(tmp);
  req.on('data',c=>{n+=c.length;if(n>limit&&!aborted){aborted=true;ws.destroy();try{fs.unlinkSync(tmp);}catch(e){}fail(req,res,413,'File terlalu besar (maksimal '+CFG.maxUploadMB+' MB)');req.destroy();}});
  req.pipe(ws);
  ws.on('finish',()=>{if(aborted)return;try{fs.renameSync(tmp,target);}catch(e){return fail(req,res,500,'Gagal menyimpan file');}log(user.name,'mengunggah file',name,'('+n+' byte)');sendJSON(req,res,200,{name:name,size:n});});
  ws.on('error',()=>{if(!aborted){aborted=true;fail(req,res,500,'Gagal menyimpan file');}});
}
function handleDownload(req,res,name){
  name=safeName(decodeURIComponent(name));
  const f=path.join(FILES,name);
  if(path.dirname(f)!==FILES||!fs.existsSync(f)||!fs.statSync(f).isFile())return fail(req,res,404,'File tidak ditemukan');
  const ext=path.extname(name).toLowerCase(),inl=INLINE[ext];
  const dn=name.replace(/^[^_]*_[a-z0-9]+_/,'');
  res.writeHead(200,baseHeaders({'Content-Type':inl||'application/octet-stream','Content-Length':fs.statSync(f).size,'Content-Disposition':(inl?'inline':'attachment')+"; filename*=UTF-8''"+encodeURIComponent(dn),'Content-Security-Policy':"default-src 'none'; style-src 'unsafe-inline'; img-src data:",'Cache-Control':'private, max-age=3600'}));
  fs.createReadStream(f).pipe(res);
}

/* ---------- router ---------- */
function adminsCount(){return USERS.users.filter(u=>u.role==='admin').length;}
async function route(req,res){
  const url=new URL(req.url,'http://x'),p=url.pathname,m=req.method;
  if(m==='GET'||m==='HEAD'){
    if(p==='/'||p==='/index.html'||p==='/shared.js'){
      const f=path.join(APP_DIR,'public',p==='/shared.js'?'shared.js':'index.html');
      const b=fs.readFileSync(f);res.writeHead(200,baseHeaders({'Content-Type':MIME[path.extname(f)],'Content-Length':b.length}));return res.end(m==='HEAD'?undefined:b);
    }
    if(p==='/favicon.ico'){res.writeHead(204);return res.end();}
  }
  if(!p.startsWith('/api/'))return fail(req,res,404,'Tidak ditemukan');
  if(m!=='GET'&&m!=='HEAD'&&req.headers['x-pt']!=='1')return fail(req,res,403,'Permintaan tidak valid');

  if(p==='/api/health')return sendJSON(req,res,200,{ok:true,version:VERSION,rev:STATE.rev});
  if(p==='/api/public'&&m==='GET')return sendJSON(req,res,200,{needSetup:USERS.users.length===0,local:isLocal(req),users:USERS.users.filter(u=>u.role==='admin'||u.role==='user').map(u=>u.name).sort((a,b)=>a.localeCompare(b)),contributors:USERS.users.filter(u=>u.role==='contributor').map(u=>u.name).sort((a,b)=>a.localeCompare(b)),viewers:USERS.users.filter(u=>u.role==='viewer').map(u=>u.name).sort((a,b)=>a.localeCompare(b)),appName:STATE.db.settings.appName||'Project Tracker',version:VERSION});

  if(p==='/api/setup'&&m==='POST'){
    if(USERS.users.length)return fail(req,res,403,'Admin sudah dibuat');
    if(!isLocal(req))return fail(req,res,403,'Admin pertama hanya bisa dibuat dari PC server (localhost)');
    const b=await readJSONBody(req,1e5);
    if(!validName(b.name))return fail(req,res,400,'Nama tidak valid (2-40 karakter)');
    if(!validPin(b.pin))return fail(req,res,400,'PIN minimal 4 karakter');
    USERS.users.push(mkUser(String(b.name).trim(),b.pin,'admin'));saveUsers();
    const u=USERS.users[0];log('Admin pertama dibuat:',u.name);
    return sendJSON(req,res,200,{user:pubUser(u),serverTime:Date.now()},{'Set-Cookie':cookieHdr(newSession(u.name),CFG.sessionDays*86400)});
  }
  if(p==='/api/login'&&m==='POST'){
    const b=await readJSONBody(req,1e5);
    const key=String(b.name||'').toLowerCase()+'|'+req.socket.remoteAddress;
    const wait=lockInfo(key);
    if(wait)return fail(req,res,429,'Terlalu banyak percobaan. Coba lagi dalam '+Math.ceil(wait/60)+' menit.');
    const u=findUser(b.name);
    if(!u||!checkPin(u,b.pin)){noteFail(key);log('Login gagal:',String(b.name||'').slice(0,40),'dari',req.socket.remoteAddress);return fail(req,res,401,'Nama atau PIN salah');}
    delete FAILS[key];log('Login:',u.name,'dari',req.socket.remoteAddress);
    return sendJSON(req,res,200,{user:pubUser(u),serverTime:Date.now()},{'Set-Cookie':cookieHdr(newSession(u.name),CFG.sessionDays*86400)});
  }
  const user=authUser(req);
  if(!user)return fail(req,res,401,'Belum masuk');
  if(p==='/api/me'&&m==='GET')return sendJSON(req,res,200,{user:pubUser(user),serverTime:Date.now(),version:VERSION});
  if(p==='/api/logout'&&m==='POST'){const tok=parseCookies(req).pt_session;if(tok){delete SESS[sha(tok)];saveSess();}return sendJSON(req,res,200,{ok:true},{'Set-Cookie':cookieHdr('',0)});}

  if(p==='/api/events'&&m==='GET'){
    res.writeHead(200,baseHeaders({'Content-Type':'text/event-stream','Connection':'keep-alive','X-Accel-Buffering':'no'}));
    res.write('retry: 3001\n\n');
    const c={res:res,user:user.name};CLIENTS.add(c);
    req.on('close',()=>{CLIENTS.delete(c);broadcast('');});
    broadcast('');return;
  }
  if(p==='/api/sync'&&m==='POST'){
    const b=await readJSONBody(req);
    const push=b.push||{};
    if(user.role==='contributor'&&((Array.isArray(push.projects)&&push.projects.length>0)||(push.deleted&&typeof push.deleted==='object'&&Object.keys(push.deleted).length>0)))return fail(req,res,403,'Akses Kontributor hanya dapat mengubah Improvement');
    const result=doSync(user,b);
    if(user.role==='contributor')result.improvements=(result.improvements||[]).filter(x=>x.submittedBy===user.name);
    return sendJSON(req,res,200,result);
  }
  if(p==='/api/files'&&m==='POST')return handleUpload(req,res,user);
  if(p.startsWith('/api/files/')&&m==='GET')return handleDownload(req,res,p.slice('/api/files/'.length));
  if(p==='/api/export'&&m==='GET'){
    const body=Buffer.from(JSON.stringify(STATE.db,null,1));
    res.writeHead(200,baseHeaders({'Content-Type':'application/json','Content-Disposition':'attachment; filename="tracker-data-'+ymd(new Date())+'.json"','Content-Length':body.length}));return res.end(body);
  }
  if(p==='/api/pin'&&m==='POST'){
    const b=await readJSONBody(req,1e5);
    if(!checkPin(user,b.old))return fail(req,res,400,'PIN lama salah');
    if(!validPin(b.pin))return fail(req,res,400,'PIN baru minimal 4 karakter');
    const salt=crypto.randomBytes(16).toString('hex');user.salt=salt;user.hash=hashPin(b.pin,salt);saveUsers();
    log(user.name,'mengganti PIN');return sendJSON(req,res,200,{ok:true});
  }
  /* --- khusus admin --- */
  if(p==='/api/backup'&&m==='POST'){if(user.role!=='admin')return fail(req,res,403,'Hanya admin');return sendJSON(req,res,200,{files:runBackup(true)});}
  if(p==='/api/users'||p.startsWith('/api/users/')){
    if(user.role!=='admin')return fail(req,res,403,'Hanya admin');
    const list=()=>({users:USERS.users.map(pubUser)});
    if(p==='/api/users'&&m==='GET')return sendJSON(req,res,200,list());
    if(p==='/api/users'&&m==='POST'){
      const b=await readJSONBody(req,1e5);
      if(!validName(b.name))return fail(req,res,400,'Nama tidak valid (2-40 karakter)');
      if(!validPin(b.pin))return fail(req,res,400,'PIN minimal 4 karakter');
      if(findUser(b.name))return fail(req,res,400,'Nama sudah dipakai');
      USERS.users.push(mkUser(String(b.name).trim(),b.pin,b.role));saveUsers();log(user.name,'menambah pengguna',b.name);
      return sendJSON(req,res,200,list());
    }
    const target=findUser(decodeURIComponent(p.slice('/api/users/'.length)));
    if(!target)return fail(req,res,404,'Pengguna tidak ditemukan');
    if(m==='PUT'){
      const b=await readJSONBody(req,1e5);
      if(b.pin!==undefined){if(!validPin(b.pin))return fail(req,res,400,'PIN minimal 4 karakter');const salt=crypto.randomBytes(16).toString('hex');target.salt=salt;target.hash=hashPin(b.pin,salt);
        for(const k in SESS)if(SESS[k].name===target.name&&target!==user)delete SESS[k];saveSess();log(user.name,'mereset PIN',target.name);}
      if(b.role!==undefined){
        const nr=['admin','user','viewer','contributor'].includes(b.role)?b.role:'user';
        if(target.role==='admin'&&nr!=='admin'&&adminsCount()<=1)return fail(req,res,400,'Harus ada minimal satu admin');
        if(target===user&&nr!=='admin')return fail(req,res,400,'Tidak bisa menurunkan peran diri sendiri');
        if(target.role!==nr){target.role=nr;for(const k in SESS)if(SESS[k].name===target.name)delete SESS[k];saveSess();log(user.name,'mengubah peran',target.name,'menjadi',nr);}
      }
      saveUsers();return sendJSON(req,res,200,list());
    }
    if(m==='DELETE'){
      if(target===user)return fail(req,res,400,'Tidak bisa menghapus akun sendiri');
      if(target.role==='admin'&&adminsCount()<=1)return fail(req,res,400,'Harus ada minimal satu admin');
      USERS.users=USERS.users.filter(u=>u!==target);for(const k in SESS)if(SESS[k].name===target.name)delete SESS[k];
      saveUsers();saveSess();log(user.name,'menghapus pengguna',target.name);return sendJSON(req,res,200,list());
    }
  }
  return fail(req,res,404,'Tidak ditemukan');
}

/* ---------- kunci agar server tidak jalan ganda ---------- */
const LOCK=path.join(DATA,'server.lock');
function alive(pid){try{process.kill(pid,0);return true;}catch(e){return e.code==='EPERM';}}
function healthy(port){return new Promise(r=>{const q=http.get({host:'127.0.0.1',port:port,path:'/api/health',timeout:1500},rs=>{let d='';rs.on('data',c=>d+=c);rs.on('end',()=>{try{r(!!JSON.parse(d).ok);}catch(e){r(false);}});});q.on('error',()=>r(false));q.on('timeout',()=>{q.destroy();r(false);});});}
async function serverRunning(){
  try{const o=readJSON(LOCK);if(o&&alive(o.pid)&&await healthy(o.port))return o;}catch(e){}
  return null;
}

/* ---------- perintah CLI ---------- */
async function cli(cmd,args){
  const run=await serverRunning();
  if(run&&cmd!=='help'){console.error('Server sedang berjalan (PID '+run.pid+'). Hentikan server dulu sebelum menjalankan perintah ini.');process.exit(1);}
  loadUsers();
  if(cmd==='adduser'){
    const[name,pin,role]=args;
    if(!validName(name)||!validPin(pin)){console.error('Pemakaian: node server.js adduser "<nama>" <PIN minimal 4 karakter> [admin|user]');process.exit(1);}
    if(findUser(name)){console.error('Nama sudah dipakai.');process.exit(1);}
    USERS.users.push(mkUser(name.trim(),pin,role||(USERS.users.length?'user':'admin')));saveUsers();
    console.log('Pengguna "'+name+'" dibuat dengan peran '+USERS.users[USERS.users.length-1].role+'.');return;
  }
  if(cmd==='resetpin'){
    const[name,pin]=args;const u=findUser(name);
    if(!u||!validPin(pin)){console.error('Pemakaian: node server.js resetpin "<nama>" <PIN baru minimal 4 karakter>');process.exit(1);}
    u.salt=crypto.randomBytes(16).toString('hex');u.hash=hashPin(pin,u.salt);saveUsers();SESS={};saveSess();console.log('PIN "'+u.name+'" direset.');return;
  }
  if(cmd==='listusers'){USERS.users.forEach(u=>console.log(u.role.padEnd(6),u.name));return;}
  if(cmd==='import'){
    const force=args.includes('--force'),src=args.find(a=>!a.startsWith('--'));
    if(!src||!fs.existsSync(src)){console.error('Pemakaian: node server.js import "<folder bersama v3.1 atau file tracker-data.json>" [--force]');process.exit(1);}
    let jf=src,dir=src;
    if(fs.statSync(src).isDirectory())jf=path.join(src,'tracker-data.json');else dir=path.dirname(src);
    if(!fs.existsSync(jf)){console.error('File tidak ditemukan: '+jf);process.exit(1);}
    const raw=readJSON(jf);
    if(raw.demo&&!force){console.error('File ini masih berisi DATA CONTOH (belum dihapus). Impor dibatalkan. Jika tetap ingin mengimpor, tambahkan --force.');process.exit(1);}
    loadState();
    const before=STATE.db.projects.length;
    STATE.db=S.mergeDB(STATE.db,S.normDB(raw));STATE.rev++;saveState();
    let copied=0;const qd=path.join(dir,'quotations');
    if(fs.existsSync(qd)&&fs.statSync(qd).isDirectory()){fs.readdirSync(qd).forEach(n=>{const t=path.join(FILES,safeName(n));if(!fs.existsSync(t)&&fs.statSync(path.join(qd,n)).isFile()){fs.copyFileSync(path.join(qd,n),t);copied++;}});}
    console.log('Impor selesai. Project: '+before+' -> '+STATE.db.projects.length+'. File quotation disalin: '+copied+'.');return;
  }
  if(cmd==='backup'){loadState();console.log('Backup dibuat:',runBackup(true).join(', '));return;}
  console.log('Perintah:\n  node server.js                       jalankan server\n  node server.js adduser "Nama" PIN [admin|user]\n  node server.js resetpin "Nama" PIN\n  node server.js listusers\n  node server.js import "<folder v3.1 / tracker-data.json>" [--force]\n  node server.js backup');
}

/* ---------- start ---------- */
async function start(){
  const run=await serverRunning();
  if(run){console.error('Server sudah berjalan (PID '+run.pid+', port '+run.port+'). Tidak boleh menjalankan dua server pada data yang sama.');process.exit(3);}
  loadUsers();loadState();
  const server=http.createServer((req,res)=>{
    route(req,res).catch(e=>{
      const code=e.status||500;if(code===500)log('ERROR',req.method,req.url,e.stack||e);
      if(!res.headersSent)fail(req,res,code,code===500?'Kesalahan di server':e.message);else try{res.end();}catch(_){}
    });
  });
  server.on('error',e=>{if(e.code==='EADDRINUSE')console.error('Port '+CFG.port+' sudah dipakai program lain. Ubah "port" di config.json.');else console.error(e);process.exit(e.code==='EADDRINUSE'?4:1);});
  server.requestTimeout=0;server.headersTimeout=30010;
  server.listen(CFG.port,CFG.host,()=>{
    fs.writeFileSync(LOCK,JSON.stringify({pid:process.pid,port:CFG.port,startedAt:Date.now()}));
    log('Project Tracker v'+VERSION,'berjalan. Data:',DATA,'| project:',STATE.db.projects.length,'| pengguna:',USERS.users.length);
    const ips=[];Object.values(os.networkInterfaces()).forEach(l=>(l||[]).forEach(i=>{if(i.family==='IPv4'&&!i.internal)ips.push(i.address);}));
    console.log('\n  Buka di browser:  http://localhost:'+CFG.port);
    ips.forEach(ip=>console.log('  Alamat untuk tim: http://'+ip+':'+CFG.port));
    console.log('  Nama komputer:    http://'+os.hostname()+':'+CFG.port+'\n');
    if(!USERS.users.length)console.log('  Belum ada pengguna: buka http://localhost:'+CFG.port+' DI PC INI untuk membuat admin pertama.\n');
    runBackup(true);
  });
  setInterval(()=>runBackup(false),10*60*1000).unref();
  const bye=sig=>{log('Berhenti ('+sig+')');try{runBackup(true);}catch(e){}try{fs.unlinkSync(LOCK);}catch(e){}process.exit(0);};
  process.on('SIGINT',()=>bye('SIGINT'));process.on('SIGTERM',()=>bye('SIGTERM'));
  process.on('uncaughtException',e=>{log('FATAL',e.stack||e);try{fs.unlinkSync(LOCK);}catch(_){}process.exit(3);}); // 3 = start.bat akan memulai ulang otomatis
}
const cmd=process.argv[2];
if(cmd&&cmd!=='start')cli(cmd,process.argv.slice(3)).catch(e=>{console.error(e.message);process.exit(1);});
else start();
