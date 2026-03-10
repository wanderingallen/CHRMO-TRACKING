<?php
// Content-only Stats page: no head/body/sidebar. Render within #main-content-swap.
?>
<div class="card" style="background:#fff;border-radius:12px;padding:20px;border:1px solid #eef3f7;box-shadow:0 8px 20px rgba(0,0,0,0.06);">
  <h3 style="margin:0 0 12px;color:#0e7490;display:flex;align-items:center;gap:8px;"><i class="fas fa-chart-line"></i> Predictive Document Volume</h3>
  <div style="height:320px">
    <canvas id="forecastChartStats" height="320"></canvas>
  </div>
  <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <button id="statsSrcBoth" class="btn-src" style="padding:6px 10px;border-radius:8px;border:1px solid #e0e0e0;background:#f8fbfd;cursor:pointer;">All</button>
    <button id="statsSrcTrack" class="btn-src" style="padding:6px 10px;border-radius:8px;border:1px solid #e0e0e0;background:#fff;cursor:pointer;">Active</button>
    <button id="statsSrcArch" class="btn-src" style="padding:6px 10px;border-radius:8px;border:1px solid #e0e0e0;background:#fff;cursor:pointer;">Archived</button>
  </div>
</div>

<script>
(function(){
  // Expose re-initializable charts for Stats page
  window.initStatsCharts = function initStatsCharts(){
    const el = document.getElementById('forecastChartStats');
    if (!el || !window.Chart) return;
    const ctx = el.getContext('2d');
    const grad = ctx.createLinearGradient(0,0,0,200);
    grad.addColorStop(0,'rgba(38,166,154,0.35)');
    grad.addColorStop(1,'rgba(38,166,154,0.05)');
    if (window.__statsForecastChart) { try { window.__statsForecastChart.destroy(); } catch(_){} }
    window.__statsForecastChart = new Chart(ctx, {
      type:'line',
      data:{ labels:[], datasets:[] },
      options:{
        responsive:true,
        plugins:{ legend:{ display:true }, tooltip:{ mode:'index', intersect:false } },
        scales:{ x:{ display:true, title:{display:true,text:'Date'} }, y:{ display:true, beginAtZero:true, title:{display:true,text:'Document Count'} } }
      }
    });

    function loadForecast(h=14, source='both'){
      fetch(`stats.php?action=predict_volume&h=${h}&source=${encodeURIComponent(source)}`)
        .then(r=>r.json())
        .then(d=>{
          const labels = d.labels.concat(d.forecast_labels);
          const actuals = d.actuals.concat(Array(d.forecast.length).fill(null));
          const forecast = Array(d.actuals.length).fill(null).concat(d.forecast);
          window.__statsForecastChart.data.labels = labels.map(s=>new Date(s).toLocaleDateString(undefined,{month:'short',day:'numeric'}));
          window.__statsForecastChart.data.datasets = [
            { label:'Actual', data: actuals, borderColor:'#0097A7', backgroundColor:'rgba(0,151,167,0.08)', fill:false, tension:0.35, pointRadius:2 },
            { label:'Forecast', data: forecast, borderColor:'#26A69A', backgroundColor: grad, fill:true, borderDash:[6,4], tension:0.35, pointRadius:2 }
          ];
          window.__statsForecastChart.update();
        }).catch(()=>{});
    }

    // Bind source buttons
    const btnBoth = document.getElementById('statsSrcBoth');
    const btnTrack = document.getElementById('statsSrcTrack');
    const btnArch = document.getElementById('statsSrcArch');
    [btnBoth, btnTrack, btnArch].forEach(btn=>{
      if (!btn) return;
      btn.addEventListener('click', ()=>{
        [btnBoth, btnTrack, btnArch].forEach(b=>{ if(b){ b.style.background = '#fff'; }});
        btn.style.background = '#f0faff';
        const source = btn===btnBoth? 'both' : (btn===btnTrack? 'tracking' : 'archive');
        loadForecast(14, source);
      });
    });

    // Initial load
    loadForecast(14, 'both');
  };

  // Auto-run if shell has just injected this content
  if (document.getElementById('forecastChartStats')) {
    if (typeof window.initStatsCharts === 'function') window.initStatsCharts();
  }
})();
</script>
