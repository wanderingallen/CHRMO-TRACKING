<?php
// Content-only wrapper for Archive page
$src = __DIR__ . '/archive.php';
function __extract_main_from_file($file){
  ob_start(); include $file; $html = ob_get_clean(); $html = trim($html);
  if (preg_match('/<div\s+class=\"([^\"]*\bmain-content\b[^\"]*)\"[^>]*>([\s\S]*?)<\/div>/i',$html,$m)) return $m[2];
  if (preg_match('/<main[^>]*>([\s\S]*?)<\/main>/i',$html,$m)) return $m[1];
  if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i',$html,$m)) return $m[1];
  return '<div style="padding:20px">Archive content not found</div>';
}
echo __extract_main_from_file($src);
?>
<script>
window.initArchivePage = window.initArchivePage || function(){
  if (typeof window.loadSidebarBadges === 'function') window.loadSidebarBadges();
};
</script>
