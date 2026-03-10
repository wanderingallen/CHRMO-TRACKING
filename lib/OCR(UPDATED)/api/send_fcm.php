<?php
// Simple FCM sender. Fill in your Firebase Server Key below.
// SECURITY: Do NOT commit real keys to public repos.
$FIREBASE_SERVER_KEY = getenv('FIREBASE_SERVER_KEY');
if (!$FIREBASE_SERVER_KEY || $FIREBASE_SERVER_KEY === '') {
  // You can hardcode for local testing, but prefer env var
  $FIREBASE_SERVER_KEY = 'PASTE_YOUR_SERVER_KEY_HERE';
}

function fcm_find_service_account_path(): ?string {
  $candidates = [
    __DIR__ . '/../../../secure/chrmo-dta-capstone-firebase-adminsdk-fbsvc-91f1559260.json',
    __DIR__ . '/../../../secure/chrmo-21269-firebase-adminsdk-fbsvc-ed9528d76a.json',
    __DIR__ . '/../../../secure/firebase_service_account.json',
  ];
  foreach ($candidates as $p) {
    if (is_file($p)) {
      return $p;
    }
  }
  return null;
}

function fcm_base64url_encode(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function fcm_http_post_form(string $url, array $fields): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($fields),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
  ]);
  $body = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['status' => $status, 'body' => $body, 'error' => $err];
}

function fcm_http_request_json(string $method, string $url, string $body, array $headers): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => $headers,
  ]);
  $respBody = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  return ['status' => $status, 'body' => $respBody, 'error' => $err];
}

function fcm_get_access_token_and_project_id(): array {
  $path = fcm_find_service_account_path();
  if (!$path) {
    return ['success' => false, 'message' => 'Service account JSON not found'];
  }

  $sa = json_decode(@file_get_contents($path), true);
  if (!$sa || empty($sa['client_email']) || empty($sa['private_key']) || empty($sa['project_id'])) {
    return ['success' => false, 'message' => 'Invalid service account JSON'];
  }

  $now = time();
  $exp = $now + 3600;
  $claims = [
    'iss' => $sa['client_email'],
    'sub' => $sa['client_email'],
    'aud' => 'https://oauth2.googleapis.com/token',
    'iat' => $now,
    'exp' => $exp,
    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
  ];

  $jwtHeader = fcm_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
  $jwtPayload = fcm_base64url_encode(json_encode($claims));
  $signatureInput = $jwtHeader . '.' . $jwtPayload;

  $signature = '';
  if (!openssl_sign($signatureInput, $signature, $sa['private_key'], 'sha256')) {
    return ['success' => false, 'message' => 'openssl_sign failed'];
  }
  $jwt = $signatureInput . '.' . fcm_base64url_encode($signature);

  $tokenResp = fcm_http_post_form('https://oauth2.googleapis.com/token', [
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwt,
  ]);
  $tokenJson = json_decode($tokenResp['body'] ?? '', true);
  if (empty($tokenJson['access_token'])) {
    return ['success' => false, 'message' => 'Could not obtain access token', 'response' => ($tokenResp['body'] ?? '')];
  }
  return ['success' => true, 'access_token' => $tokenJson['access_token'], 'project_id' => $sa['project_id']];
}

function fcm_send_v1_message(array $message): array {
  $auth = fcm_get_access_token_and_project_id();
  if (empty($auth['success'])) {
    return $auth;
  }

  $accessToken = (string)$auth['access_token'];
  $projectId = (string)$auth['project_id'];

  $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
  $payload = json_encode(['message' => $message]);

  $resp = fcm_http_request_json('POST', $url, $payload, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken,
  ]);

  $ok = ($resp['status'] >= 200 && $resp['status'] < 300);
  return ['success' => $ok, 'code' => $resp['status'], 'response' => $resp['body'], 'error' => $resp['error']];
}

function send_fcm_to_tokens(array $tokens, string $title, string $body, array $data = []): array {
  global $FIREBASE_SERVER_KEY;
  $tokens = array_values(array_filter(array_unique($tokens)));
  if (empty($tokens)) return ['success' => false, 'message' => 'No tokens'];

  // If legacy server key is missing, use FCM HTTP v1 (one request per token)
  if (!$FIREBASE_SERVER_KEY || $FIREBASE_SERVER_KEY === '' || $FIREBASE_SERVER_KEY === 'PASTE_YOUR_SERVER_KEY_HERE') {
    $results = [];
    $successCount = 0;
    foreach ($tokens as $t) {
      $res = fcm_send_v1_message([
        'token' => (string)$t,
        'notification' => [
          'title' => $title,
          'body' => $body,
        ],
        'data' => array_map('strval', $data),
        'webpush' => [
          'notification' => [
            'title' => $title,
            'body' => $body,
          ]
        ]
      ]);
      $results[] = $res;
      if (!empty($res['success'])) {
        $successCount++;
      }
    }
    return ['success' => ($successCount > 0), 'sent' => $successCount, 'attempted' => count($tokens), 'results' => $results];
  }

  $payload = [
    'registration_ids' => $tokens,
    'notification' => [
      'title' => $title,
      'body' => $body,
      'sound' => 'default',
    ],
    'data' => $data,
    'android' => [ 'priority' => 'high' ],
    'apns' => [ 'headers' => [ 'apns-priority' => '10' ] ],
  ];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: key=' . $FIREBASE_SERVER_KEY,
    'Content-Type: application/json'
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $result = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($result === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['success' => false, 'message' => $err, 'code' => $code];
  }
  curl_close($ch);
  return ['success' => ($code >= 200 && $code < 300), 'code' => $code, 'response' => $result];
}

function send_fcm_to_topic(string $topic, string $title, string $body, array $data = []): array {
  global $FIREBASE_SERVER_KEY;
  $topic = trim($topic);
  if ($topic === '') return ['success' => false, 'message' => 'Empty topic'];

  // If legacy server key is missing, use FCM HTTP v1
  if (!$FIREBASE_SERVER_KEY || $FIREBASE_SERVER_KEY === '' || $FIREBASE_SERVER_KEY === 'PASTE_YOUR_SERVER_KEY_HERE') {
    // FCM v1 expects topic name without "/topics/"
    if (str_starts_with($topic, '/topics/')) {
      $topic = substr($topic, 8);
    }
    return fcm_send_v1_message([
      'topic' => (string)$topic,
      'notification' => [
        'title' => $title,
        'body' => $body,
      ],
      'data' => array_map('strval', $data),
      'webpush' => [
        'notification' => [
          'title' => $title,
          'body' => $body,
        ]
      ]
    ]);
  }

  $payload = [
    'to' => '/topics/' . $topic,
    'notification' => [
      'title' => $title,
      'body' => $body,
      'sound' => 'default',
    ],
    'data' => $data,
    'android' => [ 'priority' => 'high' ],
    'apns' => [ 'headers' => [ 'apns-priority' => '10' ] ],
  ];

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: key=' . $FIREBASE_SERVER_KEY,
    'Content-Type: application/json'
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
  $result = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($result === false) {
    $err = curl_error($ch);
    curl_close($ch);
    return ['success' => false, 'message' => $err, 'code' => $code];
  }
  curl_close($ch);
  return ['success' => ($code >= 200 && $code < 300), 'code' => $code, 'response' => $result];
}
