<?php
/**
 * upload_final_document.php
 * Handles multiple image uploads from mobile and combines them into a single image
 * Server-side processing is much lighter on mobile devices
 * Saves to archive storage with encryption for seamless archive preview
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/file_crypto.php';
require_once __DIR__ . '/archive_storage.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../firestore_client.php';

// Database connection
$connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($connection->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get parameters
$docId = isset($_POST['doc_id']) ? (int)$_POST['doc_id'] : 0;
$pageCount = isset($_POST['page_count']) ? (int)$_POST['page_count'] : 0;

if ($docId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid document ID']);
    exit;
}

// Check if files were uploaded
if (empty($_FILES)) {
    echo json_encode(['success' => false, 'error' => 'No files uploaded']);
    exit;
}

// Use archive upload directory for seamless archive preview
$uploadDir = archive_uploads_dir();
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Temp directory for processing
$tempDir = __DIR__ . '/../uploads/temp/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$uploadedFiles = [];
$totalSize = 0;

// Process uploaded files
foreach ($_FILES as $key => $fileData) {
    // Handle array format files[0], files[1], etc
    if (is_array($fileData['tmp_name'])) {
        for ($i = 0; $i < count($fileData['tmp_name']); $i++) {
            if ($fileData['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $fileData['tmp_name'][$i];
                $originalName = $fileData['name'][$i];
                $size = $fileData['size'][$i];
                
                // Save temporarily
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $ext = 'jpg';
                }
                $tempPath = $tempDir . 'temp_' . $docId . '_' . count($uploadedFiles) . '.' . $ext;
                
                if (move_uploaded_file($tmpName, $tempPath)) {
                    $uploadedFiles[] = $tempPath;
                    $totalSize += $size;
                }
            }
        }
    } else {
        // Single file format
        if ($fileData['error'] === UPLOAD_ERR_OK) {
            $tmpName = $fileData['tmp_name'];
            $originalName = $fileData['name'];
            $size = $fileData['size'];
            
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $ext = 'jpg';
            }
            $tempPath = $tempDir . 'temp_' . $docId . '_' . count($uploadedFiles) . '.' . $ext;
            
            if (move_uploaded_file($tmpName, $tempPath)) {
                $uploadedFiles[] = $tempPath;
                $totalSize += $size;
            }
        }
    }
}

if (empty($uploadedFiles)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded files']);
    exit;
}

// Save the count before any cleanup
$totalPagesUploaded = count($uploadedFiles);

// Determine final file format based on count
$timestamp = time();
$tempFinalPath = '';
$finalExt = '';
$finalSize = 0;

if ($totalPagesUploaded === 1) {
    // Single image - use it directly
    $tempFinalPath = $uploadedFiles[0];
    $finalExt = pathinfo($tempFinalPath, PATHINFO_EXTENSION);
} else {
    // Multiple images - combine into a single image (vertical stack)
    // Using simple vertical stacking for lightweight processing
    
    $images = [];
    $totalHeight = 0;
    $maxWidth = 0;
    
    foreach ($uploadedFiles as $filePath) {
        $imgInfo = @getimagesize($filePath);
        if ($imgInfo) {
            $w = $imgInfo[0];
            $h = $imgInfo[1];
            $type = $imgInfo[2];
            
            // Load image based on type
            $img = null;
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $img = @imagecreatefromjpeg($filePath);
                    break;
                case IMAGETYPE_PNG:
                    $img = @imagecreatefrompng($filePath);
                    break;
                case IMAGETYPE_GIF:
                    $img = @imagecreatefromgif($filePath);
                    break;
                case IMAGETYPE_WEBP:
                    $img = @imagecreatefromwebp($filePath);
                    break;
            }
            
            if ($img) {
                // Resize large images to max 1200px width for lighter file
                if ($w > 1200) {
                    $newW = 1200;
                    $newH = (int)($h * (1200 / $w));
                    $resized = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    imagedestroy($img);
                    $img = $resized;
                    $w = $newW;
                    $h = $newH;
                }
                
                $images[] = ['img' => $img, 'w' => $w, 'h' => $h];
                $totalHeight += $h + 20; // 20px spacing between pages
                if ($w > $maxWidth) $maxWidth = $w;
            }
        }
    }
    
    if (empty($images)) {
        // Cleanup temp files
        foreach ($uploadedFiles as $f) { @unlink($f); }
        echo json_encode(['success' => false, 'error' => 'Failed to process images']);
        exit;
    }
    
    // Create combined image
    $combined = imagecreatetruecolor($maxWidth, $totalHeight);
    $white = imagecolorallocate($combined, 255, 255, 255);
    imagefill($combined, 0, 0, $white);
    
    $yPos = 0;
    foreach ($images as $imgData) {
        // Center horizontally
        $xPos = (int)(($maxWidth - $imgData['w']) / 2);
        imagecopy($combined, $imgData['img'], $xPos, $yPos, 0, 0, $imgData['w'], $imgData['h']);
        imagedestroy($imgData['img']);
        $yPos += $imgData['h'] + 20;
    }
    
    // Save combined image as temp JPEG
    $finalExt = 'jpg';
    $tempFinalPath = $tempDir . 'combined_' . $docId . '_' . $timestamp . '.' . $finalExt;
    
    if (!imagejpeg($combined, $tempFinalPath, 85)) {
        imagedestroy($combined);
        // Cleanup temp files
        foreach ($uploadedFiles as $f) { @unlink($f); }
        echo json_encode(['success' => false, 'error' => 'Failed to create combined image']);
        exit;
    }
    imagedestroy($combined);
    
    // Cleanup original temp files (not the combined one)
    foreach ($uploadedFiles as $f) { @unlink($f); }
}

// Get file size before encryption
$finalSize = filesize($tempFinalPath);

// Delete any existing archive files for this document ID
archive_delete_existing_files($docId);

// Encrypt and save to archive storage format: {id}_{timestamp}.{ext}.enc
$encryptedPath = $uploadDir . '/' . $docId . '_' . $timestamp . '.' . $finalExt . '.enc';

if (!file_crypto_encrypt_stream_to_path($tempFinalPath, $encryptedPath)) {
    // Fallback: save unencrypted if encryption fails
    $plainPath = $uploadDir . '/' . $docId . '_' . $timestamp . '.' . $finalExt;
    if (!rename($tempFinalPath, $plainPath)) {
        @unlink($tempFinalPath);
        echo json_encode(['success' => false, 'error' => 'Failed to save final document']);
        exit;
    }
    $encryptedPath = $plainPath;
}

// Format file size
if ($finalSize < 1024) {
    $sizeStr = $finalSize . 'B';
} elseif ($finalSize < 1024 * 1024) {
    $sizeStr = number_format($finalSize / 1024, 1) . 'KB';
} else {
    $sizeStr = number_format($finalSize / (1024 * 1024), 1) . 'MB';
}

// Store relative path to the encrypted file (relative to archive storage)
// This will be used when the document is moved to archive table
$relativePath = 'uploads/archive/' . basename($encryptedPath);

$sql = "UPDATE tracking SET file_path = ?, file_size = ?, file_type_icon = ?, status = 'Completed' WHERE id = ?";
$stmt = $connection->prepare($sql);
$stmt->bind_param('sisi', $relativePath, $finalSize, $finalExt, $docId);

if ($stmt->execute()) {
    // Check if any row was actually updated
    $rowsAffected = $stmt->affected_rows;
    
    // Log to document_history
    $actorId = 0; // Mobile user
    $pageInfo = $totalPagesUploaded > 1 ? $totalPagesUploaded . ' pages combined' : '1 page';
    $notes = "Final document captured ($pageInfo) and marked Completed";
    
    $hist = $connection->prepare("INSERT INTO document_history (doc_id, action, actor_user_id, notes) VALUES (?, 'complete', ?, ?)");
    if ($hist) {
        $hist->bind_param('iis', $docId, $actorId, $notes);
        $hist->execute();
        $hist->close();
    }

    try {
        $syncRow = null;
        if ($syncStmt = $connection->prepare("SELECT id, type, employee_name, department, current_holder, end_location, status, file_path, file_type_icon, doc_hash, mobile_timestamp, date_submitted, created_at, routing_queue, route_step FROM tracking WHERE id = ? LIMIT 1")) {
            $syncStmt->bind_param('i', $docId);
            if ($syncStmt->execute()) {
                $syncRes = $syncStmt->get_result();
                $syncRow = $syncRes ? $syncRes->fetch_assoc() : null;
            }
            $syncStmt->close();
        }
        if ($syncRow && function_exists('firestore_upsert_tracking')) {
            firestore_upsert_tracking((string)$docId, [
                'id' => (int)$docId,
                'type' => (string)($syncRow['type'] ?? ''),
                'employee_name' => (string)($syncRow['employee_name'] ?? ''),
                'department' => (string)($syncRow['department'] ?? ''),
                'current_holder' => (string)($syncRow['current_holder'] ?? ''),
                'end_location' => (string)($syncRow['end_location'] ?? ''),
                'status' => (string)($syncRow['status'] ?? 'Completed'),
                'file_path' => (string)($syncRow['file_path'] ?? $relativePath),
                'file_type_icon' => (string)($syncRow['file_type_icon'] ?? $finalExt),
                'doc_hash' => (string)($syncRow['doc_hash'] ?? ''),
                'mobile_timestamp' => (string)($syncRow['mobile_timestamp'] ?? ''),
                'date_submitted' => (string)($syncRow['date_submitted'] ?? ''),
                'created_at' => (string)($syncRow['created_at'] ?? ''),
                'routing_queue' => (string)($syncRow['routing_queue'] ?? ''),
                'route_step' => (int)($syncRow['route_step'] ?? 0),
                'updatedAt' => (int)round(microtime(true) * 1000),
            ]);
        }
    } catch (Throwable $t) {
    }
    
    $stmt->close();
    $connection->close();
    
    if ($rowsAffected > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Document completed successfully (' . $totalPagesUploaded . ' page(s))',
            'file_path' => $relativePath,
            'file_size' => $sizeStr,
            'pages' => $totalPagesUploaded
        ]);
    } else {
        // No rows updated - document ID not found
        echo json_encode([
            'success' => false,
            'error' => 'Document ID ' . $docId . ' not found in tracking table'
        ]);
    }
} else {
    $stmt->close();
    $connection->close();
    
    // Delete the file if DB update failed
    @unlink($encryptedPath);
    
    echo json_encode(['success' => false, 'error' => 'Failed to update document record']);
}
