<?php
/**
 * OAuth2 + PKCE flow for TikTok - Run this ONCE to authorize the account
 * Visit: http://localhost/carlos-malaga/oauth.php
 */
session_start();
require_once __DIR__ . '/config.php';

// Step 2: TikTok redirected back with a code
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $codeVerifier = $_SESSION['tiktok_code_verifier'] ?? '';

    if (!$codeVerifier) {
        die('Error: code_verifier no encontrado en la sesión. Inténtalo de nuevo desde el inicio.');
    }

    $ch = curl_init('https://open.tiktokapis.com/v2/oauth/token/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_key' => TIKTOK_CLIENT_KEY,
            'client_secret' => TIKTOK_CLIENT_SECRET,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => TIKTOK_REDIRECT_URI,
            'code_verifier' => $codeVerifier,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    // Clean up session
    unset($_SESSION['tiktok_code_verifier']);

    if ($error) {
        die("Error de conexión: $error");
    }

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        $errorMsg = $data['error_description'] ?? $data['message'] ?? json_encode($data);
        die("Error al obtener token: $errorMsg");
    }

    saveToken($data);

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{font-family:sans-serif;background:#0a0a0a;color:#fff;display:flex;justify-content:center;align-items:center;height:100vh;margin:0}
    .box{text-align:center;padding:40px;background:#1a1a1a;border-radius:12px;max-width:400px}
    h2{color:#25f4ee;margin-bottom:12px}a{color:#fe2c55;text-decoration:none}</style></head>
    <body><div class="box"><h2>Cuenta autorizada</h2>
    <p>El token se ha guardado correctamente.</p>
    <p style="margin-top:16px"><a href="index.html">Ir al formulario &rarr;</a></p>
    </div></body></html>';
    exit;
}

// Handle error from TikTok
if (isset($_GET['error'])) {
    die('TikTok error: ' . ($_GET['error_description'] ?? $_GET['error']));
}

// Step 1: Generate PKCE and redirect to TikTok authorization
$codeVerifier = generateCodeVerifier();
$codeChallenge = generateCodeChallenge($codeVerifier);

// Store code_verifier in session for Step 2
$_SESSION['tiktok_code_verifier'] = $codeVerifier;

$params = http_build_query([
    'client_key' => TIKTOK_CLIENT_KEY,
    'scope' => 'video.upload,video.publish',
    'response_type' => 'code',
    'redirect_uri' => TIKTOK_REDIRECT_URI,
    'code_challenge' => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

header('Location: https://www.tiktok.com/v2/auth/authorize/?' . $params);
exit;

/**
 * Generate a random code_verifier (43-128 chars)
 */
function generateCodeVerifier(): string {
    $bytes = random_bytes(64);
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * Generate code_challenge from code_verifier using S256
 */
function generateCodeChallenge(string $verifier): string {
    $hash = hash('sha256', $verifier, true);
    return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
}
