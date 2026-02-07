<?php

$dataDir = __DIR__ . '/data';
$configFile = $dataDir . '/config.json';
$tokenFile = $dataDir . '/token.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Default config
if (!file_exists($configFile)) {
    file_put_contents($configFile, json_encode([
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => ''
    ], JSON_PRETTY_PRINT));
}

$config = json_decode(file_get_contents($configFile), true);

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'status':
        $configured = !empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['redirect_uri']);
        $hasToken = file_exists($tokenFile);
        $token = $hasToken ? json_decode(file_get_contents($tokenFile), true) : null;
        $authenticated = $hasToken && !empty($token['refresh_token']);
        echo json_encode([
            'configured' => $configured,
            'authenticated' => $authenticated
        ]);
        break;

    case 'auth':
        if (empty($config['client_id']) || empty($config['redirect_uri'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Missing client_id or redirect_uri in config.json']);
            exit;
        }
        $params = http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ]);
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
        echo json_encode(['url' => $url]);
        break;

    case 'callback':
        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            http_response_code(400);
            echo json_encode(['error' => 'No auth code received']);
            exit;
        }
        $response = curlPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect_uri'],
            'grant_type' => 'authorization_code'
        ]);
        if (isset($response['error'])) {
            http_response_code(500);
            echo json_encode(['error' => $response['error_description'] ?? $response['error']]);
            exit;
        }
        $response['obtained_at'] = time();
        file_put_contents($tokenFile, json_encode($response, JSON_PRETTY_PRINT));
        // Redirect to main page after successful auth
        header('Location: ./');
        exit;

    case 'unread':
        $accessToken = getValidAccessToken($tokenFile, $config);
        if (!$accessToken) {
            http_response_code(401);
            echo json_encode(['error' => 'Not authenticated']);
            exit;
        }
        $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/labels/INBOX');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"]
        ]);
        $result = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            http_response_code(500);
            echo json_encode(['error' => 'Gmail API error', 'details' => $result]);
            exit;
        }
        echo json_encode([
            'unread' => $result['threadsUnread'] ?? 0,
            'total' => $result['threadsTotal'] ?? 0
        ]);
        break;

    case 'logout':
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

function getValidAccessToken($tokenFile, $config) {
    if (!file_exists($tokenFile)) return null;
    $token = json_decode(file_get_contents($tokenFile), true);
    if (empty($token['refresh_token'])) return null;

    // Check if access token is still valid (with 60s buffer)
    $expiresAt = ($token['obtained_at'] ?? 0) + ($token['expires_in'] ?? 0) - 60;
    if (time() < $expiresAt && !empty($token['access_token'])) {
        return $token['access_token'];
    }

    // Refresh
    $response = curlPost('https://oauth2.googleapis.com/token', [
        'refresh_token' => $token['refresh_token'],
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'grant_type' => 'refresh_token'
    ]);
    if (isset($response['error'])) return null;

    $token['access_token'] = $response['access_token'];
    $token['expires_in'] = $response['expires_in'];
    $token['obtained_at'] = time();
    file_put_contents($tokenFile, json_encode($token, JSON_PRETTY_PRINT));
    return $token['access_token'];
}

function curlPost($url, $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $result;
}
