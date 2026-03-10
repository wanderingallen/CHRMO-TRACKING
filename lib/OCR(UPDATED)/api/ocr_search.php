<?php
/**
 * OCR Search API Endpoint
 * 
 * Provides smart search across documents using OCR content.
 * 
 * Usage:
 *   GET ocr_search.php?q=payroll+dave&scope=tracking&limit=20
 *   GET ocr_search.php?q=travel+order+january&scope=archive
 *   GET ocr_search.php?action=pages&scope=tracking&doc_id=123
 * 
 * Returns JSON with matching documents, relevance scores, and matching page numbers.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/ocr_search_helper.php';
require_once __DIR__ . '/../config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = $_GET['action'] ?? 'search';
$scope = $_GET['scope'] ?? 'tracking';
if (!in_array($scope, ['tracking', 'archive'])) {
    $scope = 'tracking';
}

switch ($action) {
    case 'search':
        // Main search endpoint
        $query = trim($_GET['q'] ?? '');
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        
        if ($query === '') {
            echo json_encode([
                'success' => true,
                'query' => '',
                'results' => [],
                'total' => 0,
            ]);
            break;
        }
        
        $results = ocr_smart_search($conn, $scope, $query, $limit);
        
        // Enrich results with snippets
        foreach ($results as &$result) {
            if (!empty($result['matching_pages'])) {
                // Get snippet from first matching page
                $pageNum = (int)$result['matching_pages'][0];
                $stmt = $conn->prepare("SELECT ocr_text FROM ocr_pages WHERE scope = ? AND doc_id = ? AND page_number = ?");
                if ($stmt) {
                    $stmt->bind_param('sii', $scope, $result['id'], $pageNum);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($row = $res->fetch_assoc()) {
                        $result['snippet'] = ocr_get_match_snippet($row['ocr_text'], $query);
                    }
                    $stmt->close();
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'query' => $query,
            'scope' => $scope,
            'results' => $results,
            'total' => count($results),
        ]);
        break;
        
    case 'pages':
        // Get all OCR pages for a document
        $docId = (int)($_GET['doc_id'] ?? 0);
        if ($docId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid doc_id']);
            break;
        }
        
        $pages = ocr_get_pages($conn, $scope, $docId);
        
        echo json_encode([
            'success' => true,
            'doc_id' => $docId,
            'scope' => $scope,
            'total_pages' => count($pages),
            'pages' => $pages,
        ]);
        break;
        
    case 'suggest':
        // Quick suggestions as user types
        $query = trim($_GET['q'] ?? '');
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'suggestions' => []]);
            break;
        }
        
        $suggestions = [];
        $seen = [];
        $like = '%' . $conn->real_escape_string($query) . '%';
        
        // Get matching keywords from ocr_pages
        $sql = "SELECT DISTINCT ocr_keywords FROM ocr_pages WHERE scope = ? AND ocr_keywords LIKE ? LIMIT 10";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $scope, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $keywords = explode(' ', $row['ocr_keywords']);
                foreach ($keywords as $kw) {
                    $kw = trim($kw);
                    if ($kw !== '' && stripos($kw, $query) !== false && !isset($seen[$kw])) {
                        $seen[$kw] = true;
                        $suggestions[] = ['label' => $kw, 'type' => 'keyword'];
                        if (count($suggestions) >= 8) break 2;
                    }
                }
            }
            $stmt->close();
        }
        
        // Get matching document types
        $table = $scope === 'archive' ? 'archive' : 'tracking';
        $sql = "SELECT DISTINCT type FROM $table WHERE type LIKE ? LIMIT 5";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $type = $row['type'];
                if (!isset($seen[$type])) {
                    $seen[$type] = true;
                    $suggestions[] = ['label' => $type, 'type' => 'document_type'];
                }
            }
            $stmt->close();
        }
        
        echo json_encode([
            'success' => true,
            'query' => $query,
            'suggestions' => array_slice($suggestions, 0, 10),
        ]);
        break;
        
    case 'store':
        // Store OCR for a document (POST only)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
        }
        
        $docId = (int)($_POST['doc_id'] ?? 0);
        $pages = $_POST['pages'] ?? [];
        
        if ($docId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid doc_id']);
            break;
        }
        
        if (!is_array($pages) || empty($pages)) {
            echo json_encode(['success' => false, 'error' => 'No pages provided']);
            break;
        }
        
        $success = ocr_store_document_pages($conn, $scope, $docId, $pages);
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'OCR stored successfully' : 'Failed to store OCR',
            'doc_id' => $docId,
            'pages_stored' => count($pages),
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

$conn->close();
