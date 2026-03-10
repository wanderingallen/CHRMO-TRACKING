<?php
/**
 * Quick Verification Checklist for Document Compilation
 * Run these checks to verify the system is working
 */

require_once '../config.php';
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

echo "=== Compilation System Verification ===\n\n";

$checks = [
    'Database Tables' => false,
    'API Endpoint' => false,
    'Helper Function' => false,
    'OCR Integration' => false
];

// Check 1: Tables
echo "[1] Database Tables:\n";
$tables = ['document_attachments', 'tracking', 'document_history'];
foreach ($tables as $table) {
    $result = $connection->query("SHOW TABLES LIKE '{$table}'");
    $exists = $result && $result->num_rows > 0;
    echo "  - {$table}: " . ($exists ? "✓" : "✗") . "\n";
}

// Check page_order column
echo "  - page_order column: ";
$result = $connection->query("SHOW COLUMNS FROM document_attachments LIKE 'page_order'");
echo ($result && $result->num_rows > 0 ? "✓" : "✗ (will use created_at)") . "\n\n";

// Check 2: API Endpoint
echo "[2] API Endpoint (document_actions.php):\n";
$apiFile = __DIR__ . '/document_actions.php';
$content = file_get_contents($apiFile);
$hasCompile = strpos($content, "'compile_document'") !== false;
echo "  - compile_document action: " . ($hasCompile ? "✓" : "✗") . "\n";
$hasMerge = strpos($content, 'pdftk') !== false || strpos($content, 'Ghostscript') !== false;
echo "  - PDF merge logic: " . ($hasMerge ? "✓" : "✗") . "\n";
$hasOcr = strpos($content, 'ocr_extract_text') !== false || strpos($content, 'pdftotext') !== false;
echo "  - OCR extraction: " . ($hasOcr ? "✓" : "✗") . "\n\n";

// Check 3: Helper Function in tracking.php
echo "[3] Helper Function (tracking.php):\n";
$trackingFile = __DIR__ . '/../tracking.php';
if (file_exists($trackingFile)) {
    $content = file_get_contents($trackingFile);
    $hasHelper = strpos($content, 'function compileDocumentAttachments') !== false;
    echo "  - compileDocumentAttachments(): " . ($hasHelper ? "✓" : "✗") . "\n";
    $hasIntegration = strpos($content, 'compile_attachments') !== false;
    echo "  - Integration in update_final_document: " . ($hasIntegration ? "✓" : "✗") . "\n";
} else {
    echo "  - tracking.php not found\n";
}
echo "\n";

// Check 4: Test with sample data (if available)
echo "[4] Sample Data Check:\n";
$result = $connection->query("SELECT id, file_path FROM tracking WHERE file_path IS NOT NULL LIMIT 1");
if ($result && $row = $result->fetch_assoc()) {
    echo "  - Sample tracking ID: {$row['id']}\n";
    echo "  - File exists: " . (file_exists(__DIR__ . '/../../' . $row['file_path']) ? "✓" : "✗") . "\n";
    
    // Check for attachments
    $attResult = $connection->query("SELECT COUNT(*) as cnt FROM document_attachments WHERE tracking_id = {$row['id']}");
    $attCount = $attResult ? $attResult->fetch_assoc()['cnt'] : 0;
    echo "  - Attachments: {$attCount}\n";
} else {
    echo "  - No documents found\n";
}

echo "\n=== Manual Test Instructions ===\n";
echo "1. Upload a document via tracking.php\n";
echo "2. Add attachments via the attachment feature\n";
echo "3. Call compile API:\n";
echo "   POST api/document_actions.php?action=compile_document\n";
echo "   Body: tracking_id=XXX&compiled_by=User&department=Dept\n";
echo "4. Check response for success and file_path\n";
echo "5. Verify OCR content includes compiled text\n";

$connection->close();
?>
