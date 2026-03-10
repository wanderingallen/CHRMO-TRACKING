<?php
// Content-only wrapper for Settings page
$src = __DIR__ . '/settings.php';
function __extract_main_from_file($file){
  ob_start(); include $file; $html = ob_get_clean(); $html = trim($html);
  if (preg_match('/<div\s+class=\"([^\"]*\bmain-content\b[^\"]*)\"[^>]*>([\s\S]*?)<\/div>/i',$html,$m)) return $m[2];
  if (preg_match('/<main[^>]*>([\s\S]*?)<\/main>/i',$html,$m)) return $m[1];
  if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i',$html,$m)) return $m[1];
  return '<div style="padding:20px">Settings content not found</div>';
}
echo __extract_main_from_file($src);
?>
<script>
window.initSettingsPage = window.initSettingsPage || function(){
  // If settings page has any dynamic widgets (chips, forms), re-bind here as needed.
};
</script>
