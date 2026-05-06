<?php
/**
 * Database ↔ Firestore Synchronization Service
 * 
 * Ensures tracking documents in MySQL are properly synced to Firebase Firestore (chrmo)
 * Provides diagnostics, verification, and repair functionality
 * 
 * Usage: firestore_sync.php?action=verify_single&tracking_id=123
 * Usage: firestore_sync.php?action=fix&tracking_id=123
 * Usage: firestore_sync.php?action=fix_all&limit=100
 * Usage: firestore_sync.php?action=stats
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../firestore_client.php';

function sync_json($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

// ===================================================================
// ACTION: Verify sync status of a single document
// ===================================================================
if (isset($_GET['action']) && $_GET['action'] === 'verify_single') {
    $tracking_id = (int)($_GET['tracking_id'] ?? 0);
    if ($tracking_id <= 0) {
        sync_json(['success' => false, 'error' => 'Invalid tracking_id'], 400);
    }

    if (!isset($conn) || $conn->connect_error) {
        sync_json(['success' => false, 'error' => 'Database connection failed'], 500);
    }

    try {
        // Get SQL data
        $stmt = $conn->prepare("SELECT 
            id, type, employee_name, department, current_holder, end_location, 
            status, file_path, file_type_icon, doc_hash, mobile_timestamp, 
            ocr_content, date_submitted, created_at, batch_id
            FROM tracking WHERE id = ? LIMIT 1");
        
        if (!$stmt) {
            sync_json(['success' => false, 'error' => 'Query prepare failed'], 500);
        }

        $stmt->bind_param('i', $tracking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $sql_doc = $result->fetch_assoc();
        $stmt->close();

        if (!$sql_doc) {
            sync_json(['success' => false, 'error' => 'Document not found in SQL'], 404);
        }

        // Fetch from Firestore REST API (check if exists)
        // For now, return SQL data with sync recommendation
        sync_json([
            'success' => true,
            'tracking_id' => $tracking_id,
            'sql_document' => $sql_doc,
            'recommendation' => 'Document exists in SQL. Check Firebase console to verify Firestore sync.',
            'next_action' => 'If missing in Firestore, call: firestore_sync.php?action=fix&tracking_id=' . $tracking_id,
        ]);

    } catch (Throwable $e) {
        sync_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ===================================================================
// ACTION: Fix/re-sync a single document to Firestore
// ===================================================================
if (isset($_GET['action']) && $_GET['action'] === 'fix') {
    $tracking_id = (int)($_GET['tracking_id'] ?? 0);
    if ($tracking_id <= 0) {
        sync_json(['success' => false, 'error' => 'Invalid tracking_id'], 400);
    }

    if (!isset($conn) || $conn->connect_error) {
        sync_json(['success' => false, 'error' => 'Database connection failed'], 500);
    }

    try {
        // Get SQL data
        $stmt = $conn->prepare("SELECT 
            id, type, employee_name, department, current_holder, end_location, 
            status, file_path, file_type_icon, doc_hash, mobile_timestamp, 
            ocr_content, date_submitted, created_at, batch_id
            FROM tracking WHERE id = ? LIMIT 1");
        
        if (!$stmt) {
            sync_json(['success' => false, 'error' => 'Query prepare failed'], 500);
        }

        $stmt->bind_param('i', $tracking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $sql_doc = $result->fetch_assoc();
        $stmt->close();

        if (!$sql_doc) {
            sync_json(['success' => false, 'error' => 'Document not found in SQL'], 404);
        }

        // Sync to Firestore
        if (!function_exists('firestore_upsert_tracking')) {
            sync_json(['success' => false, 'error' => 'Firestore client not loaded'], 500);
        }

        $syncSuccess = firestore_upsert_tracking((string)$tracking_id, [
            'id' => (string)$tracking_id,
            'type' => $sql_doc['type'] ?? '',
            'employee_name' => $sql_doc['employee_name'] ?? '',
            'department' => $sql_doc['department'] ?? '',
            'current_holder' => $sql_doc['current_holder'] ?? '',
            'end_location' => $sql_doc['end_location'] ?? '',
            'status' => $sql_doc['status'] ?? 'Pending',
            'file_path' => $sql_doc['file_path'] ?? '',
            'file_type_icon' => $sql_doc['file_type_icon'] ?? '',
            'doc_hash' => $sql_doc['doc_hash'] ?? '',
            'mobile_timestamp' => $sql_doc['mobile_timestamp'] ?? '',
            'ocr_content' => $sql_doc['ocr_content'] ?? '',
            'date_submitted' => $sql_doc['date_submitted'] ?? '',
            'created_at' => $sql_doc['created_at'] ?? date('c'),
            'batch_id' => $sql_doc['batch_id'] ?? '',
        ]);

        if ($syncSuccess) {
            sync_json([
                'success' => true,
                'message' => 'Document synced to Firestore',
                'tracking_id' => $tracking_id,
            ]);
        } else {
            sync_json([
                'success' => false,
                'error' => 'Firestore sync failed - check logs',
                'tracking_id' => $tracking_id,
            ], 500);
        }

    } catch (Throwable $e) {
        sync_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ===================================================================
// ACTION: Batch fix - re-sync all documents (or recent ones)
// ===================================================================
if (isset($_GET['action']) && $_GET['action'] === 'fix_all') {
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
    $days_back = (int)($_GET['days'] ?? 7);

    if (!isset($conn) || $conn->connect_error) {
        sync_json(['success' => false, 'error' => 'Database connection failed'], 500);
    }

    try {
        $since_date = date('Y-m-d H:i:s', strtotime("-$days_back days"));
        
        $stmt = $conn->prepare("SELECT 
            id, type, employee_name, department, current_holder, end_location, 
            status, file_path, file_type_icon, doc_hash, mobile_timestamp, 
            ocr_content, date_submitted, created_at, batch_id
            FROM tracking 
            WHERE created_at >= ?
            ORDER BY created_at DESC
            LIMIT ?");
        
        if (!$stmt) {
            sync_json(['success' => false, 'error' => 'Query prepare failed'], 500);
        }

        $stmt->bind_param('si', $since_date, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $docs = [];
        while ($row = $result->fetch_assoc()) {
            $docs[] = $row;
        }
        $stmt->close();

        $total = count($docs);
        $synced = 0;
        $failed = 0;
        $errors = [];

        if (!function_exists('firestore_upsert_tracking')) {
            sync_json(['success' => false, 'error' => 'Firestore client not loaded'], 500);
        }

        foreach ($docs as $sql_doc) {
            try {
                $syncSuccess = firestore_upsert_tracking((string)$sql_doc['id'], [
                    'id' => (string)$sql_doc['id'],
                    'type' => $sql_doc['type'] ?? '',
                    'employee_name' => $sql_doc['employee_name'] ?? '',
                    'department' => $sql_doc['department'] ?? '',
                    'current_holder' => $sql_doc['current_holder'] ?? '',
                    'end_location' => $sql_doc['end_location'] ?? '',
                    'status' => $sql_doc['status'] ?? 'Pending',
                    'file_path' => $sql_doc['file_path'] ?? '',
                    'file_type_icon' => $sql_doc['file_type_icon'] ?? '',
                    'doc_hash' => $sql_doc['doc_hash'] ?? '',
                    'mobile_timestamp' => $sql_doc['mobile_timestamp'] ?? '',
                    'ocr_content' => $sql_doc['ocr_content'] ?? '',
                    'date_submitted' => $sql_doc['date_submitted'] ?? '',
                    'created_at' => $sql_doc['created_at'] ?? date('c'),
                    'batch_id' => $sql_doc['batch_id'] ?? '',
                ]);

                if ($syncSuccess) {
                    $synced++;
                } else {
                    $failed++;
                    $errors[] = 'ID: ' . $sql_doc['id'];
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'ID: ' . $sql_doc['id'] . ' - ' . $e->getMessage();
            }
        }

        sync_json([
            'success' => $failed === 0,
            'message' => "Sync completed: $synced/$total documents synced",
            'total_documents' => $total,
            'synced_count' => $synced,
            'failed_count' => $failed,
            'since_date' => $since_date,
            'errors' => array_slice($errors, 0, 10), // Show first 10 errors
        ]);

    } catch (Throwable $e) {
        sync_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ===================================================================
// ACTION: Get sync statistics
// ===================================================================
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    if (!isset($conn) || $conn->connect_error) {
        sync_json(['success' => false, 'error' => 'Database connection failed'], 500);
    }

    try {
        // Count documents in SQL
        $result = $conn->query("SELECT COUNT(*) as total FROM tracking");
        $row = $result->fetch_assoc();
        $sql_count = (int)($row['total'] ?? 0);
        $result->free();

        // Count by status
        $result = $conn->query("SELECT status, COUNT(*) as count FROM tracking GROUP BY status");
        $status_breakdown = [];
        while ($row = $result->fetch_assoc()) {
            $status_breakdown[$row['status']] = (int)$row['count'];
        }
        $result->free();

        // Count by department
        $result = $conn->query("SELECT department, COUNT(*) as count FROM tracking GROUP BY department ORDER BY count DESC LIMIT 10");
        $dept_breakdown = [];
        while ($row = $result->fetch_assoc()) {
            $dept_breakdown[$row['department']] = (int)$row['count'];
        }
        $result->free();

        // Recent documents
        $result = $conn->query("SELECT id, employee_name, type, status, created_at FROM tracking ORDER BY created_at DESC LIMIT 5");
        $recent = [];
        while ($row = $result->fetch_assoc()) {
            $recent[] = $row;
        }
        $result->free();

        sync_json([
            'success' => true,
            'sql_database_stats' => [
                'total_documents' => $sql_count,
                'by_status' => $status_breakdown,
                'by_department' => $dept_breakdown,
                'recent_documents' => $recent,
            ],
            'sync_information' => [
                'firestore_sync_enabled' => function_exists('firestore_upsert_tracking'),
                'last_sync_note' => 'Check Firebase console for exact Firestore document count',
                'sync_endpoint' => 'firestore_sync.php?action=fix_all&limit=100',
            ],
        ]);

    } catch (Throwable $e) {
        sync_json(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// ===================================================================
// Default: Show available actions
// ===================================================================
sync_json([
    'success' => true,
    'message' => 'Firestore Sync Service - Available Actions',
    'endpoints' => [
        'verify_single' => [
            'description' => 'Verify if a document exists in SQL',
            'url' => 'firestore_sync.php?action=verify_single&tracking_id=123',
        ],
        'fix' => [
            'description' => 'Re-sync a single document to Firestore',
            'url' => 'firestore_sync.php?action=fix&tracking_id=123',
        ],
        'fix_all' => [
            'description' => 'Batch re-sync documents from last N days',
            'url' => 'firestore_sync.php?action=fix_all&limit=100&days=7',
            'parameters' => [
                'limit' => 'Max documents to sync (default 100, max 500)',
                'days' => 'How many days back to sync (default 7)',
            ],
        ],
        'stats' => [
            'description' => 'Get database statistics and sync info',
            'url' => 'firestore_sync.php?action=stats',
        ],
    ],
    'setup_instructions' => [
        'step1' => 'Verify Firebase service account JSON exists in secure/ folder for chrmo-21269 project',
        'step2' => 'Run: firestore_sync.php?action=fix_all&limit=500 to sync all documents',
        'step3' => 'Verify in Firebase console (chrmo-21269 project) that documents appear in collection "tracking"',
        'step4' => 'Test mobile app - documents should now appear in dashboard',
    ],
]);
?>
