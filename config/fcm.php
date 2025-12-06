<?php
declare(strict_types=1);

/**
 * Firebase Cloud Messaging (HTTP v1) minimal client (no composer libs).
 *
 * Requires: OpenSSL (enabled by default on Hostinger).
 *
 * Usage:
 *   require __DIR__ . '/fcm.php';
 *   fcm_send_to_topic($projectId, $serviceJsonPath, 'user_123',
 *                     title: 'Order Preparing', body: 'Your order is being prepared', data: ['tx'=>'TX-123']);
 */

function fcm_access_token(string $serviceJsonPath): string {
    static $cache = ['token'=>null, 'exp'=>0, 'hash'=>null];

    if (!is_file($serviceJsonPath)) {
        throw new RuntimeException("FCM service account JSON not found: $serviceJsonPath");
    }
    $json = file_get_contents($serviceJsonPath);
    $sa   = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    $now   = time();
    $cacheKey = md5($json);
    // reuse cached token if not expired (skew 60s)
    if ($cache['token'] && $cache['exp'] > ($now + 60) && $cache['hash'] === $cacheKey) {
        return $cache['token'];
    }

    $privateKey  = $sa['private_key']     ?? null;
    $clientEmail = $sa['client_email']    ?? null;
    $tokenUri    = $sa['token_uri']       ?? 'https://oauth2.googleapis.com/token';
    if (!$privateKey || !$clientEmail) {
        throw new RuntimeException('Invalid service account JSON (missing private_key or client_email).');
    }

    // Build JWT for OAuth 2 service account flow
    $header  = ['alg'=>'RS256','typ'=>'JWT'];
    $claims  = [
        'iss'   => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => $tokenUri,
        'iat'   => $now,
        'exp'   => $now + 3600, // 1 hour
    ];
    $b64 = fn($arr) => rtrim(strtr(base64_encode(json_encode($arr)), '+/', '-_'), '=');

    $jwtUnsigned = $b64($header) . '.' . $b64($claims);
    $signature   = '';
    if (!openssl_sign($jwtUnsigned, $signature, $privateKey, 'SHA256')) {
        throw new RuntimeException('Failed to sign JWT for FCM.');
    }
    $assertion = $jwtUnsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    // Exchange for access token
    $ch = curl_init($tokenUri);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL error getting FCM token: '.curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $resp = json_decode($raw, true);
    if ($code !== 200 || empty($resp['access_token'])) {
        throw new RuntimeException('FCM token error: HTTP '.$code.' — '.$raw);
    }

    $cache = [
        'token' => $resp['access_token'],
        'exp'   => $now + (int)($resp['expires_in'] ?? 3600),
        'hash'  => $cacheKey,
    ];
    return $cache['token'];
}

function fcm_send(string $projectId, string $serviceJsonPath, array $message): array {
    $token = fcm_access_token($serviceJsonPath);
    $url   = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $payload = ['message' => $message, 'validate_only' => false];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=UTF-8',
            "Authorization: Bearer {$token}",
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL error sending FCM: '.curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $resp = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('FCM send error: HTTP '.$code.' — '.$raw);
    }
    return $resp;
}

/** Send to a topic (recommended: one topic per user, e.g. "user_42") */
function fcm_send_to_topic(string $projectId, string $serviceJsonPath, string $topic, ?string $title, ?string $body, array $data = []): array {
    $msg = [
        'topic' => $topic, // no "/topics/" prefix here
        'android' => [
            'priority' => 'HIGH',
            'notification' => array_filter([
                'title' => $title,
                'body'  => $body,
                // optional channel id if you created one: 'channel_id' => 'orders',
            ]),
            'ttl' => '60s',
        ],
        // Use data payload so your app can fully control the UI
        'data' => array_map('strval', $data),
    ];
    return fcm_send($projectId, $serviceJsonPath, $msg);
}

/** Send to a list of device tokens (if you choose token storage over topics) */
function fcm_send_to_tokens(string $projectId, string $serviceJsonPath, array $tokens, ?string $title, ?string $body, array $data = []): array {
    $results = [];
    foreach (array_values(array_unique(array_filter($tokens))) as $token) {
        $msg = [
            'token' => $token,
            'android' => [
                'priority' => 'HIGH',
                'notification' => array_filter([
                    'title' => $title,
                    'body'  => $body,
                ]),
                'ttl' => '60s',
            ],
            'data' => array_map('strval', $data),
        ];
        $results[] = fcm_send($projectId, $serviceJsonPath, $msg);
    }
    return $results;
}
