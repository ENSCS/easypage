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
/* ตรวจว่ามีไฟล์เผยแพร่ p/<id>.md อยู่แล้วหรือไม่ (เปิดผ่าน http เท่านั้น; file:// / ติดต่อไม่ได้ = ถือว่าไม่มี) */
async function exists(id){
  try{
    const c=new AbortController(),t=setTimeout(()=>c.abort(),3000);
    const r=await fetch('p/'+id+'.md',{method:'HEAD',cache:'no-store',signal:c.signal});
    clearTimeout(t);return r.ok;
  }catch(e){return false}
}
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

/* ---------- เผยแพร่: ส่งไป publish.php เขียน p/<id>.md บนเซิร์ฟเวอร์ ---------- */
function markPublished(id,updated){
  const p=get(id);if(!p)return null;
  p.publishedAt=Math.max(p.publishedAt||0,updated);
  put(p);return p;
}
async function publish(p){
  for(let i=0;i<3;i++){
    let pw='';try{pw=sessionStorage.getItem('easypage:pw')||''}catch(e){}
    let j;
    try{
      const r=await fetch('publish.php?id='+encodeURIComponent(p.id),{method:'POST',
        headers:{'X-Easypage':'1','X-Easypage-Password':encodeURIComponent(pw),'Content-Type':'text/plain;charset=UTF-8'},body:p.md});
      j=await r.json();
    }catch(e){return {ok:false,error:'ติดต่อ publish.php ไม่ได้ — ต้องเปิดผ่านเว็บเซิร์ฟเวอร์ที่มี PHP (ไม่ใช่เปิดไฟล์ตรงๆ)'}}
    if(j&&j.needPassword){
      const x=prompt((j.error||'ใส่รหัสผ่านเพื่อเผยแพร่')+'\n(จำไว้เฉพาะแท็บนี้ จนกว่าจะปิดแท็บ)');
      if(x===null)return {ok:false,error:'ยกเลิกการเผยแพร่'};
      try{sessionStorage.setItem('easypage:pw',x)}catch(e){}
      continue;
    }
    if(j&&j.ok)markPublished(p.id,p.updated);
    return j||{ok:false,error:'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง'};
  }
  return {ok:false,error:'รหัสผ่านไม่ถูกต้อง'};
}

g.EP={ID_RE,list,get,put,remove,fileText,
  genId,exists,idFromUrl,shareUrl,download,fmtDate,copyText,toast,isPending,publish,markPublished};
})(window);
