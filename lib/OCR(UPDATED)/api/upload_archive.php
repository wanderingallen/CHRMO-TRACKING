<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/file_crypto.php';
require_once __DIR__ . '/archive_storage.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Only POST allowed');

    // Basic validation
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    $type = isset($_POST['type']) ? trim($_POST['type']) : 'Scanned';
    
    // Encryption metadata
    $is_encrypted = isset($_POST['is_encrypted']) ? filter_var($_POST['is_encrypted'], FILTER_VALIDATE_BOOLEAN) : false;
    $original_filename = isset($_POST['original_filename']) ? trim($_POST['original_filename']) : '';
    $encrypted_filename = isset($_POST['encrypted_filename']) ? trim($_POST['encrypted_filename']) : '';
    $encryption_metadata = isset($_POST['encryption_metadata']) ? $_POST['encryption_metadata'] : '';
    
    $size = '';
    $file_type_icon = 'file';

    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        throw new Exception('No file uploaded');
    }

    $origName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $bytes = (int)$_FILES['file']['size'];
    if ($bytes < 1024) $size = $bytes . 'B';
    elseif ($bytes < 1024*1024) $size = number_format($bytes/1024,1) . 'KB';
    else $size = number_format($bytes/(1024*1024),1) . 'MB';

    // Determine file type based on original filename if available, otherwise uploaded filename
    $typeExt = strtolower(pathinfo($original_filename ?: $origName, PATHINFO_EXTENSION));
    if ($typeExt === 'pdf') $file_type_icon = 'pdf';
    elseif (in_array($typeExt, ['doc','docx'])) $file_type_icon = 'doc';
    elseif (in_array($typeExt, ['xls','xlsx'])) $file_type_icon = 'xls';
    elseif (in_array($typeExt, ['png','jpg','jpeg'])) $file_type_icon = $typeExt;
    elseif ($typeExt === 'txt') $file_type_icon = 'txt';
    elseif ($typeExt === 'enc') {
        // For encrypted files, try to infer original type
        $inferredExt = file_crypto_infer_original_extension($original_filename ?: $origName);
        if ($inferredExt === 'pdf') $file_type_icon = 'pdf';
        elseif (in_array($inferredExt, ['doc','docx'])) $file_type_icon = 'doc';
        elseif (in_array($inferredExt, ['xls','xlsx'])) $file_type_icon = 'xls';
        elseif (in_array($inferredExt, ['png','jpg','jpeg'])) $file_type_icon = $inferredExt;
        elseif ($inferredExt === 'txt') $file_type_icon = 'txt';
    }

    // Handle file storage based on encryption status
    $uploadDir = __DIR__ . '/../uploads/archive';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    
    if ($is_encrypted) {
        // File is already encrypted on client side, store as-is
        $finalFilename = $encrypted_filename ?: $origName;
        $target = $uploadDir . '/' . $finalFilename;
        
        // Move the pre-encrypted file
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            throw new Exception('Failed to store encrypted file');
        }
        
        // Store encryption metadata in a separate file for future reference
        if ($encryption_metadata) {
            $metadataFile = $uploadDir . '/' . pathinfo($finalFilename, PATHINFO_FILENAME) . '_metadata.json';
            file_put_contents($metadataFile, $encryption_metadata);
        }
        
        error_log("🔐 Stored pre-encrypted file: $finalFilename (original: $original_filename)");
    } else {
        // Encrypt file server-side for backward compatibility
        $baseName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($origName, PATHINFO_BASENAME));
        if ($baseName === '' || $baseName === null) {
            $baseName = 'document.' . $ext;
        }
        // Use a time-based unique prefix so filenames remain distinct without DB ids
        $prefix = 'm' . time() . '_' . mt_rand(1000, 9999);
        $encName = $prefix . '_' . $baseName . '.enc';
        $target = $uploadDir . '/' . $encName;
        
        if (!file_crypto_encrypt_stream_to_path($_FILES['file']['tmp_name'], $target)) {
            throw new Exception('Failed to encrypt uploaded file');
        }
        
        error_log("🔐 Server-side encrypted file: $encName");
    }

    echo json_encode([
        'success' => true, 
        'file' => basename($target),
        'is_encrypted' => $is_encrypted,
        'original_filename' => $original_filename,
        'file_type_icon' => $file_type_icon,
        'size' => $size
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
