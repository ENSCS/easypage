/* Easypage — ตัวเรียก API (api.php) + เครื่องมือเล็กๆ
   ทุกอย่างอยู่บนเซิร์ฟเวอร์: ฉบับร่าง / รายการ / เผยแพร่ / ปิด-เปิด / ลบ → เปิดจากเครื่องไหนก็เห็นเหมือนกัน
   เบราว์เซอร์เก็บแค่ “ที่พักกันข้อความหาย” ชั่วคราวระหว่างพิมพ์ (easypage:buf:<id>) และข้อมูลรุ่นเก่า (easypage:v1) เพื่อย้ายขึ้นเซิร์ฟเวอร์
*/
(function(g){
'use strict';

/* ตัวอักษรที่ใช้สุ่มรหัส: a-z และ 1-9 โดยตัด 0 o i l ออก (หน้าตาคล้ายกัน อ่าน/พิมพ์สับสน) = 32 ตัวพอดี
   ตรวจรหัสที่มีอยู่แล้ว (ID_RE) ยังรับ a-z0-9 กว้างๆ เพื่อให้หน้าเก่าที่ใช้ตัวอักษรเหล่านี้ยังเปิด/แก้ได้ */
const ALPHA='abcdefghjkmnpqrstuvwxyz123456789';
const ID_RE=/^[a-z0-9]{5}$/;
const OLD_KEY='easypage:v1';

function randId(){
  const b=crypto.getRandomValues(new Uint8Array(5));
  let s='';
  for(const x of b)s+=ALPHA[x&31];   // 32 ตัวพอดี → ไม่เอนเอียง
  return s;
}
function idFromUrl(){
  const m=location.search.match(/^\?(?:id)?=([A-Za-z0-9]{5})(?:&|$)/);
  return m?m[1].toLowerCase():null;
}
const shareUrl=id=>location.href.replace(/[?#].*$/,'').replace(/[^\/]*$/,'')+'view.php?='+id;
/* ลิงก์ย่อ: ระบบย่อลิงก์แยกต่างหาก รับรหัส 5 ตัวของเราไปต่อท้ายได้เลย — เปลี่ยนโดเมน/พาธที่นี่ที่เดียว */
const SHORT_BASE='https://9fly.tv/p/';
const shortUrl=id=>SHORT_BASE+id;

/* ---------- เรียก api.php ---------- */
const NET_ERR='ติดต่อเซิร์ฟเวอร์ไม่ได้ — ต้องเปิดผ่านเว็บเซิร์ฟเวอร์ที่มี PHP (ไม่ใช่เปิดไฟล์ตรงๆ)';
const qs=o=>Object.keys(o).filter(k=>o[k]!==undefined&&o[k]!==null).map(k=>encodeURIComponent(k)+'='+encodeURIComponent(o[k])).join('&');

async function read(a,params){
  try{
    const r=await fetch('api.php?'+qs(Object.assign({a},params||{})),{cache:'no-store'});
    const j=await r.json().catch(()=>null);
    if(!j)return {ok:false,status:r.status,error:'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (ต้องมี PHP)'};
    j.status=r.status;return j;
  }catch(e){return {ok:false,status:0,error:NET_ERR}}
}
/* คำสั่งเขียน — ถามรหัสผ่านให้เองถ้าเซิร์ฟเวอร์ตั้งโหมด password */
async function write(a,id,params,body){
  for(let i=0;i<3;i++){
    let pw='';try{pw=sessionStorage.getItem('easypage:pw')||''}catch(e){}
    let j;
    try{
      const r=await fetch('api.php?'+qs(Object.assign({a,id},params||{})),{method:'POST',
        headers:{'X-Easypage':'1','X-Easypage-Password':encodeURIComponent(pw),'Content-Type':'text/plain;charset=UTF-8'},body:body||''});
      j=await r.json().catch(()=>null);
      if(j)j.status=r.status;
    }catch(e){return {ok:false,status:0,error:NET_ERR}}
    if(j&&j.needPassword){
      const x=prompt((j.error||'ใส่รหัสผ่านเพื่อดำเนินการ')+'\n(จำไว้เฉพาะแท็บนี้ จนกว่าจะปิดแท็บ)');
      if(x===null)return {ok:false,status:401,error:'ยกเลิก'};
      try{sessionStorage.setItem('easypage:pw',x)}catch(e){}
      continue;
    }
    return j||{ok:false,status:0,error:'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (ต้องมี PHP)'};
  }
  return {ok:false,status:401,error:'รหัสผ่านไม่ถูกต้อง'};
}

const list=()=>read('list');                               // {ok,items:[info…]}
const get=id=>read('get',{id});                             // {ok,md,info}
/* บันทึกฉบับร่าง: o = {isNew, base (เวอร์ชันที่โหลดมา — ถ้าเซิร์ฟเวอร์ใหม่กว่า = ชน; ว่าง = เขียนทับ), expDate, expTime} */
const save=(id,md,o)=>{
  o=o||{};
  return write('save',id,{new:o.isNew?1:undefined,base:o.base,exp:o.expDate||'',exptime:o.expTime||''},md);
};
const publish=id=>write('publish',id);
const setClosed=(id,closed)=>write(closed?'close':'open',id);
const remove=id=>write('delete',id);

/* ---------- ที่พักกันข้อความหาย (เฉพาะระหว่างพิมพ์ — ล้างเมื่อบันทึกขึ้นเซิร์ฟเวอร์สำเร็จ) ---------- */
const BUF='easypage:buf:';
function bufSet(id,o){try{localStorage.setItem(BUF+id,JSON.stringify(o))}catch(e){}}
function bufGet(id){try{return JSON.parse(localStorage.getItem(BUF+id)||'null')}catch(e){return null}}
function bufClear(id){try{localStorage.removeItem(BUF+id)}catch(e){}}

/* ---------- ย้ายข้อมูลรุ่นเก่า (เก็บในเบราว์เซอร์) ขึ้นเซิร์ฟเวอร์ — ทำครั้งเดียวต่อหน้า ไม่ทับของที่มีบนเซิร์ฟเวอร์ ไม่ลบของเดิมในเครื่อง ---------- */
async function migrateLocal(){
  let all;try{all=JSON.parse(localStorage.getItem(OLD_KEY)||'{}')}catch(e){return {moved:0}}
  const recs=Object.values(all||{}).filter(p=>p&&ID_RE.test(String(p.id))&&String(p.md||'').trim());
  if(!recs.length)return {moved:0};
  let done={};try{done=JSON.parse(localStorage.getItem('easypage:migrated')||'{}')}catch(e){}
  const todo=recs.filter(p=>!done[p.id]);
  if(!todo.length)return {moved:0};
  const l=await list();
  if(!l.ok)return {moved:0,error:l.error};
  const have=new Set(l.items.map(x=>x.id));
  let moved=0;
  for(const p of todo){
    if(have.has(p.id)){done[p.id]=1;continue}   // เซิร์ฟเวอร์มีอยู่แล้ว → ใช้ของเซิร์ฟเวอร์
    const r=await save(p.id,p.md,{isNew:true});
    if(r.ok){moved++;done[p.id]=1}
    else if(r.exists)done[p.id]=1;
    else break;
  }
  try{localStorage.setItem('easypage:migrated',JSON.stringify(done))}catch(e){}
  return {moved};
}

/* ---------- วันหมดอายุ / ชื่อไฟล์ / ทั่วไป ---------- */
function fmtExpiry(p){
  if(!p||!/^\d{4}-\d{2}-\d{2}$/.test(p.expDate||''))return '';
  const t=/^([01]\d|2[0-3]):[0-5]\d$/.test(p.expTime||'')?p.expTime:'';
  const at=Date.parse(p.expDate+'T'+(t?t+':00':'23:59:59')+'+07:00');
  if(isNaN(at))return '';
  return new Date(at).toLocaleDateString('th-TH',{day:'numeric',month:'short',year:'numeric',timeZone:'Asia/Bangkok'})+(t?' '+t+' น.':'');
}
/* ชื่อไฟล์ตอนดาวน์โหลด = ชื่อเรื่อง (บรรทัดแรกของเนื้อหา) + .md — ตัดอักขระที่ใช้ตั้งชื่อไฟล์ไม่ได้ออก ไม่มีชื่อเรื่องใช้รหัสหน้าแทน
   (ไฟล์บนเซิร์ฟเวอร์ยังเป็น p/<รหัส>.md เสมอ — ชื่อนี้ใช้เฉพาะไฟล์ที่ดาวน์โหลด) */
function fileName(p){
  let t=(p.title||(g.MD&&p.md!=null?g.MD.titleOf(p.md):'')||'').trim();
  if(t==='ไม่มีชื่อเรื่อง'&&!p.title)t='';
  t=t.replace(/…$/,'').replace(/[\\/:*?"<>|\u0000-\u001f]+/g,' ').replace(/\s+/g,' ').replace(/^[.\s]+|[.\s]+$/g,'');
  t=[...t].slice(0,80).join('').trim();
  return (t||p.id)+'.md';
}
function download(name,data,type){
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob([data],{type:type||'application/octet-stream'}));
  a.download=name;document.body.appendChild(a);a.click();
  setTimeout(()=>{URL.revokeObjectURL(a.href);a.remove()},1000);
}
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

g.EP={ID_RE,randId,idFromUrl,shareUrl,shortUrl,list,get,save,publish,setClosed,remove,
  bufSet,bufGet,bufClear,migrateLocal,fmtExpiry,fileName,download,fmtDate,copyText,toast};
})(window);
