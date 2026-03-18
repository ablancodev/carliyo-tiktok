<?php
// TikTok API Configuration
// Get these from https://developers.tiktok.com/
define('TIKTOK_CLIENT_KEY', 'sbaw...');
define('TIKTOK_CLIENT_SECRET', 'u....');
define('TIKTOK_REDIRECT_URI', 'https://donde-lo-metas.com/oauth.php');

// Token storage file
define('TOKEN_FILE', __DIR__ . '/token.json');

// Max upload size (50MB)
define('MAX_FILE_SIZE', 50 * 1024 * 1024);

/**
 * Get a valid access token, refreshing if expired
 */
function getAccessToken(): string {
    if (!file_exists(TOKEN_FILE)) {
        throw new Exception('No hay token configurado. Ve a /oauth.php para autorizar la cuenta de TikTok.');
    }

    $tokenData = json_decode(file_get_contents(TOKEN_FILE), true);

    if (!$tokenData || empty($tokenData['access_token'])) {
        throw new Exception('Token inválido. Ve a /oauth.php para reautorizar.');
    }

    // Check if token is expired (with 60s margin)
    if (time() >= ($tokenData['expires_at'] ?? 0) - 60) {
        $tokenData = refreshToken($tokenData['refresh_token'] ?? '');
    }

    return $tokenData['access_token'];
}

/**
 * Refresh the access token
 */
function refreshToken(string $refreshToken): array {
    if (!$refreshToken) {
        throw new Exception('No hay refresh token. Ve a /oauth.php para reautorizar.');
    }

    $ch = curl_init('https://open.tiktokapis.com/v2/oauth/token/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_key' => TIKTOK_CLIENT_KEY,
            'client_secret' => TIKTOK_CLIENT_SECRET,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception('Error al refrescar token: ' . $error);
    }

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        // Delete invalid token file
        unlink(TOKEN_FILE);
        throw new Exception('Token expirado. Ve a /oauth.php para reautorizar.');
    }

    saveToken($data);
    return $data;
}

/**
 * Save token data to file
 */
function saveToken(array $data): void {
    $tokenData = [
        'access_token' => $data['access_token'],
        'refresh_token' => $data['refresh_token'] ?? '',
        'expires_at' => time() + ($data['expires_in'] ?? 86400),
        'refresh_expires_at' => time() + ($data['refresh_expires_in'] ?? 86400 * 365),
        'saved_at' => date('Y-m-d H:i:s'),
    ];
    file_put_contents(TOKEN_FILE, json_encode($tokenData, JSON_PRETTY_PRINT));
}
