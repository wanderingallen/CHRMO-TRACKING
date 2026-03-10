<?php
// Prefer rendering content from the existing page for structure
$src = __DIR__ . '/dashboard.php';
function __extract_main_from_file($file){
  ob_start(); include $file; $html = ob_get_clean(); $html = trim($html);
  if (preg_match('/<div\s+class=\"([^\"]*\bmain-content\b[^\"]*)\"[^>]*>([\s\S]*?)<\/div>/i',$html,$m)) return $m[2];
  if (preg_match('/<main[^>]*>([\s\S]*?)<\/main>/i',$html,$m)) return $m[1];
  if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i',$html,$m)) return $m[1];
  return '<div style=\"padding:20px\">Dashboard content not found</div>';
}
echo __extract_main_from_file($src);

// Compute datasets needed if the original page didn't embed them into JS
require_once __DIR__ . '/config.php';
$recentLabels = $recentCounts = $deptLabels = $deptCounts = $deptColors = [];
$pendingReviewCount = 0;
try {
  $connection = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if (!$connection->connect_error) {
    // Recent last 7 days (robust date parsing for VARCHAR columns)
    $today = new DateTime();
    $dateMap7 = [];
    for ($i = 6; $i >= 0; $i--) { $d = clone $today; $d->modify("-$i day"); $key = $d->format('Y-m-d'); $dateMap7[$key] = 0; }

    // Fetch raw dates without relying on MySQL DATE() (handles VARCHAR dates)
    $sql_dates = "SELECT created_at, date_submitted FROM tracking";
    if ($res = $connection->query($sql_dates)) {
      while ($row = $res->fetch_assoc()) {
        $raw = $row['created_at'];
        if (!$raw || trim($raw) === '') { $raw = $row['date_submitted']; }
        if (!$raw || trim($raw) === '') { continue; }
        $ts = null;
        $candidates = [
          'Y-m-d H:i:s','Y-m-d','m/d/Y','m/d/Y H:i:s','d/m/Y','d-m-Y','M j, Y','F j, Y','D, M j, Y','Y/m/d'
        ];
        foreach ($candidates as $fmt) {
          $dt = DateTime::createFromFormat($fmt, trim($raw));
          if ($dt) { $ts = $dt; break; }
        }
        if (!$ts) {
          // Last resort: strtotime
          $t = @strtotime($raw);
          if ($t) { $ts = (new DateTime())->setTimestamp($t); }
        }
        if ($ts) {
          $key = $ts->format('Y-m-d');
          if (isset($dateMap7[$key])) { $dateMap7[$key]++; }
        }
      }
      $res->free();
    }

    $sum7 = array_sum($dateMap7);
    // If the last 7 days are all zero, fallback to last 30 days and show that range
    if ($sum7 === 0) {
      $dateMap30 = [];
      for ($i = 29; $i >= 0; $i--) { $d = clone $today; $d->modify("-$i day"); $key = $d->format('Y-m-d'); $dateMap30[$key] = 0; }
      if ($res2 = $connection->query($sql_dates)) {
        while ($row = $res2->fetch_assoc()) {
          $raw = $row['created_at'];
          if (!$raw || trim($raw) === '') { $raw = $row['date_submitted']; }
          if (!$raw || trim($raw) === '') { continue; }
          $ts = null;
          $candidates = ['Y-m-d H:i:s','Y-m-d','m/d/Y','m/d/Y H:i:s','d/m/Y','d-m-Y','M j, Y','F j, Y','D, M j, Y','Y/m/d'];
          foreach ($candidates as $fmt) { $dt = DateTime::createFromFormat($fmt, trim($raw)); if ($dt) { $ts = $dt; break; } }
          if (!$ts) { $t = @strtotime($raw); if ($t) { $ts = (new DateTime())->setTimestamp($t); } }
          if ($ts) { $key = $ts->format('Y-m-d'); if (isset($dateMap30[$key])) { $dateMap30[$key]++; } }
        }
        $res2->free();
      }
      foreach ($dateMap30 as $dateStr => $count) { $dt = DateTime::createFromFormat('Y-m-d', $dateStr); $recentLabels[] = $dt ? $dt->format('M j') : $dateStr; $recentCounts[] = (int)$count; }
    } else {
      foreach ($dateMap7 as $dateStr => $count) { $dt = DateTime::createFromFormat('Y-m-d', $dateStr); $recentLabels[] = $dt ? $dt->format('M j') : $dateStr; $recentCounts[] = (int)$count; }
    }

    // Absolute fallback: if still all zeros but there are documents Pending/In Review, show them on today as a visible bar
    $totalRecent = array_sum($recentCounts);
    // Always compute Pending+In Review for JS fallbacks
    $sql_pr = "SELECT COUNT(*) AS c FROM tracking WHERE LOWER(status) IN ('pending','in review')";
    if ($res3 = $connection->query($sql_pr)) { if ($row = $res3->fetch_assoc()) { $pendingReviewCount = (int)$row['c']; } $res3->free(); }
    if ($totalRecent === 0 && $pendingReviewCount > 0 && count($recentCounts) > 0) {
      $recentCounts[count($recentCounts)-1] = $pendingReviewCount; // put today’s bar
    }

    // Department distribution current month
    $colorPalette = ['#00BCD4', '#26A69A', '#FFB300', '#8E24AA', '#FF7043', '#E91E63', '#9C27B0', '#3F51B5', '#009688', '#FF5722'];
    $sql_dept = "SELECT UPPER(TRIM(department)) AS department, COUNT(*) as c FROM tracking WHERE department IS NOT NULL AND TRIM(department) <> '' GROUP BY UPPER(TRIM(department)) ORDER BY c DESC";
    if ($res = $connection->query($sql_dept)) { $idx=0; while ($row = $res->fetch_assoc()) { $dept = strtoupper(trim($row['department'])); if ($dept==='') continue; $deptLabels[]=$dept; $deptCounts[]=(int)$row['c']; $deptColors[]=$colorPalette[$idx % count($colorPalette)]; $idx++; } $res->free(); }
  }
} catch (Throwable $e) { /* ignore and let JS handle empty */ }
?>
<script>
(function(){
  const PHP_recentLabels = <?php echo json_encode($recentLabels); ?>;
  const PHP_recentCounts = <?php echo json_encode($recentCounts); ?>;
  const PHP_deptLabels = <?php echo json_encode($deptLabels); ?>;
  const PHP_deptCounts = <?php echo json_encode($deptCounts); ?>;
  const PHP_deptColors = <?php echo json_encode($deptColors); ?>;
  const PHP_pendingReview = <?php echo (int)$pendingReviewCount; ?>;

  window.initDashboardCharts = function initDashboardCharts(){
    if (!window.Chart) return;
    const recentCanvas = document.getElementById('recentBarChart');
    if (recentCanvas) {
      // Ensure visible height regardless of container sizing
      try { recentCanvas.height = 260; recentCanvas.style.height = '260px'; } catch(_){}
      const ctx = recentCanvas.getContext('2d');
      const grad = ctx.createLinearGradient(0,0,0,240);
      grad.addColorStop(0,'rgba(0,188,212,0.9)');
      grad.addColorStop(1,'rgba(0,188,212,0.15)');
      let labels = Array.isArray(PHP_recentLabels) ? PHP_recentLabels.slice() : [];
      let counts = Array.isArray(PHP_recentCounts) ? PHP_recentCounts.slice() : [];
      // If no labels, seed last 7 days labels
      if (labels.length === 0) {
        const now = new Date();
        const pad = n => String(n).padStart(2,'0');
        const names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        for (let i=6;i>=0;i--){
          const d = new Date(now.getFullYear(), now.getMonth(), now.getDate()-i);
          labels.push(`${names[d.getMonth()]} ${d.getDate()}`);
        }
        counts = new Array(labels.length).fill(0);
      }
      // If all zero and we do have pending/review, put it on today
      if (counts.length && counts.every(v => (v||0) === 0) && PHP_pendingReview > 0) {
        counts[counts.length-1] = PHP_pendingReview;
      }
      if (window.__recentBarChart) { try { window.__recentBarChart.destroy(); } catch(_){} }
      window.__recentBarChart = new Chart(ctx, {
        type: 'bar',
        data: { labels, datasets: [{ label:'Documents Uploaded', data: counts, backgroundColor: grad, borderColor:'rgba(0,151,167,1)', borderWidth:2, borderRadius:10, hoverBackgroundColor:'rgba(0,188,212,1)', maxBarThickness: 42 }] },
        options: { responsive:true, maintainAspectRatio:false, animation:{ duration:500 }, plugins:{ legend:{ display:false } }, scales:{ x:{ grid:{display:false} }, y:{ beginAtZero:true, grid:{ color:'#ECEFF1' } } } }
      });
    }

    const pieCanvas = document.getElementById('docPieChart');
    if (pieCanvas) {
      const labels = PHP_deptLabels || [];
      const counts = PHP_deptCounts || [];
      const colors = PHP_deptColors || [];
      if (window.__docPieChart) { try { window.__docPieChart.destroy(); } catch(_){} }
      window.__docPieChart = new Chart(pieCanvas.getContext('2d'), {
        type: 'doughnut',
        data: { labels, datasets: [{ data: counts, backgroundColor: colors, borderColor:'#fff', borderWidth:2, hoverOffset:8 }] },
        options: { responsive:true, plugins:{ legend:{ display:true, position:'bottom' } } }
      });
    }
  };

  // Auto-run after content injection
  if (document.getElementById('recentBarChart')) {
    if (typeof window.initDashboardCharts === 'function') window.initDashboardCharts();
  }
})();
</script>
