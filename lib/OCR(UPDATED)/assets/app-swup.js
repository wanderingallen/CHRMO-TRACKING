(function(){
  'use strict';

  const root = document;

  function qs(sel, ctx=document){ return ctx.querySelector(sel); }
  function qsa(sel, ctx=document){ return Array.from(ctx.querySelectorAll(sel)); }

  function currentPageFromURL(url){
    try {
      const u = new URL(url, window.location.origin);
      return (u.searchParams.get('page') || 'dashboard').toLowerCase();
    } catch { return 'dashboard'; }
  }

  async function swapTo(url, push=true){
    const target = qs('#main-content-swap');
    if (!target) { window.location.href = url; return; }
    document.body.classList.add('no-anim');
    target.classList.add('swapping-out');
    try {
      const u = new URL(url, window.location.origin);
      u.searchParams.set('contentOnly','1');
      const res = await fetch(u.toString(), { cache: 'no-store' });
      const html = await res.text();
      // Swap
      target.innerHTML = html;
      target.setAttribute('data-page', currentPageFromURL(url));
      // Update heading
      const heading = qs('.top-bar h2');
      if (heading) heading.textContent = capitalize(currentPageFromURL(url));
      // Update active in sidebar
      const page = currentPageFromURL(url);
      qsa('.sidebar .menu-item').forEach(a=>{
        const is = (a.getAttribute('href')||'').includes('page='+page);
        a.classList.toggle('active', is);
      });
      // Re-run common initializers
      runCommonInitializers();
      // Run page-specific inits
      runPageInits(page);
      // Normalize card animations for this page to avoid double flicker
      if (typeof window.normalizeCardAnimations === 'function') {
        window.normalizeCardAnimations(page);
      }
      // Update badges
      if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
      if (push) window.history.pushState({url: url}, '', url);
    } catch (e) {
      console.error('Swap failed, navigating normally', e);
      window.location.href = url;
    } finally {
      requestAnimationFrame(()=>{
        const t = qs('#main-content-swap');
        if (t){
          t.classList.remove('swapping-out');
          t.classList.add('swapping-in');
          setTimeout(()=>{
            t.classList.remove('swapping-in');
            document.body.classList.remove('no-anim');
          }, 320);
        }
      });
    }
  }

  function runCommonInitializers(){
    // Notifications
    if (typeof window.loadNotifications === 'function') window.loadNotifications();
    // Page-enter effect for main
    const main = qs('#main-content-swap .page-enter');
    if (main) main.classList.remove('page-enter');
  }

  function runPageInits(page){
    // Charts and page-specific logic
    if (page === 'dashboard') {
      attemptInit(() => window.initDashboardCharts, () => window.Chart, () => window.initDashboardCharts());
    }
    if (page === 'stats') {
      attemptInit(() => window.initStatsCharts, () => window.Chart, () => window.initStatsCharts(), tryInitStatsFallback);
    }
    if (page === 'tracking' && typeof window.initTrackingPage === 'function') {
      window.initTrackingPage();
    }
    if (page === 'archive' && typeof window.initArchivePage === 'function') {
      window.initArchivePage();
    }
    if (page === 'settings' && typeof window.initSettingsPage === 'function') {
      window.initSettingsPage();
    }
    if (page === 'usercontrol' && typeof window.initUserControlPage === 'function') {
      window.initUserControlPage();
    }
  }

  function attemptInit(hasInitFn, hasDependency, initCall, fallback){
    let tries = 0;
    const maxTries = 12; // ~1.2s
    const tick = () => {
      const readyFn = typeof hasInitFn === 'function' ? hasInitFn() : null;
      const readyDep = typeof hasDependency === 'function' ? hasDependency() : true;
      if (readyFn && readyDep) {
        try { initCall(); } catch(e) { console.error('Init failed', e); }
      } else if (tries++ < maxTries) {
        setTimeout(tick, 100);
      } else if (typeof fallback === 'function') {
        try { fallback(); } catch(_){}
      }
    };
    tick();
  }

  function tryInitStatsFallback(){
    const canvas = qs('#forecastChartStats');
    if (!canvas || !window.Chart) return;
    const ctx = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0,0,0,200);
    grad.addColorStop(0,'rgba(38,166,154,0.35)');
    grad.addColorStop(1,'rgba(38,166,154,0.05)');
    const chart = new Chart(ctx, {
      type:'line',
      data:{ labels:[], datasets:[] },
      options:{ responsive:true, plugins:{ legend:{display:true} }, scales:{ x:{display:true}, y:{beginAtZero:true} } }
    });
    fetch('stats.php?action=predict_volume&h=14&source=both').then(r=>r.json()).then(d=>{
      const labels = d.labels.concat(d.forecast_labels);
      const actuals = d.actuals.concat(Array(d.forecast.length).fill(null));
      const forecast = Array(d.actuals.length).fill(null).concat(d.forecast);
      chart.data.labels = labels.map(s=>new Date(s).toLocaleDateString(undefined,{month:'short',day:'numeric'}));
      chart.data.datasets = [
        { label:'Actual', data:actuals, borderColor:'#0097A7', backgroundColor:'rgba(0,151,167,0.08)', fill:false, tension:0.35, pointRadius:2 },
        { label:'Forecast', data:forecast, borderColor:'#26A69A', backgroundColor:grad, fill:true, borderDash:[6,4], tension:0.35, pointRadius:2 }
      ];
      chart.update();
    }).catch(()=>{});
  }

  function capitalize(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : s; }

  // Intercept sidebar clicks
  root.addEventListener('click', (e)=>{
    const a = e.target.closest('.sidebar .menu-item');
    if (!a) return;
    const href = a.getAttribute('href')||'';
    if (!href.includes('layout_shell.php')) return; // only handle shell links
    e.preventDefault();
    swapTo(href, true);
  });

  // Handle back/forward
  window.addEventListener('popstate', (e)=>{
    const url = (e.state && e.state.url) || window.location.href;
    swapTo(url, false);
  });

  // Initial common init
  document.addEventListener('DOMContentLoaded', ()=>{
    runCommonInitializers();
    const page = qs('#main-content-swap')?.getAttribute('data-page') || currentPageFromURL(window.location.href);
    runPageInits(page);
    if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
  });
})();
