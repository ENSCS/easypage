<?php
// ========================================
// easypage/render.php — แปลง Markdown → หน้าเว็บ (ใช้ร่วมกันโดย view.php และ preview.php)
// ขั้นตอน/หน้าตาเหมือน ai/mdparse/mdview.php (Parsedown + KaTeX + ปุ่มธีม/ขนาดตัวอักษร + คลิก pre เพื่อคัดลอก)
// สไตล์: mdview.css (สำเนาของ mdparse/style.css)
// ========================================
require_once __DIR__ . '/parsedown.php';

/** แปลง markdown → array(html, hasMath, title) */
function ep_render($content)
{
    $content = (string)$content;

    // สูตรคณิตศาสตร์ ($...$ และ $$...$$) — พักไว้ก่อนแปลง กัน Parsedown แปลง _ \\ * ในสูตร แล้วใส่กลับ (KaTeX วาดฝั่งเบราว์เซอร์)
    $mathParts = array();
    $stashMath = function ($m) use (&$mathParts) {
        $mathParts[] = $m[0];
        return 'MATHSLOT' . (count($mathParts) - 1) . 'X';
    };
    $content = preg_replace_callback('/\$\$.+?\$\$/s', $stashMath, $content);
    $content = preg_replace_callback('/(?<![\\\$])\$(?=\S)([^$\n]+?)(?<=\S)\$(?![\d\$])/', $stashMath, $content);

    $p = new Parsedown();
    $p->setBreaksEnabled(true);
    $p->setSafeMode(true);   // ไม่รัน HTML/JS ที่แทรกมาใน markdown (หน้าที่เผยแพร่ได้โดยไม่ต้องล็อกอิน) + กรองลิงก์ javascript:
    $html = $p->text($content);

    if ($mathParts) {
        $html = preg_replace_callback('/MATHSLOT(\d+)X/', function ($m) use ($mathParts) {
            return isset($mathParts[(int)$m[1]]) ? htmlspecialchars($mathParts[(int)$m[1]], ENT_NOQUOTES, 'UTF-8') : $m[0];
        }, $html);
    }

    // ทำ URL เปล่าให้เป็นลิงก์ — เฉพาะนอก <pre>/<code> (ไม่แตะ href="..." / src="..." เดิม)
    $parts = preg_split('/(<pre\b.*?<\/pre>|<code\b.*?<\/code>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $seg) {
        if ($i % 2 === 1) continue;   // ส่วนที่เป็นโค้ด
        // https://xxx , http://xxx
        $seg = preg_replace(
            '/(?<!href=")(?<!src=")(https?:\/\/[^\s<>"\')\]]+)(?![^<]*<\/a>)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $seg
        );
        // www.xxx.com (ไม่มี http) → ลิงก์ https:// (ไม่แตะที่อยู่ใน href/src, ในลิงก์เดิม หรือหลัง https://)
        $seg = preg_replace_callback(
            '/(?<![\w\/@.="\'\-])(www\.[A-Za-z0-9][^\s<>"\')\]]*)(?![^<]*<\/a>)/i',
            function ($m) {
                $u = $m[1];
                $tail = '';
                while ($u !== '' && strpos('.,;:!?', substr($u, -1)) !== false) {   // ตัดเครื่องหมายท้ายประโยค
                    $tail = substr($u, -1) . $tail;
                    $u = substr($u, 0, -1);
                }
                if (!preg_match('/^www\.[^\s.\/?#]+\.[^\s.\/?#]/i', $u)) return $m[0];   // ต้องมีโดเมนจริง เช่น www.a.com
                return '<a href="https://' . $u . '" target="_blank" rel="noopener noreferrer">' . $u . '</a>' . $tail;
            },
            $seg
        );
        $parts[$i] = $seg;
    }
    $html = implode('', $parts);

    // ห่อตารางด้วย .table-wrap ให้ scroll ได้เฉพาะตัวตาราง
    $html = preg_replace('/<table>/i', '<div class="table-wrap"><table>', $html);
    $html = preg_replace('/<\/table>/i', '</table></div>', $html);

    // เพิ่ม id ให้ heading ทุกตัว สำหรับ anchor link
    $html = preg_replace_callback('/<(h[1-6])>(.*?)<\/h[1-6]>/is', function ($m) {
        $id = strip_tags($m[2]);
        $id = preg_replace('/\s*—\s*/u', '--', $id);
        $id = preg_replace('/[.?!,()\[\]{}"\']/u', '', $id);
        $id = mb_strtolower($id, 'UTF-8');
        $id = preg_replace('/\s+/u', '-', trim($id));
        return '<' . $m[1] . ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . $m[2] . '</' . $m[1] . '>';
    }, $html);

    // title = บรรทัดแรกที่ไม่ว่าง
    $title = 'Easypage';
    foreach (explode("\n", $content) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $t = trim(ltrim($line, '#'));
            if ($t !== '') $title = $t;
            break;
        }
    }

    return array($html, (bool)$mathParts, $title);
}

/** ประกอบหน้า HTML เต็ม (หน้าอ่าน / preview ใช้เหมือนกัน) */
/** $bare = true → ไม่มีปุ่มธีม/ขนาดตัวอักษร แสดงเฉพาะเนื้อหา (ใช้กับ preview) */
function ep_page($html, $hasMath, $title, $bare = false)
{
    $v = @filemtime(__DIR__ . '/mdview.css');
    ob_start();
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($title); ?></title>
<link rel="stylesheet" href="mdview.css?v=<?php echo (int)$v; ?>">
<?php if ($bare): ?>
<style>.container{padding-top:1.1rem;padding-bottom:2rem}.container>:first-child{margin-top:0}</style>
<?php endif; ?>
<?php if ($hasMath): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/katex.min.css">
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/katex.min.js"></script>
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/KaTeX/0.16.9/contrib/auto-render.min.js"></script>
<?php endif; ?>
</head>
<body>
<div class="container">
<?php if (!$bare): ?>
<div class="theme-wrap">
  <button class="font-btn" id="fontDown">A−</button>
  <button class="font-btn" id="fontUp">A+</button>
  <button class="theme-toggle" id="themeToggle">🌙 Dark</button>
</div>
<?php endif; ?>
<?php echo $html; ?>
</div>
<div class="copy-toast" id="copyToast" role="status"></div>
<script>
(function() {
  var BARE = <?php echo $bare ? 'true' : 'false'; ?>;   // preview ในหน้าเขียน: ขนาดตัวอักษรปกติเสมอ ไม่ตามค่า A+/A− ของหน้าอ่าน
  var btn = document.getElementById('themeToggle');
  var fontUp = document.getElementById('fontUp');
  var fontDown = document.getElementById('fontDown');
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function load(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }

  // --- Theme ---
  var theme = load('theme');
  if (!theme) {
    theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    if (btn) btn.textContent = t === 'dark' ? '☀️ Light' : '🌙 Dark';
    store('theme', t);
  }
  applyTheme(theme);
  if (btn) btn.addEventListener('click', function() {
    applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
  });

  // --- Font Size ---
  var SIZES = [14, 16, 18, 20, 22, 24, 26, 30, 36];   // ขนาดตัวอักษรที่เลือกได้ (px) เริ่มต้น 18
  var fontSize = BARE ? 18 : (parseInt(load('fontSize')) || 18);
  function applyFontSize(size) {
    fontSize = Math.min(SIZES[SIZES.length - 1], Math.max(SIZES[0], size));
    document.body.style.fontSize = fontSize + 'px';
    if (!BARE) store('fontSize', fontSize);
  }
  applyFontSize(fontSize);
  function stepFont(dir) {
    var next = fontSize;
    if (dir > 0) { next = SIZES.filter(function(z) { return z > fontSize; })[0] || fontSize; }
    else { var s = SIZES.filter(function(z) { return z < fontSize; }); next = s.length ? s[s.length - 1] : fontSize; }
    applyFontSize(next);
  }
  if (fontUp) fontUp.addEventListener('click', function() { stepFont(1); });
  if (fontDown) fontDown.addEventListener('click', function() { stepFont(-1); });

  // --- คีย์ลัด: + / = ขยาย, - ย่อ (ไม่ทำงานตอนพิมพ์ในช่องกรอก หรือกด Ctrl/Cmd) ---
  if (!BARE) document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
    if (e.key === '+' || e.key === '=') { stepFont(1); e.preventDefault(); }
    else if (e.key === '-' || e.key === '_') { stepFont(-1); e.preventDefault(); }
  });

  // --- Links: เปิดแท็บใหม่ (ยกเว้น #anchor ในหน้าเดียวกัน) ---
  document.querySelectorAll('.container a').forEach(function(a) {
    if (a.getAttribute('href') && a.getAttribute('href').indexOf('#') === 0) return;
    a.setAttribute('target', '_blank');
    a.setAttribute('rel', 'noopener noreferrer');
  });

  // --- กดที่กล่องโค้ด (pre) = คัดลอก ---
  var toastEl = document.getElementById('copyToast');
  var toastTimer;
  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { toastEl.classList.remove('show'); }, 1500);
  }
  function copyText(text, done) {
    function legacy() {
      var ok = false, ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;font-size:16px';
      document.body.appendChild(ta);
      ta.select(); ta.setSelectionRange(0, text.length);
      try { ok = document.execCommand('copy'); } catch (e) {}
      ta.remove();
      done(ok);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function() { done(true); }, legacy);
    } else {
      legacy();
    }
  }
  document.querySelectorAll('.container pre').forEach(function(pre) {
    pre.classList.add('copyable');
    pre.setAttribute('title', 'คลิกเพื่อคัดลอก');
    pre.addEventListener('click', function() {
      var sel = window.getSelection();
      if (sel && String(sel).length > 0) return;   // กำลังลากคลุมเอง ไม่ต้องคัดลอกทั้งกล่อง
      copyText(pre.innerText.replace(/\n$/, ''), function(ok) { toast(ok ? 'คัดลอกแล้ว' : 'คัดลอกไม่สำเร็จ'); });
    });
  });
})();
</script>
<?php if ($hasMath): ?>
<script>
// วาดสูตร $...$ / $$...$$ ด้วย KaTeX (ข้ามในกล่องโค้ด)
window.addEventListener('load', function() {
  if (typeof renderMathInElement !== 'function') return;
  renderMathInElement(document.querySelector('.container'), {
    delimiters: [
      {left: '$$', right: '$$', display: true},
      {left: '$', right: '$', display: false}
    ],
    throwOnError: false
  });
});
</script>
<?php endif; ?>
</body>
</html>
<?php
    return ob_get_clean();
}

/** หน้าแจ้งสถานะ (ปิดการเผยแพร่ / ไม่พบ / ลิงก์ไม่ถูกต้อง) — ข้อความสุภาพ อ่านง่าย รองรับ Dark อัตโนมัติ */
function ep_notice($icon, $title, $text, $hint = '', $id = '')
{
    $v = @filemtime(__DIR__ . '/mdview.css');
    ob_start();
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo htmlspecialchars($title); ?></title>
<link rel="stylesheet" href="mdview.css?v=<?php echo (int)$v; ?>">
<style>
.notice{max-width:26em;margin:0 auto;padding:14vh 1.5rem 4rem;text-align:center}
.notice .ico{font-size:3.4rem;line-height:1;margin-bottom:.4em}
.notice h1{font-size:1.7em;margin:0 0 .5em;line-height:1.4}
.notice p{margin:.6em 0}
.notice .hint{color:var(--muted);font-size:.9em;margin-top:1.4em}
.notice .code{display:inline-block;margin-top:1.8em;padding:.15em .7em;border:1px solid var(--border);border-radius:999px;color:var(--muted);font-size:.8em;font-family:"SFMono-Regular",Consolas,monospace}
</style>
<script>
(function(){var t;try{t=localStorage.getItem('theme')}catch(e){}
if(!t)t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';
document.documentElement.setAttribute('data-theme',t);})();
</script>
</head>
<body>
<main class="notice">
  <div class="ico" aria-hidden="true"><?php echo $icon; ?></div>
  <h1><?php echo htmlspecialchars($title); ?></h1>
  <p><?php echo htmlspecialchars($text); ?></p>
<?php if ($hint !== ''): ?>
  <p class="hint"><?php echo htmlspecialchars($hint); ?></p>
<?php endif; ?>
<?php if ($id !== ''): ?>
  <div class="code">?=<?php echo htmlspecialchars($id); ?></div>
<?php endif; ?>
</main>
</body>
</html>
<?php
    return ob_get_clean();
}
