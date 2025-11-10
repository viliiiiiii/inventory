<?php
require_once __DIR__ . '/helpers.php';
require_login();

$counts = analytics_counts();

$priorityData = analytics_group("SELECT priority, COUNT(*) AS total FROM tasks GROUP BY priority");
$statusData   = analytics_group("SELECT status, COUNT(*) AS total FROM tasks GROUP BY status");
$assigneeData = analytics_group("SELECT COALESCE(NULLIF(TRIM(assigned_to), ''), 'Unassigned') AS label, COUNT(*) AS total
  FROM tasks GROUP BY label ORDER BY total DESC LIMIT 10");
$ageData = analytics_group("SELECT " . get_age_bucket_sql() . " AS bucket, COUNT(*) AS total FROM tasks t WHERE t.status <> 'done' GROUP BY bucket");
$buildingData = analytics_group("SELECT b.name AS label, COUNT(*) AS total FROM tasks t JOIN buildings b ON b.id = t.building_id GROUP BY b.id ORDER BY total DESC LIMIT 10");
$roomData = analytics_group("SELECT CONCAT(r.room_number, IF(r.label IS NULL OR r.label = '', '', CONCAT(' - ', r.label))) AS label,
  COUNT(*) AS total FROM tasks t JOIN rooms r ON r.id = t.room_id GROUP BY r.id ORDER BY total DESC LIMIT 10");

$title = 'Dashboard';
include __DIR__ . '/includes/header.php';
?>

<!-- ====== DASHBOARD STYLES (light, futuristic, mobile-first) ====== -->
<style>
:root{
  --ui-bg: #f7f9ff;
  --ui-card: #ffffff;
  --ui-line: #e8eef8;
  --ui-text: #0f172a;
  --ui-muted:#667085;
  --ui-primary:#335dff;
  --ui-primary-10:#ecf0ff;
  --ui-grad: linear-gradient(180deg,#ffffff 0%, #f7faff 100%);
}

.dash-wrap{ display:grid; gap:1rem; }
.kpi-grid{
  display:grid; gap:.8rem; grid-template-columns: 1fr 1fr;
}
@media (min-width:900px){ .kpi-grid{ grid-template-columns: repeat(4,1fr); } }

.kpi-card{
  position:relative; display:flex; flex-direction:column; gap:.35rem;
  padding:1rem; border-radius:16px; background:var(--ui-card);
  border:1px solid var(--ui-line);
  box-shadow: 0 1px 0 rgba(16,24,40,.04), 0 8px 20px rgba(2,6,23,.04);
  transition: transform .2s ease, box-shadow .2s ease;
  overflow:hidden;
}
.kpi-card:hover{ transform: translateY(-2px); box-shadow: 0 12px 24px rgba(2,6,23,.08); }
.kpi-card h2{ font-size:.95rem; font-weight:700; color:var(--ui-muted); letter-spacing:.01em; }
.kpi-number{ font-size:2rem; font-weight:800; color:var(--ui-text); line-height:1; }
.kpi-card::after{
  content:""; position:absolute; inset:-1px; border-radius:16px;
  padding:1px; background: conic-gradient(from 180deg, #8aa1ff, #69e3ff, #c6f6d5, #ffd479, #8aa1ff);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor; mask-composite: exclude; opacity:.18; pointer-events:none;
}

/* Card + chart layout */
.chart-grid{ display:grid; gap:1rem; }
@media (min-width:960px){ .chart-grid{ grid-template-columns: 1fr 1fr; } }

.chart-card{
  background:var(--ui-card); border:1px solid var(--ui-line); border-radius:16px;
  padding:1rem; box-shadow: 0 1px 0 rgba(16,24,40,.04), 0 8px 20px rgba(2,6,23,.04);
}
.chart-head{ display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:.5rem; }
.chart-head h2{ font-size:1rem; font-weight:800; color:var(--ui-text); }
.chart-legend{ display:flex; flex-wrap:wrap; gap:.4rem; }
.legend-item{
  display:inline-flex; align-items:center; gap:.5rem; padding:.25rem .5rem; border-radius:999px; font-size:.8rem;
  border:1px solid var(--ui-line); background:#fff; cursor:pointer; user-select:none;
}
.legend-swatch{ width:.9rem; height:.9rem; border-radius:3px; display:inline-block; }

.chart-frame{
  position:relative; height: 300px; /* default mobile height */
}
@media (min-width:1200px){ .chart-frame{ height: 340px; } }
canvas.chart{ width:100%; height:100%; display:block; }

/* Tooltip */
.chart-tooltip{
  position:fixed; z-index:9999; pointer-events:none; transform:translate(-50%, -115%);
  min-width: 120px; max-width: 280px;
  padding:.45rem .6rem; border-radius:10px; background:#111827; color:#fff; font-size:.8rem; line-height:1.25;
  box-shadow: 0 10px 25px rgba(0,0,0,.25);
  border:1px solid rgba(255,255,255,.08);
  opacity:0; transition:opacity .12s ease;
}
.chart-tooltip strong{ display:block; margin-bottom:.1rem; font-size:.82rem; }
.chart-tooltip.show{ opacity:1; }

/* Make grids breathe on wide screens */
.grid.two, .grid-2 { display:grid; gap:1rem; }
@media (min-width:900px){ .grid.two, .grid-2 { grid-template-columns: 1fr 1fr; } }

/* Improve section spacing to feel premium */
section.card{ background:var(--ui-grad); }
</style>

<section class="card">
  <h1>Dashboard</h1>
  <div class="kpi-grid">
    <a class="kpi-card" href="tasks.php?status=open">
      <h2>Open Tasks</h2>
      <p class="kpi-number"><?php echo number_format($counts['open']); ?></p>
    </a>
    <a class="kpi-card" href="tasks.php?status=done&created_from=<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
      <h2>Done (30d)</h2>
      <p class="kpi-number"><?php echo number_format($counts['done30']); ?></p>
    </a>
    <a class="kpi-card" href="tasks.php?due_from=<?php echo date('Y-m-d'); ?>&due_to=<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
      <h2>Due This Week</h2>
      <p class="kpi-number"><?php echo number_format($counts['dueWeek']); ?></p>
    </a>
    <a class="kpi-card" href="tasks.php?status=open&due_to=<?php echo date('Y-m-d', strtotime('-1 day')); ?>">
      <h2>Overdue</h2>
      <p class="kpi-number"><?php echo number_format($counts['overdue']); ?></p>
    </a>
  </div>
</section>

<div class="chart-grid">
  <section class="chart-card">
    <div class="chart-head">
      <h2>Tasks by Priority</h2>
      <div class="chart-legend" id="legend-priority"></div>
    </div>
    <div class="chart-frame">
      <canvas id="priorityChart" class="chart" data-kind="bar-v"
        data-chart='<?php echo json_encode($priorityData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>

  <section class="chart-card">
    <div class="chart-head">
      <h2>Tasks by Status</h2>
      <div class="chart-legend" id="legend-status"></div>
    </div>
    <div class="chart-frame">
      <canvas id="statusChart" class="chart" data-kind="donut"
        data-chart='<?php echo json_encode($statusData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>
</div>

<div class="chart-grid">
  <section class="chart-card">
    <div class="chart-head">
      <h2>Tasks by Assignee (Top 10)</h2>
      <div class="chart-legend" id="legend-assignee"></div>
    </div>
    <div class="chart-frame">
      <canvas id="assigneeChart" class="chart" data-kind="bar-h"
        data-chart='<?php echo json_encode($assigneeData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>

  <section class="chart-card">
    <div class="chart-head">
      <h2>Age of Open Tasks</h2>
      <div class="chart-legend" id="legend-age"></div>
    </div>
    <div class="chart-frame">
      <canvas id="ageChart" class="chart" data-kind="bar-v"
        data-chart='<?php echo json_encode($ageData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>
</div>

<div class="chart-grid">
  <section class="chart-card">
    <div class="chart-head">
      <h2>Tasks per Building</h2>
      <div class="chart-legend" id="legend-building"></div>
    </div>
    <div class="chart-frame">
      <canvas id="buildingChart" class="chart" data-kind="bar-h"
        data-chart='<?php echo json_encode($buildingData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>

  <section class="chart-card">
    <div class="chart-head">
      <h2>Tasks per Room (Top 10)</h2>
      <div class="chart-legend" id="legend-room"></div>
    </div>
    <div class="chart-frame">
      <canvas id="roomChart" class="chart" data-kind="bar-h"
        data-chart='<?php echo json_encode($roomData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>'></canvas>
    </div>
  </section>
</div>

<!-- Global chart tooltip -->
<div id="chartTooltip" class="chart-tooltip" aria-hidden="true"></div>

<script>
(function(){
  // ===== Palette (pleasant light mode) =====
  const PALETTE = [
    '#335dff', '#2d9d78', '#f59e0b', '#e11d48',
    '#06b6d4', '#7c3aed', '#ef4444', '#10b981',
    '#3b82f6', '#f97316'
  ];

  const tooltip = document.getElementById('chartTooltip');
  function showTip(x,y,title,value){
    tooltip.innerHTML = '<strong>'+title+'</strong>'+ value+'';
    tooltip.style.left = x+'px';
    tooltip.style.top  = y+'px';
    tooltip.classList.add('show');
    tooltip.setAttribute('aria-hidden','false');
  }
  function hideTip(){
    tooltip.classList.remove('show');
    tooltip.setAttribute('aria-hidden','true');
  }

  // ===== Canvas helpers (retina scaling) =====
  function fitCanvas(canvas){
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const {width, height} = canvas.getBoundingClientRect();
    canvas.width  = Math.round(width  * dpr);
    canvas.height = Math.round(height * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.clearRect(0,0,width,height);
    ctx.font = '12px ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial';
    ctx.textBaseline = 'middle';
    return {ctx, cssW: width, cssH: height};
  }

  function niceMax(max){
    if (max <= 5) return 5;
    if (max <= 10) return 10;
    if (max <= 25) return 25;
    if (max <= 50) return 50;
    if (max <= 100) return 100;
    return Math.ceil(max/100)*100;
  }

  // ===== Legends (only visual toggles for donut) =====
  function buildLegend(el, items, onToggle){
    if (!el) return;
    el.innerHTML = '';
    items.forEach((it, i) => {
      const li = document.createElement('button');
      li.type = 'button';
      li.className = 'legend-item';
      li.innerHTML = '<span class="legend-swatch" style="background:'+it.color+'"></span>' + (it.label||'');
      if (onToggle) {
        li.addEventListener('click', () => onToggle(i, li));
      }
      el.appendChild(li);
    });
  }

  // ===== Charts =====
  function drawBarV(canvas, rows){
    const {ctx, cssW:W, cssH:H} = fitCanvas(canvas);
    const values = rows.map(r => +r.total||0);
    const labels = rows.map(r => (r.label||r.priority||r.status||r.bucket||''));
    const maxVal = Math.max(1, ...values);
    const MAX = niceMax(maxVal);
    const pad = {t: 12, r: 12, b: 50, l: 40};
    // extra left if long y ticks
    if (MAX >= 1000) pad.l = 48;

    // grid
    ctx.strokeStyle = '#eef2ff';
    ctx.lineWidth = 1;
    const gridLines = 4;
    for (let i=0;i<=gridLines;i++){
      const y = pad.t + (H - pad.t - pad.b) * (i/gridLines);
      ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(W - pad.r, y); ctx.stroke();
      const tickVal = Math.round(MAX * (1 - i/gridLines));
      ctx.fillStyle = '#64748b';
      ctx.textAlign = 'right'; ctx.fillText(tickVal, pad.l - 6, y);
    }

    // bars
    const n = values.length || 1;
    const barSpace = (W - pad.l - pad.r) / n;
    const barW = Math.max(18, Math.min(48, barSpace * 0.6));
    const barGap = barSpace - barW;
    const shapes = [];

    for (let i=0;i<n;i++){
      const v = values[i];
      const h = (v / MAX) * (H - pad.t - pad.b);
      const x = pad.l + i*barSpace + barGap/2;
      const y = H - pad.b - h;

      const color = PALETTE[i % PALETTE.length];
      // bar glow
      ctx.fillStyle = color + '22';
      ctx.fillRect(x, y-4, barW, h+4);
      // bar
      ctx.fillStyle = color;
      ctx.fillRect(x, y, barW, h);

      // value label
      ctx.fillStyle = '#111827';
      ctx.textAlign = 'center';
      ctx.fillText(v, x + barW/2, y - 10);

      // x label (angled)
      ctx.save();
      ctx.translate(x + barW/2, H - pad.b + 16);
      ctx.rotate(-Math.PI/4);
      ctx.fillStyle = '#475569';
      ctx.fillText(labels[i], 0, 0);
      ctx.restore();

      shapes.push({type:'bar', x, y, w:barW, h, label: labels[i], value: v, color});
    }

    canvas.__shapes = shapes;
  }

  function drawBarH(canvas, rows){
    const {ctx, cssW:W, cssH:H} = fitCanvas(canvas);
    const values = rows.map(r => +r.total||0);
    const labels = rows.map(r => (r.label||''));
    const maxVal = Math.max(1, ...values);
    const MAX = niceMax(maxVal);
    // measure longest label
    ctx.font = '12px ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial';
    const longest = labels.reduce((m, s) => Math.max(m, ctx.measureText(s).width), 0);
    const pad = {t: 12, r: 16, b: 12, l: Math.min(220, Math.max(80, longest + 20))};

    // grid verticals
    ctx.strokeStyle = '#eef2ff';
    ctx.lineWidth = 1;
    const gridLines = 4;
    for (let i=0;i<=gridLines;i++){
      const x = pad.l + (W - pad.l - pad.r) * (i/gridLines);
      ctx.beginPath(); ctx.moveTo(x, pad.t); ctx.lineTo(x, H - pad.b); ctx.stroke();
      const tickVal = Math.round(MAX * (i/gridLines));
      ctx.fillStyle = '#64748b';
      ctx.textAlign = 'center'; ctx.fillText(tickVal, x, H - 4);
    }

    const n = values.length || 1;
    const rowSpace = (H - pad.t - pad.b) / n;
    const barH = Math.max(16, Math.min(28, rowSpace * 0.6));
    const barGap = Math.max(6, rowSpace - barH);
    const shapes = [];

    for (let i=0;i<n;i++){
      const v = values[i];
      const w = (v / MAX) * (W - pad.l - pad.r);
      const x = pad.l;
      const y = pad.t + i*(barH+barGap) + (barGap/2);

      const color = PALETTE[i % PALETTE.length];
      // shadow strip
      ctx.fillStyle = color + '22';
      ctx.fillRect(x, y-2, w+4, barH+4);
      // bar
      ctx.fillStyle = color;
      ctx.fillRect(x, y, w, barH);

      // label left
      ctx.fillStyle = '#0f172a'; ctx.textAlign = 'right';
      ctx.fillText(labels[i], pad.l - 8, y + barH/2);
      // value on bar
      ctx.fillStyle = '#111827'; ctx.textAlign = 'left';
      ctx.fillText(v, x + w + 8, y + barH/2);

      shapes.push({type:'bar', x, y, w, h:barH, label: labels[i], value: v, color});
    }

    canvas.__shapes = shapes;
  }

  function drawDonut(canvas, rows, legendEl){
    const {ctx, cssW:W, cssH:H} = fitCanvas(canvas);
    const total = rows.reduce((s,r)=> s + (+r.total||0), 0) || 1;
    const cx = W/2, cy = H/2;
    const R  = Math.min(W,H)/2 - 10;
    const r  = R * 0.62;

    // Build items with color + visibility (legend toggles)
    if (!canvas.__segments){
      canvas.__segments = rows.map((r,i)=>({label:(r.status||r.label||''), value:+r.total||0, color:PALETTE[i%PALETTE.length], on:true}));
      // legend
      buildLegend(legendEl, canvas.__segments, (i,btn)=>{
        canvas.__segments[i].on = !canvas.__segments[i].on;
        btn.style.opacity = canvas.__segments[i].on ? '1' : '.45';
        drawDonut(canvas, rows, legendEl);
      });
    }

    const segs = canvas.__segments;
    const activeTotal = segs.reduce((s,sg)=> sg.on ? s + sg.value : s, 0) || 1;

    ctx.clearRect(0,0,W,H);
    let start = -Math.PI/2;
    const shapes = [];
    segs.forEach((sg) => {
      if (!sg.on || sg.value<=0) return;
      const ang = (sg.value / activeTotal) * Math.PI*2;
      ctx.beginPath();
      ctx.moveTo(cx,cy);
      ctx.fillStyle = sg.color;
      ctx.arc(cx, cy, R, start, start+ang);
      ctx.closePath();
      ctx.fill();

      const mid = start + ang/2;
      const hitR = (R+r)/2;
      const hx = cx + Math.cos(mid)*hitR;
      const hy = cy + Math.sin(mid)*hitR;
      shapes.push({type:'arc', cx, cy, R, r, a0:start, a1:start+ang, label:sg.label, value:sg.value, color:sg.color, hx, hy});
      start += ang;
    });

    // donut hole
    ctx.save();
    ctx.globalCompositeOperation = 'destination-out';
    ctx.beginPath();
    ctx.arc(cx,cy,r,0,Math.PI*2);
    ctx.fill();
    ctx.restore();

    // center label
    ctx.fillStyle = '#0f172a';
    ctx.font = '700 14px ui-sans-serif, system-ui';
    ctx.textAlign = 'center';
    ctx.fillText(activeTotal + ' tasks', cx, cy);

    canvas.__shapes = shapes;
  }

  function hitTest(canvas, evt){
    const rect = canvas.getBoundingClientRect();
    const x = evt.clientX - rect.left, y = evt.clientY - rect.top;
    const shapes = canvas.__shapes || [];
    for (let i=0;i<shapes.length;i++){
      const s = shapes[i];
      if (s.type === 'bar'){
        if (x>=s.x && x<=s.x+s.w && y>=s.y && y<=s.y+s.h){
          return {shape:s, x:evt.clientX, y:evt.clientY};
        }
      } else if (s.type === 'arc'){
        const dx = (x - s.cx), dy = (y - s.cy);
        const dist = Math.sqrt(dx*dx+dy*dy);
        if (dist>=s.r && dist<=s.R){
          // angle from center
          let ang = Math.atan2(dy,dx);
          if (ang < -Math.PI/2) ang += Math.PI*2; // keep continuity
          const a0 = s.a0, a1 = s.a1;
          // normalize angles
          const norm = (k)=> (k< -Math.PI/2 ? k + Math.PI*2 : k);
          if (norm(ang) >= norm(a0) && norm(ang) <= norm(a1)){
            return {shape:s, x:evt.clientX, y:evt.clientY};
          }
        }
      }
    }
    return null;
  }

  function attachInteractions(canvas){
    canvas.addEventListener('mousemove', (e)=>{
      const hit = hitTest(canvas,e);
      if (hit){
        showTip(hit.x, hit.y, hit.shape.label, hit.shape.value);
        canvas.style.cursor = 'pointer';
      } else {
        hideTip();
        canvas.style.cursor = 'default';
      }
    });
    canvas.addEventListener('mouseleave', hideTip);
  }

  function normalizeRows(raw){
    return (raw||[]).map(r => ({
      label: (r.label || r.priority || r.status || r.bucket || ''),
      total: +r.total || 0
    }));
  }

  function drawAll(){
    document.querySelectorAll('canvas.chart[data-chart]').forEach(canvas=>{
      const kind = canvas.dataset.kind || 'bar-v';
      const data = normalizeRows(JSON.parse(canvas.dataset.chart||'[]'));
      const legendEl = document.getElementById('legend-' + (canvas.id||''));
      // reset per redraw
      if (kind !== 'donut'){ canvas.__segments = null; }
      if (kind === 'donut')      drawDonut(canvas, data, legendEl);
      else if (kind === 'bar-h') drawBarH(canvas, data);
      else                       drawBarV(canvas, data);
      if (!canvas.__wired){ attachInteractions(canvas); canvas.__wired = true; }
    });
  }

  drawAll();
  window.addEventListener('resize', drawAll);
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
