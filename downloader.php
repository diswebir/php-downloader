<?php
// downloader.php — Simple single-file PHP downloader with progress UI (RTL + Vazirmatn)
// Requirements: PHP with cURL enabled

// ---------- Helpers ----------
function respond_json($arr, $status=200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function valid_token($t) { return is_string($t) && preg_match('/^[a-f0-9]{16}$/', $t); }
function tmp_dir() {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}
function progress_path($token) {
    $dir = tmp_dir();
    return $dir . '/progress_' . $token . '.json';
}
function remove_dir_if_empty($dir) {
    if (!is_dir($dir)) return;
    $h = @opendir($dir);
    if (!$h) return;
    while (($e = readdir($h)) !== false) {
        if ($e === '.' || $e === '..') continue;
        closedir($h);
        return; // not empty
    }
    closedir($h);
    @rmdir($dir);
}
function sanitize_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim($name, '._');
    return $name !== '' ? $name : 'file';
}
function unique_filename($dir, $name) {
    $pi = pathinfo($name);
    $base = $pi['filename'] ?? 'file';
    $ext  = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
    $candidate = $base . $ext;
    $path = rtrim($dir, '/\\') . '/' . $candidate;
    $i = 1;
    while (file_exists($path)) {
        $candidate = $base . '_' . $i . $ext;
        $path = rtrim($dir, '/\\') . '/' . $candidate;
        $i++;
    }
    return [$path, $candidate];
}

// ---------- API endpoints ----------
if (isset($_GET['action'])) {
    $action = $_GET['action'];

    // Poll progress
    if ($action === 'progress') {
        $token = $_GET['token'] ?? '';
        if (!valid_token($token)) respond_json(['status'=>'error','message'=>'invalid token'], 400);
        $file = progress_path($token);
        if (!is_file($file)) respond_json(['status'=>'idle']);
        $content = @file_get_contents($file);
        if ($content === false) respond_json(['status'=>'idle']);
        header('Content-Type: application/json; charset=utf-8');
        echo $content;
        exit;
    }

    // Start download
    if ($action === 'download') {
        $url      = trim($_POST['url'] ?? '');
        $fnameIn  = trim($_POST['filename'] ?? '');
        $token    = $_POST['token'] ?? '';

        if (!valid_token($token)) respond_json(['status'=>'error','message'=>'invalid token'], 400);
        if (!filter_var($url, FILTER_VALIDATE_URL)) respond_json(['status'=>'error','message'=>'URL نامعتبر است'], 400);

        $parts  = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http','https'], true)) {
            respond_json(['status'=>'error','message'=>'فقط لینک‌های http/https مجاز هستند'], 400);
        }

        $dlDir = __DIR__ . '/downloads';
        if (!is_dir($dlDir)) @mkdir($dlDir, 0755, true);
        if (!is_dir($dlDir) || !is_writable($dlDir)) {
            respond_json(['status'=>'error','message'=>'پوشه downloads قابل نوشتن نیست'], 500);
        }

        $defaultName = basename($parts['path'] ?? '') ?: ('download_' . date('Ymd_His'));
        $filename    = sanitize_filename($fnameIn !== '' ? $fnameIn : $defaultName);
        [$savePath, $finalName] = unique_filename($dlDir, $filename);

        $progressFile = progress_path($token);
        @file_put_contents($progressFile, json_encode([
            'status'=>'starting','downloaded'=>0,'total'=>0,'percent'=>0,'speed'=>0,'filename'=>$finalName
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        if (!function_exists('curl_init')) {
            @file_put_contents($progressFile, json_encode(['status'=>'error','message'=>'افزونه cURL در PHP فعال نیست'], JSON_UNESCAPED_UNICODE), LOCK_EX);
            respond_json(['status'=>'error','message'=>'cURL extension missing'], 500);
        }

        $fp = @fopen($savePath, 'wb');
        if (!$fp) {
            @file_put_contents($progressFile, json_encode(['status'=>'error','message'=>'عدم دسترسی برای ساخت فایل روی سرور'], JSON_UNESCAPED_UNICODE), LOCK_EX);
            respond_json(['status'=>'error','message'=>'cannot open file'], 500);
        }

        set_time_limit(0);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SimplePHPDownloader/1.0');
        // اگر خطای SSL گرفتید و مطمئنید لینک معتبر است، این خط را (با ریسک امنیتی) فعال کنید:
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $startTime  = microtime(true);
        $lastUpdate = 0.0;
        $progressCb = function($resource, $download_size, $downloaded, $upload_size)
            use ($progressFile, $startTime, &$lastUpdate, $finalName) {
            $now = microtime(true);
            if (($now - $lastUpdate) >= 0.25) {
                $lastUpdate = $now;
                $percent = $download_size > 0 ? round(($downloaded / $download_size) * 100, 1) : null;
                $speed   = $downloaded > 0 ? (int) round($downloaded / max($now - $startTime, 0.001)) : 0;
                @file_put_contents($progressFile, json_encode([
                    'status'=>'downloading',
                    'downloaded'=>$downloaded,
                    'total'=>$download_size,
                    'percent'=>$percent,
                    'speed'=>$speed,
                    'filename'=>$finalName
                ]), LOCK_EX);
            }
            return 0;
        };
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, $progressCb);

        $ok   = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $http >= 400) {
            @unlink($savePath);
            $msg = $err ?: ('HTTP ' . $http);
            @file_put_contents($progressFile, json_encode(['status'=>'error','message'=>$msg,'filename'=>$finalName], JSON_UNESCAPED_UNICODE), LOCK_EX);
            respond_json(['status'=>'error','message'=>$msg], 500);
        }

        $size = @filesize($savePath) ?: 0;
        @file_put_contents($progressFile, json_encode([
            'status'=>'done',
            'downloaded'=>$size,
            'total'=>$size,
            'percent'=>100,
            'speed'=>0,
            'filename'=>$finalName,
            'relative'=>'downloads/' . rawurlencode($finalName)
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);

        respond_json(['status'=>'ok','file'=>'downloads/'.$finalName]);
    }

    // Cleanup tmp after finish
    if ($action === 'cleanup') {
        $token = $_POST['token'] ?? $_GET['token'] ?? '';
        if (valid_token($token)) {
            $file = progress_path($token);
            if (is_file($file)) @unlink($file);
        }
        // اگر پوشه خالی بود پاکش کن
        remove_dir_if_empty(__DIR__ . '/tmp');
        respond_json(['status'=>'ok']);
    }
    exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>دانلودر ساده PHP</title>

<!-- Vazirmatn font (Google Fonts) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;700&display=swap" rel="stylesheet">

<style>
    :root { color-scheme: light dark; }
    html, body { height: 100%; }
    body {
        font-family: "Vazirmatn", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif;
        background:#f6f8fa; margin:0; padding:24px; line-height:1.7;
    }
    .card { max-width:680px; margin:24px auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,.04); }
    h1 { font-size:20px; margin:0 0 14px; font-weight:700; }
    .row { display:flex; gap:8px; margin:8px 0; }
    input[type=text] { flex:1; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; font-family: inherit; }
    button { padding:10px 16px; font-size:14px; border:0; border-radius:8px; background:#2563eb; color:#fff; cursor:pointer; font-family: inherit; }
    button:disabled { opacity:.6; cursor:not-allowed; }
    .help { color:#6b7280; font-size:12px; margin-top:2px; }
    .bar { background:#e5e7eb; border-radius:999px; height:10px; overflow:hidden; margin-top:10px; }
    .fill { background:#10b981; height:100%; width:0%; transition:width .2s ease; }
    .stats { font-size:13px; color:#374151; margin-top:8px; display:flex; gap:12px; flex-wrap:wrap; }
    .status { margin-top:8px; font-size:13px; color:#374151; }
    .link { margin-top:10px; }
    a { color:#2563eb; text-decoration:none; }
    a:hover { text-decoration:underline; }
</style>
</head>
<body>
<div class="card">
    <h1>دانلود فایل به هاست</h1>
    <form id="dl-form">
        <div class="row">
            <input id="url" type="text" placeholder="مثال: https://example.com/file.zip" required>
        </div>
        <div class="row">
            <input id="filename" type="text" placeholder="نام فایل خروجی (اختیاری)">
            <button id="startBtn" type="submit">دانلود</button>
        </div>
        <div class="help">فایل‌ها در پوشه downloads ذخیره می‌شوند. لینک باید http/https باشد.</div>
    </form>

    <div id="progressArea" style="display:none;">
        <div class="bar"><div class="fill" id="barFill"></div></div>
        <div class="stats">
            <div id="pcent">0%</div>
            <div id="sizes">0 / 0</div>
            <div id="speed">0 KB/s</div>
            <div id="eta">ETA —</div>
        </div>
        <div class="status" id="statusText">در حال آماده‌سازی…</div>
        <div class="link" id="doneLink"></div>
    </div>
</div>

<script>
(function(){
    const form = document.getElementById('dl-form');
    const urlEl = document.getElementById('url');
    const fnameEl = document.getElementById('filename');
    const btn = document.getElementById('startBtn');

    const area = document.getElementById('progressArea');
    const barFill = document.getElementById('barFill');
    const pcent = document.getElementById('pcent');
    const sizes = document.getElementById('sizes');
    const speed = document.getElementById('speed');
    const eta = document.getElementById('eta');
    const statusText = document.getElementById('statusText');
    const doneLink = document.getElementById('doneLink');

    function token16() {
        const a = new Uint8Array(8);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.from(a).map(b => b.toString(16).padStart(2,'0')).join('');
    }
    function humanBytes(b) {
        const u = ['B','KB','MB','GB','TB']; let i=0, x = Number(b)||0;
        while (x >= 1024 && i < u.length-1) { x/=1024; i++; }
        return (i>=2 ? x.toFixed(2) : (i===1 ? x.toFixed(1) : x.toFixed(0))) + ' ' + u[i];
    }
    function humanTime(sec) {
        sec = Math.max(0, Math.floor(sec));
        const m = Math.floor(sec/60), s = sec%60;
        if (m>0) return m+'m '+s+'s';
        return s+'s';
    }

    let pollTimer = null;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const link = urlEl.value.trim();
        if (!link) return;
        btn.disabled = true;
        urlEl.disabled = true;
        fnameEl.disabled = true;
        doneLink.innerHTML = '';
        area.style.display = '';
        statusText.textContent = 'در حال شروع...';
        barFill.style.width = '0%';
        pcent.textContent = '0%';
        sizes.textContent = '0 / 0';
        speed.textContent = '0 KB/s';
        eta.textContent = 'ETA —';

        const token = token16();

        // شروع دانلود (در پس‌زمینه)
        const fd = new FormData();
        fd.append('url', link);
        if (fnameEl.value.trim()) fd.append('filename', fnameEl.value.trim());
        fd.append('token', token);

        // حین دانلود، پیشرفت را پول می‌کنیم
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(async ()=>{
            try {
                const res = await fetch('?action=progress&token='+token+'&_=' + Date.now());
                if (!res.ok) return;
                const data = await res.json();

                if (data.status === 'idle' || data.status === 'starting') {
                    statusText.textContent = 'در حال اتصال...';
                    return;
                }
                if (data.status === 'downloading') {
                    const total = Number(data.total)||0, downloaded = Number(data.downloaded)||0;
                    const pct = data.percent != null ? data.percent : (total>0 ? (downloaded/total*100) : 0);
                    const spd = Number(data.speed)||0;
                    barFill.style.width = Math.min(100, pct).toFixed(1) + '%';
                    pcent.textContent = (data.percent != null ? data.percent : '—') + '%';
                    sizes.textContent = humanBytes(downloaded) + ' / ' + (total ? humanBytes(total) : 'نامشخص');
                    speed.textContent = (spd ? humanBytes(spd) : '0 B') + '/s';
                    if (total && spd>0) {
                        const rem = (total - downloaded)/spd;
                        eta.textContent = 'ETA ' + humanTime(rem);
                    } else {
                        eta.textContent = 'ETA —';
                    }
                    statusText.textContent = 'در حال دانلود...';
                }
                if (data.status === 'error') {
                    clearInterval(pollTimer);
                    statusText.textContent = 'خطا: ' + (data.message || 'نامشخص');
                    btn.disabled = false; urlEl.disabled = false; fnameEl.disabled = false;
                    // تمیزکاری احتیاطی
                    fetch('?action=cleanup', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:'token='+encodeURIComponent(token) });
                }
            } catch (e) { /* سکوت؛ تلاش بعدی */ }
        }, 600);

        // دانلود را آغاز کن و منتظر اتمامش بمان (برای گرفتن نتیجه نهایی و تمیزکاری)
        fetch('?action=download', { method: 'POST', body: fd })
          .then(async res => {
              let data = {};
              try { data = await res.json(); } catch(e) {}
              if (res.ok && data.status === 'ok') {
                  clearInterval(pollTimer);
                  barFill.style.width = '100%';
                  pcent.textContent = '100%';
                  speed.textContent = 'تمام شد';
                  eta.textContent = 'ETA 0s';
                  statusText.textContent = 'دانلود با موفقیت انجام شد.';
                  const href = data.file;
                  sizes.textContent = '—';
                  doneLink.innerHTML = 'فایل ذخیره شد: <a href="'+href+'" target="_blank" rel="noopener">'+href+'</a>';

                  // پاکسازی tmp پس از اتمام موفق
                  fetch('?action=cleanup', {
                      method:'POST',
                      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                      body:'token='+encodeURIComponent(token)
                  });

                  btn.disabled = false; urlEl.disabled = false; fnameEl.disabled = false;
              } else {
                  clearInterval(pollTimer);
                  statusText.textContent = 'خطا در دانلود' + (data.message ? (' — ' + data.message) : '');
                  btn.disabled = false; urlEl.disabled = false; fnameEl.disabled = false;
                  // تمیزکاری احتیاطی
                  fetch('?action=cleanup', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:'token='+encodeURIComponent(token) });
              }
          })
          .catch(() => {
              // اگر درخواست اصلی شکست خورد، پولینگ احتمالاً وضعیت را نشان می‌دهد؛
              // اما بهتر است بعد از چند ثانیه خطا نشان دهیم یا دکمه را فعال کنیم.
          });
    });
})();
</script>
</body>
</html>
