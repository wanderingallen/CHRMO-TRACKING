<?php
// Simple Firestore client using a service account and REST API to upsert
// documents. Keep the service account JSON OUTSIDE web root.

function firestore_upsert_document($collection, $documentId, array $fields)
{
    if ($collection === '' || $documentId === null) {
        return false;
    }

    $candidates = [
        __DIR__ . '/../../secure/chrmo-dta-capstone-firebase-adminsdk-fbsvc-91f1559260.json',
        __DIR__ . '/../../secure/chrmo-21269-firebase-adminsdk-fbsvc-ed9528d76a.json',
        __DIR__ . '/../../secure/firebase_service_account.json',
    ];
    $serviceAccountPath = null;
    foreach ($candidates as $p) {
        if (is_file($p)) {
            $serviceAccountPath = $p;
            break;
        }
    }
    if (!$serviceAccountPath) {
        error_log('firestore_upsert_document: service account JSON not found in secure/');
        return false;
    }

    $sa = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$sa || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
        error_log('firestore_upsert_tracking: invalid service account JSON');
        return false;
    }

    $projectId = $sa['project_id'];

    // 1) Build JWT for OAuth2 token exchange
    $now   = time();
    $exp   = $now + 3600; // 1 hour
    $claims = [
        'iss'   => $sa['client_email'],
        'sub'   => $sa['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $exp,
        'scope' => 'https://www.googleapis.com/auth/datastore',
    ];

    $jwtHeader  = firestore_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtPayload = firestore_base64url_encode(json_encode($claims));
    $signatureInput = $jwtHeader . '.' . $jwtPayload;

    $privateKey = $sa['private_key'];
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'sha256')) {
        error_log('firestore_upsert_tracking: openssl_sign failed');
        return false;
    }
    $jwt = $signatureInput . '.' . firestore_base64url_encode($signature);

    // 2) Exchange JWT for access token
    $tokenResp = firestore_http_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $tokenJson = json_decode($tokenResp['body'] ?? '', true);
    if (empty($tokenJson['access_token'])) {
        error_log('firestore_upsert_tracking: could not obtain access token: ' . ($tokenResp['body'] ?? ''));
        return false;
    }
    $accessToken = $tokenJson['access_token'];

    // 3) Build Firestore document body
    $document = ['fields' => []];
    foreach ($fields as $key => $value) {
        if (is_int($value)) {
            $document['fields'][$key] = ['integerValue' => (string)$value];
        } elseif (is_bool($value)) {
            $document['fields'][$key] = ['booleanValue' => $value];
        } else {
            $document['fields'][$key] = ['stringValue' => (string)$value];
        }
    }

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
        rawurlencode($projectId),
        rawurlencode($collection),
        rawurlencode((string)$documentId)
    );

    $body = json_encode($document);
    $resp = firestore_http_request_json('PATCH', $url, $body, $accessToken);

    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        error_log('firestore_upsert_document: Firestore error ' . $resp['status'] . ' ' . $resp['body']);
        return false;
    }

    return true;
}

// Backwards-compatible helper for tracking collection
function firestore_upsert_tracking($documentId, array $fields)
{
    return firestore_upsert_document('tracking', $documentId, $fields);
}

function firestore_delete_document($collection, $documentId)
{
    if ($collection === '' || $documentId === null) {
        return false;
    }

    $candidates = [
        __DIR__ . '/../../secure/chrmo-dta-capstone-firebase-adminsdk-fbsvc-91f1559260.json',
        __DIR__ . '/../../secure/chrmo-21269-firebase-adminsdk-fbsvc-ed9528d76a.json',
        __DIR__ . '/../../secure/firebase_service_account.json',
    ];
    $serviceAccountPath = null;
    foreach ($candidates as $p) {
        if (is_file($p)) {
            $serviceAccountPath = $p;
            break;
        }
    }
    if (!$serviceAccountPath) {
        error_log('firestore_delete_document: service account JSON not found in secure/');
        return false;
    }

    $sa = json_decode(file_get_contents($serviceAccountPath), true);
    if (!$sa || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
        error_log('firestore_delete_document: invalid service account JSON');
        return false;
    }

    $projectId = $sa['project_id'];

    // Build JWT for OAuth2 token exchange
    $now   = time();
    $exp   = $now + 3600; // 1 hour
    $claims = [
        'iss'   => $sa['client_email'],
        'sub'   => $sa['client_email'],
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $exp,
        'scope' => 'https://www.googleapis.com/auth/datastore',
    ];

    $jwtHeader  = firestore_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtPayload = firestore_base64url_encode(json_encode($claims));
    $signatureInput = $jwtHeader . '.' . $jwtPayload;

    $privateKey = $sa['private_key'];
    $signature = '';
    if (!openssl_sign($signatureInput, $signature, $privateKey, 'sha256')) {
        error_log('firestore_delete_document: openssl_sign failed');
        return false;
    }
    $jwt = $signatureInput . '.' . firestore_base64url_encode($signature);

    // Exchange JWT for access token
    $tokenResp = firestore_http_post_form('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $tokenJson = json_decode($tokenResp['body'] ?? '', true);
    if (empty($tokenJson['access_token'])) {
        error_log('firestore_delete_document: could not obtain access token: ' . ($tokenResp['body'] ?? ''));
        return false;
    }
    $accessToken = $tokenJson['access_token'];

    $url = sprintf(
        'https://firestore.googleapis.com/v1/projects/%s/databases/(default)/documents/%s/%s',
        rawurlencode($projectId),
        rawurlencode($collection),
        rawurlencode((string)$documentId)
    );

    $resp = firestore_http_request_json('DELETE', $url, '', $accessToken);

    // Treat missing doc as success (already deleted)
    if ($resp['status'] === 404) {
        return true;
    }
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        error_log('firestore_delete_document: Firestore error ' . $resp['status'] . ' ' . $resp['body']);
        return false;
    }

    return true;
}

function firestore_delete_tracking($documentId)
{
    return firestore_delete_document('tracking', $documentId);
}

function firestore_base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function firestore_http_post_form($url, array $fields)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $body];
}

function firestore_http_request_json($method, $url, $body, $accessToken)
{
    $ch = curl_init($url);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
    ];

    // For DELETE, don't send an empty body (some environments treat it inconsistently)
    if (!($method === 'DELETE' && ($body === '' || $body === null))) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    curl_setopt_array($ch, $opts);
    $respBody = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => $respBody];
}
