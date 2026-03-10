<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo json_encode([
  'ok' => true,
  'app' => 'CHRMO Document Tracking',
  'path' => '/lib/OCR(UPDATED)/api',
  'time' => date('c'),
]);
