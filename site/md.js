/* Easypage — ตัวแปลง Markdown → HTML (เขียนเอง ไม่พึ่ง library)
   ปลอดภัย: HTML ดิบถูก escape ทั้งหมด, ลิงก์รับเฉพาะ http/https/mailto/tel/relative
   รองรับ: หัวข้อ, ตัวหนา/เอียง/ขีดฆ่า, code, code block, quote, list (ซ้อนได้ + task),
           ตาราง, เส้นคั่น, ลิงก์ [t](url), <url>, URL เปล่า, รูปภาพ  — ขึ้นบรรทัดใหม่ = <br> */
(function(g){
'use strict';

const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

/* ---------- URL ---------- */
function safeUrl(u,img){
  u=String(u).trim();
  if(!u||/[\s\u0000-\u001f]/.test(u))return null;
  if(/^www\./i.test(u))return img?null:'https://'+u;
  const sch=u.match(/^([a-z][a-z0-9+.\-]*):/i);
  if(sch){
    const s=sch[1].toLowerCase();
    if(s==='http'||s==='https')return u;
    if(!img&&(s==='mailto'||s==='tel'))return u;
    return null;
  }
  return u; // relative / anchor
}
const A=(h,t)=>'<a href="'+h+'" target="_blank" rel="noopener noreferrer">'+t+'</a>';

/* ---------- inline ---------- */
let ST=[];
const keep=h=>'\u0000'+(ST.push(h)-1)+'\u0000';
function restore(s){
  while(/\u0000\d+\u0000/.test(s))s=s.replace(/\u0000(\d+)\u0000/g,(m,i)=>ST[+i]);
  return s;
}
const EM='([^\\s*](?:[^*]*?[^\\s*])?)';
function inl(s){
  // code span
  s=s.replace(/(`+)([\s\S]*?[^`])\1(?!`)/g,(m,a,c)=>keep('<code>'+esc(c.trim())+'</code>'));
  // backslash escape
  s=s.replace(/\\([\\`*_{}\[\]()#+\-.!|~<>])/g,(m,c)=>keep(esc(c)));
  // image
  s=s.replace(/!\[([^\]]*)\]\(((?:[^()\s]|\([^()\s]*\))+)(?:\s+"([^"]*)")?\)/g,(m,alt,u,t)=>{
    const x=safeUrl(u,true);
    return x?keep('<img src="'+esc(x)+'" alt="'+esc(alt)+'"'+(t?' title="'+esc(t)+'"':'')+' loading="lazy">'):m;
  });
  // link
  s=s.replace(/\[([^\]]+)\]\(((?:[^()\s]|\([^()\s]*\))+)(?:\s+"([^"]*)")?\)/g,(m,t,u)=>{
    const x=safeUrl(u);
    return x?keep(A(esc(x),inl(t))):m;
  });
  // <autolink>
  s=s.replace(/<((?:https?:\/\/|mailto:)[^\s<>]+)>/gi,(m,u)=>keep(A(esc(u),esc(u))));
  s=esc(s);
  // bare URL (ASCII เท่านั้น เพื่อไม่กินตัวอักษรไทยที่ติดท้าย)
  s=s.replace(/(^|[^A-Za-z0-9\/@="'])((?:https?:\/\/|www\.)(?:(?!&lt;|&gt;|&quot;)[A-Za-z0-9\-._~:\/?#@!$&'()*+,;=%\[\]])+)/g,(m,pre,u)=>{
    let tail='';
    for(;;){
      const c=u.slice(-1);
      if(/[.,;:!?'\]*]/.test(c)||(c===')'&&(u.match(/\(/g)||[]).length<(u.match(/\)/g)||[]).length)){tail=c+tail;u=u.slice(0,-1)}
      else break;
    }
    if(!/^(https?:\/\/|www\.)[^\/?#]*[A-Za-z0-9]/i.test(u))return m;
    return pre+keep(A(/^www\./i.test(u)?'https://'+u:u,u))+tail;
  });
  // emphasis
  s=s.replace(new RegExp('\\*\\*\\*'+EM+'\\*\\*\\*','g'),'<strong><em>$1</em></strong>')
     .replace(new RegExp('\\*\\*'+EM+'\\*\\*','g'),'<strong>$1</strong>')
     .replace(/(^|[^A-Za-z0-9_])__([^\s_](?:[^_]*?[^\s_])?)__(?![A-Za-z0-9_])/g,'$1<strong>$2</strong>')
     .replace(new RegExp('\\*'+EM+'\\*','g'),'<em>$1</em>')
     .replace(/(^|[^A-Za-z0-9_])_([^\s_](?:[^_]*?[^\s_])?)_(?![A-Za-z0-9_])/g,'$1<em>$2</em>')
     .replace(/~~([^\s~](?:[^~]*?[^\s~])?)~~/g,'<del>$1</del>');
  return s;
}
const inline=s=>restore(inl(s).replace(/\n/g,'<br>'));

/* ---------- block ---------- */
const RE={
  fence:/^\s{0,3}(`{3,}|~{3,})\s*([^\s`]*)/,
  h:/^\s{0,3}(#{1,6})\s+(.*?)(?:\s+#+)?\s*$/,
  hr:/^\s{0,3}([-*_])(?:\s*\1){2,}\s*$/,
  bq:/^\s{0,3}>/,
  li:/^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/,
  delim:/^\s*\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?\s*$/
};
const isBlank=s=>!s.trim();
const indentOf=s=>s.match(/^ */)[0].length;
const tableStart=(L,i)=>L[i].includes('|')&&i+1<L.length&&L[i+1].includes('|')&&RE.delim.test(L[i+1]);
const startsBlock=s=>RE.fence.test(s)||RE.h.test(s)||RE.hr.test(s)||RE.bq.test(s);

function cells(l){
  l=l.trim().replace(/^\|/,'').replace(/\|$/,'');
  const r=[];let c='';
  for(let k=0;k<l.length;k++){
    const ch=l[k];
    if(ch==='\\'&&l[k+1]==='|'){c+='|';k++}
    else if(ch==='|'){r.push(c.trim());c=''}
    else c+=ch;
  }
  r.push(c.trim());
  return r;
}

function table(L,i){
  const head=cells(L[i]);
  const al=cells(L[i+1]).map(c=>/^:-+:$/.test(c)?' class="al-c"':/-:$/.test(c)?' class="al-r"':'');
  let h='<div class="tw"><table><thead><tr>'+head.map((c,k)=>'<th'+(al[k]||'')+'>'+inline(c)+'</th>').join('')+'</tr></thead><tbody>';
  i+=2;
  while(i<L.length&&!isBlank(L[i])&&L[i].includes('|')){
    const r=cells(L[i]);
    h+='<tr>'+head.map((_,k)=>'<td'+(al[k]||'')+'>'+inline(r[k]||'')+'</td>').join('')+'</tr>';
    i++;
  }
  return [h+'</tbody></table></div>',i];
}

function list(L,i){
  const n=L.length;
  const m0=L[i].match(RE.li);
  const base=m0[1].length, ordered=/^\d/.test(m0[2]);
  const start=ordered?parseInt(m0[2],10):1;
  const items=[];let loose=false;
  while(i<n){
    if(isBlank(L[i])){
      let j=i;while(j<n&&isBlank(L[j]))j++;
      const mj=j<n&&L[j].match(RE.li);
      if(mj&&mj[1].length<base+2&&/^\d/.test(mj[2])===ordered){loose=true;i=j}else break;
    }
    const m=L[i].match(RE.li);
    if(!m||m[1].length>=base+2||/^\d/.test(m[2])!==ordered)break;
    const body=[m[3]];i++;
    while(i<n){
      const t=L[i];
      if(isBlank(t)){
        let j=i;while(j<n&&isBlank(L[j]))j++;
        if(j<n&&indentOf(L[j])>=base+2){for(;i<j;i++)body.push('');loose=true;continue}
        break;
      }
      const ind=indentOf(t);
      if(ind>=base+2){body.push(t.slice(base+2));i++;continue}
      if(RE.li.test(t)||startsBlock(t))break;
      body.push(t.trim());i++; // ต่อบรรทัดเดิม
    }
    items.push(body);
  }
  let h='';
  for(const body of items){
    let task=null;
    const tm=body[0].match(/^\[([ xX])\]\s+([\s\S]*)$/);
    if(tm){task=tm[1]!==' ';body[0]=tm[2]}
    let inner=blocks(body).join('');
    if(!loose)inner=inner.replace(/^<p>([\s\S]*?)<\/p>/,'$1');
    h+=task===null?'<li>'+inner+'</li>'
      :'<li class="task"><input type="checkbox" disabled'+(task?' checked':'')+'>'+inner+'</li>';
  }
  const tag=ordered?'ol':'ul';
  return ['<'+tag+(ordered&&start!==1?' start="'+start+'"':'')+'>'+h+'</'+tag+'>',i];
}

function blocks(L){
  const out=[];const n=L.length;let i=0;
  while(i<n){
    const s=L[i];
    if(isBlank(s)){i++;continue}
    let m;
    if((m=s.match(RE.fence))){
      const ch=m[1][0],len=m[1].length,lang=m[2],buf=[];
      i++;
      while(i<n){
        const c=L[i].match(/^\s{0,3}(`{3,}|~{3,})\s*$/);
        if(c&&c[1][0]===ch&&c[1].length>=len){i++;break}
        buf.push(L[i]);i++;
      }
      out.push('<pre><code'+(lang?' class="lang-'+esc(lang)+'"':'')+'>'+esc(buf.join('\n'))+'</code></pre>');
      continue;
    }
    if((m=s.match(RE.h))){
      out.push('<h'+m[1].length+'>'+inline(m[2])+'</h'+m[1].length+'>');i++;continue;
    }
    if(RE.hr.test(s)){out.push('<hr>');i++;continue}
    if(RE.bq.test(s)){
      const buf=[];
      while(i<n&&RE.bq.test(L[i])){buf.push(L[i].replace(/^\s{0,3}>\s?/,''));i++}
      out.push('<blockquote>'+blocks(buf).join('')+'</blockquote>');continue;
    }
    if(tableStart(L,i)){const r=table(L,i);out.push(r[0]);i=r[1];continue}
    if(RE.li.test(s)){const r=list(L,i);out.push(r[0]);i=r[1];continue}
    const buf=[s];i++;
    while(i<n&&!isBlank(L[i])&&!startsBlock(L[i])&&!RE.li.test(L[i])&&!tableStart(L,i)){buf.push(L[i]);i++}
    out.push('<p>'+inline(buf.map(x=>x.trim()).join('\n'))+'</p>');
  }
  return out;
}

function render(src){
  ST=[];
  const L=String(src==null?'':src).replace(/\r\n?/g,'\n').replace(/\t/g,'    ').split('\n');
  const h=blocks(L).join('\n');
  ST=[];
  return h;
}

/* ชื่อเรื่องอัตโนมัติ: บรรทัดแรกที่มีเนื้อหา (ตัดสัญลักษณ์ markdown ออก) */
function titleOf(md){
  let fence=false;
  for(const raw of String(md||'').split(/\r?\n/)){
    if(/^\s*(```|~~~)/.test(raw)){fence=!fence;continue}
    if(fence||!raw.trim()||RE.hr.test(raw))continue;
    const t=raw.replace(/^\s*(#{1,6}\s+|>+\s*|[-*+]\s+(\[[ xX]\]\s+)?|\d+[.)]\s+)/,'')
      .replace(/!?\[([^\]]*)\]\([^)]*\)/g,'$1')
      .replace(/[*_~`]/g,'').trim();
    if(t)return t.length>80?t.slice(0,80)+'…':t;
  }
  return 'ไม่มีชื่อเรื่อง';
}

g.MD={render,titleOf,esc};
})(window);
