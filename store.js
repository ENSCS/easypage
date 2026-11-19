/* Easypage — ที่เก็บข้อมูล + ไฟล์เผยแพร่ + เครื่องมือเล็กๆ
   - ฉบับร่าง/ต้นฉบับ เก็บใน localStorage ของเบราว์เซอร์ผู้เขียน (key: easypage:v1)
   - ไฟล์เผยแพร่คือ p/<id>.md (markdown ดิบ) → view.php?=<id> อ่านแล้วแปลงด้วย Parsedown
*/
(function(g){
'use strict';

const KEY='easypage:v1';
/* ตัวอักษรที่ใช้สุ่มรหัส: a-z และ 1-9 โดยตัด 0 o i l ออก (หน้าตาคล้ายกัน อ่าน/พิมพ์สับสน) = 32 ตัวพอดี
   ตรวจรหัสที่มีอยู่แล้ว (ID_RE) ยังรับ a-z0-9 กว้างๆ เพื่อให้หน้าเก่าที่ใช้ตัวอักษรเหล่านี้ยังเปิด/แก้ได้ */
const ALPHA='abcdefghjkmnpqrstuvwxyz123456789';
const ID_RE=/^[a-z0-9]{5}$/;

/* ---------- localStorage ---------- */
function readAll(){
  try{const o=JSON.parse(localStorage.getItem(KEY)||'{}');return o&&typeof o==='object'&&!Array.isArray(o)?o:{}}
  catch(e){return {}}
}
function writeAll(o){
  try{localStorage.setItem(KEY,JSON.stringify(o));return true}catch(e){return false}
}
const list=()=>Object.values(readAll()).sort((a,b)=>(b.updated||0)-(a.updated||0));
const get=id=>readAll()[id]||null;
function put(p){const all=readAll();all[p.id]=p;return writeAll(all)}
function remove(id){const all=readAll();delete all[id];return writeAll(all)}

/* ---------- ไฟล์เผยแพร่ ---------- */
const fileText=p=>p.md||'';

/* ---------- สุ่มรหัส 5 ตัว (a-z 1-9 ไม่มี 0 o i l) ไม่ซ้ำของเดิม ---------- */
function randId(){
  const b=crypto.getRandomValues(new Uint8Array(5));
  let s='';
  for(const x of b)s+=ALPHA[x&31];   // 32 ตัวพอดี → ไม่เอนเอียง
  return s;
}
/* สถานะจริงบนเซิร์ฟเวอร์ผ่าน view.php?=id&check=1 → 'open' | 'closed' | 'expired' | 'missing' | null (ติดต่อไม่ได้ / ไม่ใช่เซิร์ฟเวอร์ PHP) */
async function remoteState(id){
  try{
    const c=new AbortController(),t=setTimeout(()=>c.abort(),4000);
    const r=await fetch('view.php?='+id+'&check=1',{method:'HEAD',cache:'no-store',signal:c.signal});
    clearTimeout(t);
    const st=r.headers.get('X-Easypage-State');
    return st==='open'||st==='closed'||st==='expired'||st==='missing'?st:null;
  }catch(e){return null}
}
/* มี id นี้อยู่บนเซิร์ฟเวอร์แล้ว (เปิดอยู่ / ปิดอยู่ / หมดอายุ) → ห้ามสุ่มซ้ำ */
async function exists(id){const s=await remoteState(id);return s==='open'||s==='closed'||s==='expired'}
async function genId(){
  const all=readAll();
  for(let k=0;k<30;k++){
    const id=randId();
    if(all[id])continue;
    if(await exists(id))continue; // ซ้ำกับไฟล์ที่เผยแพร่ไว้แล้ว
    return id;
  }
  return randId();
}
function idFromUrl(){
  const m=location.search.match(/^\?(?:id)?=([A-Za-z0-9]{5})(?:&|$)/);
  return m?m[1].toLowerCase():null;
}
const shareUrl=id=>location.href.replace(/[?#].*$/,'').replace(/[^\/]*$/,'')+'view.php?='+id;

/* ชื่อไฟล์ตอนดาวน์โหลด = ชื่อเรื่อง (บรรทัดแรกของเนื้อหา) + .md — ตัดอักขระที่ใช้ตั้งชื่อไฟล์ไม่ได้ออก ไม่มีชื่อเรื่องใช้รหัสหน้าแทน
   (ไฟล์บนเซิร์ฟเวอร์ยังเป็น p/<รหัส>.md เสมอ — ชื่อนี้ใช้เฉพาะไฟล์ที่ดาวน์โหลด) */
function fileName(p){
  let t=(p.title||(g.MD?g.MD.titleOf(p.md):'')||'').trim();
  if(t==='ไม่มีชื่อเรื่อง'&&!p.title)t='';
  t=t.replace(/…$/,'').replace(/[\\/:*?"<>|\u0000-\u001f]+/g,' ').replace(/\s+/g,' ').replace(/^[.\s]+|[.\s]+$/g,'');
  t=[...t].slice(0,80).join('').trim();
  return (t||p.id)+'.md';
}

/* ---------- download ---------- */
function download(name,data,type){
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([data],{type:type||'application/octet-stream'}));
  a.download=name;document.body.appendChild(a);a.click();
  setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},1000);
}
/* ---------- ทั่วไป ---------- */
function fmtDate(ts){
  if(!ts)return '-';
  try{return new Date(ts).toLocaleString('th-TH',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}
  catch(e){return new Date(ts).toISOString()}
}
async function copyText(t){
  try{if(navigator.clipboard&&window.isSecureContext){await navigator.clipboard.writeText(t);return true}}catch(e){}
  const ta=document.createElement('textarea');
  ta.value=t;ta.setAttribute('readonly','');ta.style.cssText='position:fixed;top:0;left:0;opacity:0';
  document.body.appendChild(ta);ta.select();
  let ok=false;try{ok=document.execCommand('copy')}catch(e){}
  ta.remove();return ok;
}
let toastTimer;
function toast(msg){
  let el=document.getElementById('toast');
  if(!el){el=document.createElement('div');el.id='toast';document.body.appendChild(el)}
  el.textContent=msg;el.classList.add('show');
  clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),1800);
}
/* ยังไม่เผยแพร่ หรือแก้ไขหลังเผยแพร่ครั้งล่าสุด */
const isPending=p=>!p.publishedAt||p.publishedAt<p.updated;

/* ---------- เผยแพร่ / ปิด / เปิด: ส่งไป publish.php ---------- */
function markPublished(id,updated){
  const p=get(id);if(!p)return null;
  p.publishedAt=Math.max(p.publishedAt||0,updated);
  p.closed=false;   // เผยแพร่ = เปิดให้อ่านได้
  p.expired=false;
  put(p);return p;
}
function markClosed(id,closed){
  const p=get(id);if(!p)return null;
  p.closed=!!closed;put(p);return p;
}
/* ส่งคำสั่งไป publish.php (action: publish | close | open) — ถามรหัสผ่านให้เองถ้าเซิร์ฟเวอร์ตั้งไว้ */
/* กุญแจเจ้าของหน้า (สุ่ม 128 บิต) — สร้างตอนเผยแพร่ครั้งแรก เก็บในเบราว์เซอร์ผู้เขียน เซิร์ฟเวอร์เก็บแค่ hash
   ใช้พิสูจน์ว่าเป็นเจ้าของหน้า (แก้/ปิด/เปิดได้เฉพาะเครื่องที่ถือกุญแจ) ในโหมด publish='open' */
function ensureKey(id){
  const p=get(id);
  if(p&&p.key)return p.key;
  const k=[...crypto.getRandomValues(new Uint8Array(16))].map(x=>x.toString(16).padStart(2,'0')).join('');
  if(p){p.key=k;put(p)}
  return k;
}
async function api(id,action,body,params){
  const key=ensureKey(id);
  for(let i=0;i<3;i++){
    let pw='';try{pw=sessionStorage.getItem('easypage:pw')||''}catch(e){}
    let j;
    try{
      const r=await fetch('publish.php?id='+encodeURIComponent(id)+'&action='+action+(params||''),{method:'POST',
        headers:{'X-Easypage':'1','X-Easypage-Key':key,'X-Easypage-Password':encodeURIComponent(pw),'Content-Type':'text/plain;charset=UTF-8'},body:body||''});
      j=await r.json();
    }catch(e){return {ok:false,error:'ติดต่อ publish.php ไม่ได้ — ต้องเปิดผ่านเว็บเซิร์ฟเวอร์ที่มี PHP (ไม่ใช่เปิดไฟล์ตรงๆ)'}}
    if(j&&j.needPassword){
      const x=prompt((j.error||'ใส่รหัสผ่านเพื่อดำเนินการ')+'\n(จำไว้เฉพาะแท็บนี้ จนกว่าจะปิดแท็บ)');
      if(x===null)return {ok:false,error:'ยกเลิก'};
      try{sessionStorage.setItem('easypage:pw',x)}catch(e){}
      continue;
    }
    return j||{ok:false,error:'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง'};
  }
  return {ok:false,error:'รหัสผ่านไม่ถูกต้อง'};
}
/* วันหมดอายุของหน้า (เวลาไทย): expDate 'YYYY-MM-DD' + expTime 'HH:MM' (ว่าง = หมดสิ้นวันนั้น) → {at: epoch ms, hasTime} | null */
function expiryOf(p){
  if(!p||!p.expDate||!/^\d{4}-\d{2}-\d{2}$/.test(p.expDate))return null;
  const t=/^([01]\d|2[0-3]):[0-5]\d$/.test(p.expTime||'')?p.expTime:'';
  const at=Date.parse(p.expDate+'T'+(t?t+':00':'23:59:59')+'+07:00');
  return isNaN(at)?null:{at,hasTime:!!t};
}
function fmtExpiry(p){
  const e=expiryOf(p);if(!e)return '';
  const d=new Date(e.at).toLocaleDateString('th-TH',{day:'numeric',month:'short',year:'numeric',timeZone:'Asia/Bangkok'});
  return d+(e.hasTime?' '+p.expTime+' น.':'');
}
/* หมดอายุแล้วหรือยัง: ตามเวลาที่ตั้งไว้ในเครื่อง หรือตามที่เซิร์ฟเวอร์รายงานล่าสุด (p.expired) */
const isExpired=p=>!!(p&&p.publishedAt&&(p.expired||(expiryOf(p)&&Date.now()>expiryOf(p).at)));

async function publish(p){
  const j=await api(p.id,'publish',p.md,'&exp='+encodeURIComponent(p.expDate||'')+'&exptime='+encodeURIComponent(p.expTime||''));
  if(j.ok)markPublished(p.id,p.updated);
  return j;
}
/* ปิด: ผู้ที่เปิดลิงก์เห็นหน้าแจ้งว่าปิดอยู่ ไม่เห็นบทความ — เปิดกลับได้ (ไม่ลบเนื้อหา) */
async function setClosed(id,closed){
  const j=await api(id,closed?'close':'open');
  if(j.ok)markClosed(id,closed);
  return j;
}
/* ตรวจสถานะจริงของหน้าที่เผยแพร่แล้วทั้งหมด แล้วอัปเดตธง closed / publishedAt ในเครื่องให้ตรงกับเซิร์ฟเวอร์ → true ถ้ามีอะไรเปลี่ยน */
async function syncRemote(){
  let changed=false;
  await Promise.all(list().filter(p=>p.publishedAt).map(async p=>{
    const st=await remoteState(p.id);
    if(st===null)return;                       // ติดต่อไม่ได้ → ไม่แตะ
    const cur=get(p.id);if(!cur)return;
    if(st==='missing'&&cur.publishedAt){cur.publishedAt=0;cur.closed=false;put(cur);changed=true}   // ไฟล์บนเซิร์ฟเวอร์หายไป
    else if(st==='closed'&&!cur.closed){cur.closed=true;put(cur);changed=true}
    else if(st==='expired'&&(cur.closed||!cur.expired)){cur.closed=false;cur.expired=true;put(cur);changed=true}
    else if(st==='open'&&(cur.closed||cur.expired)){cur.closed=false;cur.expired=false;put(cur);changed=true}
  }));
  return changed;
}

g.EP={ID_RE,list,get,put,remove,fileText,
  genId,exists,remoteState,syncRemote,setClosed,fileName,idFromUrl,shareUrl,download,fmtDate,copyText,toast,isPending,publish,markPublished,markClosed,expiryOf,fmtExpiry,isExpired};
})(window);
