<?php
/**
 * OCR Search Helper Functions
 * 
 * Provides smart search across OCR content with:
 * - Keyword extraction (names, amounts, dates, document types)
 * - Fuzzy matching for typos
 * - Multi-page document support
 * - Search result ranking
 */

require_once __DIR__ . '/file_crypto.php';

function ocr_ensure_pages_table(mysqli $conn): bool {
    try {
        $res = $conn->query("SHOW TABLES LIKE 'ocr_pages'");
        if ($res && $res->num_rows > 0) {
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS ocr_pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope ENUM('tracking', 'archive') NOT NULL DEFAULT 'tracking',
            doc_id INT NOT NULL,
            page_number INT NOT NULL DEFAULT 1,
            ocr_text LONGTEXT,
            ocr_keywords TEXT COMMENT 'Auto-extracted keywords for fast search',
            text_sha256 CHAR(64) COMMENT 'Integrity hash of ocr_text',
            confidence_score DECIMAL(5,2) COMMENT 'OCR confidence if available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_page (scope, doc_id, page_number),
            INDEX idx_doc (scope, doc_id),
            INDEX idx_keywords (ocr_keywords(100)),
            FULLTEXT INDEX ft_ocr_text (ocr_text),
            FULLTEXT INDEX ft_keywords (ocr_keywords)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return (bool)$conn->query($sql);
    } catch (Throwable $t) {
        error_log('ocr_ensure_pages_table failed: ' . $t->getMessage());
        return false;
    }
}

function ocr_ensure_parent_ocr_columns(mysqli $conn): void {
    // Best-effort only: this should never break the main workflow.
    try {
        // tracking.ocr_summary
        $check = $conn->query("SHOW COLUMNS FROM tracking LIKE 'ocr_summary'");
        if ($check && $check->num_rows === 0) {
            @$conn->query("ALTER TABLE tracking ADD COLUMN ocr_summary TEXT COMMENT 'Aggregated searchable keywords from all pages' AFTER ocr_content");
        }

        // tracking.total_pages
        $check = $conn->query("SHOW COLUMNS FROM tracking LIKE 'total_pages'");
        if ($check && $check->num_rows === 0) {
            @$conn->query("ALTER TABLE tracking ADD COLUMN total_pages INT DEFAULT 1 AFTER ocr_summary");
        }

        // archive.ocr_summary
        $check = $conn->query("SHOW COLUMNS FROM archive LIKE 'ocr_summary'");
        if ($check && $check->num_rows === 0) {
            // Use a safe placement: if original_ocr_content doesn't exist in some DBs, MySQL will fail.
            // So don't specify AFTER here.
            @$conn->query("ALTER TABLE archive ADD COLUMN ocr_summary TEXT COMMENT 'Aggregated searchable keywords from all pages'");
        }

        // archive.total_pages
        $check = $conn->query("SHOW COLUMNS FROM archive LIKE 'total_pages'");
        if ($check && $check->num_rows === 0) {
            @$conn->query("ALTER TABLE archive ADD COLUMN total_pages INT DEFAULT 1");
        }
    } catch (Throwable $t) {
        error_log('ocr_ensure_parent_ocr_columns failed: ' . $t->getMessage());
    }
}

/**
 * Extract searchable keywords from OCR text
 * Similar to the Dart OcrTextProcessor but server-side for stored data
 */
function ocr_extract_keywords(string $text): array {
    $keywords = [];
    $text = trim($text);
    if ($text === '') return $keywords;
    
    // Normalize whitespace
    $text = preg_replace('/\s+/', ' ', $text);
    
    // Extract names (Title Case words, 2+ consecutive)
    if (preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+)+)\b/', $text, $matches)) {
        foreach ($matches[1] as $name) {
            $keywords[] = strtolower($name);
        }
    }
    
    // Extract amounts (₱ or PHP followed by numbers)
    if (preg_match_all('/(?:₱|PHP|Php)\s*([\d,]+(?:\.\d{2})?)/i', $text, $matches)) {
        foreach ($matches[0] as $amount) {
            $keywords[] = strtolower(preg_replace('/\s+/', '', $amount));
        }
    }
    
    // Extract dates (various formats)
    if (preg_match_all('/\b\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}\b/', $text, $matches)) {
        $keywords = array_merge($keywords, $matches[0]);
    }
    if (preg_match_all('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s*\d{4}\b/i', $text, $matches)) {
        foreach ($matches[0] as $date) {
            $keywords[] = strtolower($date);
        }
    }
    
    // Extract document type indicators
    $docTypes = ['payroll', 'payslip', 'memo', 'memorandum', 'travel order', 'purchase request', 
                 'purchase order', 'advisory', 'announcement', 'voucher', 'receipt', 'invoice',
                 'certificate', 'contract', 'agreement', 'report', 'letter', 'notice'];
    $textLower = strtolower($text);
    foreach ($docTypes as $type) {
        if (strpos($textLower, $type) !== false) {
            $keywords[] = $type;
        }
    }
    
    // Extract reference numbers (patterns like REF-123, OR-456, etc.)
    if (preg_match_all('/\b[A-Z]{2,5}[-#]?\d{3,10}\b/i', $text, $matches)) {
        foreach ($matches[0] as $ref) {
            $keywords[] = strtolower($ref);
        }
    }
    
    // Extract significant words (4+ chars, not common words)
    $stopWords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'had', 'her', 
                  'was', 'one', 'our', 'out', 'has', 'have', 'been', 'from', 'they', 'will',
                  'with', 'this', 'that', 'what', 'which', 'their', 'would', 'there', 'could',
                  'other', 'into', 'than', 'then', 'these', 'some', 'them', 'make', 'like',
                  'page', 'date', 'name', 'form'];
    $words = preg_split('/\s+/', strtolower($text));
    foreach ($words as $word) {
        $word = preg_replace('/[^a-z0-9]/', '', $word);
        if (strlen($word) >= 4 && !in_array($word, $stopWords) && !is_numeric($word)) {
            $keywords[] = $word;
        }
    }
    
    // Deduplicate and limit
    $keywords = array_unique($keywords);
    return array_slice($keywords, 0, 100);
}

/**
 * Generate a searchable summary from multiple pages of OCR text
 */
function ocr_generate_summary(array $pageTexts): string {
    $allKeywords = [];
    
    foreach ($pageTexts as $pageNum => $text) {
        $keywords = ocr_extract_keywords($text);
        $allKeywords = array_merge($allKeywords, $keywords);
    }
    
    // Deduplicate and sort by frequency
    $counts = array_count_values($allKeywords);
    arsort($counts);
    
    // Take top 50 keywords
    $topKeywords = array_slice(array_keys($counts), 0, 50);
    
    return implode(' ', $topKeywords);
}

/**
 * Store OCR text for a document page
 */
function ocr_store_page(mysqli $conn, string $scope, int $docId, int $pageNumber, string $ocrText, ?float $confidence = null): bool {
    $ocrText = trim($ocrText);
    if ($ocrText === '') return false;

    ocr_ensure_pages_table($conn);
    
    $hash = hash('sha256', $ocrText);
    $keywords = implode(' ', ocr_extract_keywords($ocrText));
    
    $stmt = $conn->prepare("
        INSERT INTO ocr_pages (scope, doc_id, page_number, ocr_text, ocr_keywords, text_sha256, confidence_score)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            ocr_text = VALUES(ocr_text),
            ocr_keywords = VALUES(ocr_keywords),
            text_sha256 = VALUES(text_sha256),
            confidence_score = VALUES(confidence_score),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    if (!$stmt) {
        error_log("ocr_store_page prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param('siisssd', $scope, $docId, $pageNumber, $ocrText, $keywords, $hash, $confidence);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Store multiple pages and update parent document summary
 */
function ocr_store_document_pages(mysqli $conn, string $scope, int $docId, array $pageTexts): bool {
    $totalPages = count($pageTexts);
    if ($totalPages === 0) return false;
    
    ocr_ensure_pages_table($conn);
    ocr_ensure_parent_ocr_columns($conn);
    
    // Store each page
    foreach ($pageTexts as $pageNum => $text) {
        ocr_store_page($conn, $scope, $docId, $pageNum + 1, $text);
    }
    
    // Generate and store summary on parent document
    $summary = ocr_generate_summary($pageTexts);
    $table = $scope === 'archive' ? 'archive' : 'tracking';
    
    $stmt = $conn->prepare("UPDATE $table SET ocr_summary = ?, total_pages = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('sii', $summary, $totalPages, $docId);
        $stmt->execute();
        $stmt->close();
    }
    
    return true;
}

/**
 * Get all OCR pages for a document
 */
function ocr_get_pages(mysqli $conn, string $scope, int $docId): array {
    $pages = [];
    $stmt = $conn->prepare("SELECT page_number, ocr_text, ocr_keywords, confidence_score FROM ocr_pages WHERE scope = ? AND doc_id = ? ORDER BY page_number");
    if ($stmt) {
        $stmt->bind_param('si', $scope, $docId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pages[] = $row;
            }
        }
        $stmt->close();
    }
    return $pages;
}

function ocr_table_has_column(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $res = @$conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    $cache[$key] = ($res && $res->num_rows > 0);
    if ($res) {
        $res->free();
    }
    return $cache[$key];
}

/**
 * Smart search across documents using OCR content
 * Returns documents with relevance scores and matching page info
 */
function ocr_smart_search(mysqli $conn, string $scope, string $query, int $limit = 20): array {
    $results = [];
    $query = trim($query);
    if ($query === '') return $results;
    
    ocr_ensure_pages_table($conn);
    ocr_ensure_parent_ocr_columns($conn);
    
    // Parse query into terms
    $terms = preg_split('/\s+/', strtolower($query));
    $terms = array_filter($terms, fn($t) => strlen($t) >= 2);
    if (empty($terms)) return $results;
    
    $table = $scope === 'archive' ? 'archive' : 'tracking';
    $nameCol = $scope === 'archive' ? 'document_name' : 'employee_name';
    $hasSummary = ocr_table_has_column($conn, $table, 'ocr_summary');
    $hasTotalPages = ocr_table_has_column($conn, $table, 'total_pages');
    $summarySelect = $hasSummary ? "d.ocr_summary" : "NULL AS ocr_summary";
    $totalPagesSelect = $hasTotalPages ? "d.total_pages" : "1 AS total_pages";
    
    // Build search conditions
    $likeTerms = array_map(fn($t) => '%' . $conn->real_escape_string($t) . '%', $terms);
    
    // Method 1: FULLTEXT search on ocr_pages (best for natural language queries)
    $ftQuery = $conn->real_escape_string(implode(' ', $terms));
    
    $sql = "
        SELECT DISTINCT 
            d.id,
            d.type,
            d.$nameCol as name,
            d.department,
            d.status,
            $summarySelect,
            $totalPagesSelect,
            (
                SELECT GROUP_CONCAT(DISTINCT op.page_number ORDER BY op.page_number)
                FROM ocr_pages op 
                WHERE op.scope = ? AND op.doc_id = d.id 
                AND (MATCH(op.ocr_text) AGAINST(? IN NATURAL LANGUAGE MODE) 
                     OR MATCH(op.ocr_keywords) AGAINST(? IN NATURAL LANGUAGE MODE))
            ) as matching_pages,
            (
                SELECT MAX(MATCH(op2.ocr_text) AGAINST(? IN NATURAL LANGUAGE MODE))
                FROM ocr_pages op2 
                WHERE op2.scope = ? AND op2.doc_id = d.id
            ) as relevance_score
        FROM $table d
        WHERE EXISTS (
            SELECT 1 FROM ocr_pages op 
            WHERE op.scope = ? AND op.doc_id = d.id
            AND (MATCH(op.ocr_text) AGAINST(? IN NATURAL LANGUAGE MODE)
                 OR MATCH(op.ocr_keywords) AGAINST(? IN NATURAL LANGUAGE MODE))
        )
        ORDER BY relevance_score DESC
        LIMIT ?
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ssssssssi', 
                $scope, $ftQuery, $ftQuery, 
                $ftQuery, $scope, 
                $scope, $ftQuery, $ftQuery, 
                $limit
            );
            
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $results[] = [
                        'id' => (int)$row['id'],
                        'type' => $row['type'] ?? 'Document',
                        'name' => $row['name'] ?? '',
                        'department' => $row['department'] ?? '',
                        'status' => $row['status'] ?? '',
                        'total_pages' => (int)($row['total_pages'] ?? 1),
                        'matching_pages' => $row['matching_pages'] ? explode(',', $row['matching_pages']) : [],
                        'relevance' => (float)($row['relevance_score'] ?? 0),
                        'summary_snippet' => substr($row['ocr_summary'] ?? '', 0, 150),
                    ];
                }
            }
            $stmt->close();
        } else {
            error_log('ocr_smart_search prepare failed: ' . $conn->error);
        }
    } catch (Throwable $t) {
        error_log('ocr_smart_search failed: ' . $t->getMessage());
        $results = [];
    }
    
    // Fallback: LIKE search if FULLTEXT returns nothing (for short queries)
    if (empty($results)) {
        $results = ocr_like_search($conn, $scope, $terms, $limit);
    }
    
    return $results;
}

/**
 * Fallback LIKE-based search for when FULLTEXT doesn't match
 */
function ocr_like_search(mysqli $conn, string $scope, array $terms, int $limit): array {
    $results = [];
    $table = $scope === 'archive' ? 'archive' : 'tracking';
    $nameCol = $scope === 'archive' ? 'document_name' : 'employee_name';
    $hasSummary = ocr_table_has_column($conn, $table, 'ocr_summary');
    $hasTotalPages = ocr_table_has_column($conn, $table, 'total_pages');
    $summarySelect = $hasSummary ? "d.ocr_summary" : "NULL AS ocr_summary";
    $totalPagesSelect = $hasTotalPages ? "d.total_pages" : "1 AS total_pages";
    
    // Build OR conditions for each term
    $conditions = [];
    $params = [];
    $types = '';
    
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $conditions[] = "(op.ocr_text LIKE ? OR op.ocr_keywords LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }
    
    $whereClause = implode(' OR ', $conditions);
    
    $sql = "
        SELECT DISTINCT 
            d.id,
            d.type,
            d.$nameCol as name,
            d.department,
            d.status,
            $summarySelect,
            $totalPagesSelect,
            (SELECT GROUP_CONCAT(DISTINCT op2.page_number ORDER BY op2.page_number)
             FROM ocr_pages op2 
             WHERE op2.scope = ? AND op2.doc_id = d.id 
             AND ($whereClause)) as matching_pages
        FROM $table d
        INNER JOIN ocr_pages op ON op.scope = ? AND op.doc_id = d.id
        WHERE $whereClause
        GROUP BY d.id
        LIMIT ?
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            // Build parameter array: scope for subquery, params, scope for join, params again, limit
            $bindParams = [$scope];
            $bindParams = array_merge($bindParams, $params);
            $bindParams[] = $scope;
            $bindParams = array_merge($bindParams, $params);
            $bindParams[] = $limit;
            
            $bindTypes = 's' . $types . 's' . $types . 'i';
            
            $stmt->bind_param($bindTypes, ...$bindParams);
            
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $results[] = [
                        'id' => (int)$row['id'],
                        'type' => $row['type'] ?? 'Document',
                        'name' => $row['name'] ?? '',
                        'department' => $row['department'] ?? '',
                        'status' => $row['status'] ?? '',
                        'total_pages' => (int)($row['total_pages'] ?? 1),
                        'matching_pages' => $row['matching_pages'] ? explode(',', $row['matching_pages']) : [],
                        'relevance' => 1.0,
                        'summary_snippet' => substr($row['ocr_summary'] ?? '', 0, 150),
                    ];
                }
            }
            $stmt->close();
        } else {
            error_log('ocr_like_search prepare failed: ' . $conn->error);
        }
    } catch (Throwable $t) {
        error_log('ocr_like_search failed: ' . $t->getMessage());
    }
    
    return $results;
}

/**
 * Get OCR text snippet around a matching term for display
 */
function ocr_get_match_snippet(string $text, string $query, int $contextChars = 60): string {
    $pos = stripos($text, $query);
    if ($pos === false) {
        // Try individual terms
        $terms = preg_split('/\s+/', $query);
        foreach ($terms as $term) {
            $pos = stripos($text, $term);
            if ($pos !== false) break;
        }
    }
    
    if ($pos === false) {
        return substr($text, 0, $contextChars * 2) . '...';
    }
    
    $start = max(0, $pos - $contextChars);
    $end = min(strlen($text), $pos + strlen($query) + $contextChars);
    
    $snippet = substr($text, $start, $end - $start);
    
    if ($start > 0) $snippet = '...' . $snippet;
    if ($end < strlen($text)) $snippet .= '...';
    
    return $snippet;
}

/**
 * Copy OCR pages from tracking to archive when archiving a document
 */
function ocr_copy_to_archive(mysqli $conn, int $trackingId, int $archiveId): bool {
    $result = false;

    try {
        if (!ocr_ensure_pages_table($conn)) {
            return false;
        }

        ocr_ensure_parent_ocr_columns($conn);

        // First, copy the main ocr_content column from tracking to archive
        $stmtMain = $conn->prepare("UPDATE archive SET ocr_content = COALESCE((SELECT ocr_content FROM tracking WHERE id = ?), ocr_content) WHERE id = ? AND (ocr_content IS NULL OR ocr_content = '')");
        if ($stmtMain) {
            $stmtMain->bind_param('ii', $trackingId, $archiveId);
            $stmtMain->execute();
            $stmtMain->close();
        }

        // Then copy the ocr_pages entries
        $stmt = $conn->prepare("
            INSERT INTO ocr_pages (scope, doc_id, page_number, ocr_text, ocr_keywords, text_sha256, confidence_score)
            SELECT 'archive', ?, page_number, ocr_text, ocr_keywords, text_sha256, confidence_score
            FROM ocr_pages 
            WHERE scope = 'tracking' AND doc_id = ?
            ON DUPLICATE KEY UPDATE
                ocr_text = VALUES(ocr_text),
                ocr_keywords = VALUES(ocr_keywords),
                text_sha256 = VALUES(text_sha256)
        ");

        if (!$stmt) return false;

        $stmt->bind_param('ii', $archiveId, $trackingId);
        $result = (bool)$stmt->execute();
        $stmt->close();
    } catch (Throwable $t) {
        error_log('ocr_copy_to_archive failed: ' . $t->getMessage());
        $result = false;
    }
    
    // Also copy summary (best-effort)
    try {
        $stmt2 = $conn->prepare("UPDATE archive SET ocr_summary = (SELECT ocr_summary FROM tracking WHERE id = ?), total_pages = (SELECT total_pages FROM tracking WHERE id = ?) WHERE id = ?");
        if ($stmt2) {
            $stmt2->bind_param('iii', $trackingId, $trackingId, $archiveId);
            $stmt2->execute();
            $stmt2->close();
        }
    } catch (Throwable $t) {
        error_log('ocr_copy_to_archive summary copy failed: ' . $t->getMessage());
    }
    
    return $result;
}
