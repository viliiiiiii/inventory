<?php
require_once __DIR__ . '/../helpers.php';
require_login();
$title = 'Notifications Debug';
include __DIR__ . '/../includes/header.php';
?>
<section class="card">
  <div class="card-header">
    <h1>Notifications Debug</h1>
    <div class="actions">
      <a class="btn" href="/index.php">Back</a>
    </div>
  </div>

  <p class="muted">This page listens to <code>/notifications/stream.php</code> and prints anything it receives.</p>

  <pre id="log" style="height: 320px; overflow:auto; background:#0b1020; color:#d7e3ff; padding:10px; border-radius:8px;"></pre>

  <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap">
    <button id="btnTest" class="btn">Send test notification</button>
    <button id="btnCount" class="btn secondary">Fetch unread count</button>
    <button id="btnRead" class="btn secondary">Mark all read</button>
  </div>
</section>

<script>
(function(){
  const logEl = document.getElementById('log');
  function log(line){ logEl.textContent += line + "\n"; logEl.scrollTop = logEl.scrollHeight; }

  // Connect SSE
  const es = new EventSource('/notifications/stream.php');
  es.addEventListener('open',  () => log('[SSE] connected'));
  es.addEventListener('error', () => log('[SSE] error (server closed or network?)'));
  es.addEventListener('ping',  (e) => log('[SSE] ping ' + (e.data || '')));
  es.addEventListener('notify',(e) => log('[notify] ' + e.data));
  es.onmessage = (e) => log('[message] ' + e.data); // default channel

  // Buttons
  document.getElementById('btnTest')?.addEventListener('click', async () => {
    const r = await fetch('/notifications/test.php', {credentials:'same-origin'});
    log('[POST] test -> ' + (r.ok ? 'OK' : r.status));
  });
  document.getElementById('btnCount')?.addEventListener('click', async () => {
    const r = await fetch('/notifications/unread_count.php', {credentials:'same-origin'});
    log('[GET] unread_count -> ' + (r.ok ? await r.text() : r.status));
  });
  document.getElementById('btnRead')?.addEventListener('click', async () => {
    const r = await fetch('/notifications/mark_all_read.php', {method:'POST', credentials:'same-origin'});
    log('[POST] mark_all_read -> ' + (r.ok ? await r.text() : r.status));
  });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php';
