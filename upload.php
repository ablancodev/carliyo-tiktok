<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Handle JSON body
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    // FormData (file upload)
    $input = $_POST;
}

$method = $input['method'] ?? '';

try {
    // Get token from server config (auto-refreshes if expired)
    $token = getAccessToken();

    switch ($method) {
        case 'url':
            handleUrlUpload($input, $token);
            break;
        case 'file_init':
            handleFileInit($input, $token);
            break;
        case 'file_upload':
            handleFileUpload($input);
            break;
        case 'status':
            handleStatusCheck($input, $token);
            break;
        case 'creator_info':
            handleCreatorInfo($token);
            break;
        default:
            throw new Exception('Método no válido');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * PULL_FROM_URL - TikTok pulls the video from a public URL
 */
function handleUrlUpload(array $input, string $token): void {
    $videoUrl = $input['video_url'] ?? '';

    if (!$videoUrl) {
        throw new Exception('URL del video es obligatoria');
    }

    $body = [
        'source_info' => [
            'source' => 'PULL_FROM_URL',
            'video_url' => $videoUrl,
        ],
    ];

    $postInfo = buildPostInfo($input);
    if ($postInfo) {
        $body['post_info'] = $postInfo;
    }

    $endpoint = getPublishEndpoint($input);

    $response = tiktokRequest($endpoint, $token, $body);

    $data = json_decode($response, true);

    if (isset($data['error']['code']) && $data['error']['code'] !== 'ok') {
        throw new Exception('TikTok API error: ' . ($data['error']['message'] ?? 'Unknown error'));
    }

    echo json_encode([
        'success' => true,
        'publish_id' => $data['data']['publish_id'] ?? null,
    ]);
}

/**
 * FILE_UPLOAD Step 1 - Initialize upload and get presigned URL
 */
function handleFileInit(array $input, string $token): void {
    $videoSize = (int)($input['video_size'] ?? 0);

    if (!$videoSize) {
        throw new Exception('Tamaño del video es obligatorio');
    }

    $body = [
        'source_info' => [
            'source' => 'FILE_UPLOAD',
            'video_size' => $videoSize,
            'chunk_size' => $videoSize,
            'total_chunk_count' => 1,
        ],
    ];

    $postInfo = buildPostInfo($input);
    if ($postInfo) {
        $body['post_info'] = $postInfo;
    }

    $endpoint = getPublishEndpoint($input);

    $response = tiktokRequest($endpoint, $token, $body);

    $data = json_decode($response, true);

    if (isset($data['error']['code']) && $data['error']['code'] !== 'ok') {
        throw new Exception('TikTok API error: ' . ($data['error']['message'] ?? 'Unknown error'));
    }

    echo json_encode([
        'success' => true,
        'publish_id' => $data['data']['publish_id'] ?? null,
        'upload_url' => $data['data']['upload_url'] ?? null,
    ]);
}

/**
 * FILE_UPLOAD Step 2 - Upload the actual video file to TikTok's presigned URL
 */
function handleFileUpload(array $input): void {
    $uploadUrl = $input['upload_url'] ?? ($_POST['upload_url'] ?? '');

    if (!$uploadUrl) {
        throw new Exception('Upload URL es obligatoria');
    }

    if (!isset($_FILES['video'])) {
        throw new Exception('No se ha recibido el archivo de video');
    }

    $file = $_FILES['video'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al recibir el archivo: ' . $file['error']);
    }

    $fileContent = file_get_contents($file['tmp_name']);
    $fileSize = $file['size'];

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: video/mp4',
            'Content-Length: ' . $fileSize,
            'Content-Range: bytes 0-' . ($fileSize - 1) . '/' . $fileSize,
        ],
        CURLOPT_TIMEOUT => 300,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Error de conexión: ' . $curlError);
    }

    if ($httpCode >= 400) {
        throw new Exception('Error al subir el video (HTTP ' . $httpCode . '): ' . $response);
    }

    echo json_encode(['success' => true]);
}

/**
 * Check publish status
 */
function handleStatusCheck(array $input, string $token): void {
    $publishId = $input['publish_id'] ?? '';

    if (!$publishId) {
        throw new Exception('Publish ID es obligatorio');
    }

    $response = tiktokRequest(
        'https://open.tiktokapis.com/v2/post/publish/status/fetch/',
        $token,
        ['publish_id' => $publishId]
    );

    $data = json_decode($response, true);
    echo json_encode($data, JSON_PRETTY_PRINT);
}

/**
 * Query creator info - needed before direct publish
 */
function handleCreatorInfo(string $token): void {
    $response = tiktokRequest(
        'https://open.tiktokapis.com/v2/post/publish/creator_info/query/',
        $token,
        new stdClass() // empty JSON object {}
    );

    $data = json_decode($response, true);
    echo json_encode($data, JSON_PRETTY_PRINT);
}

/**
 * Get the correct TikTok endpoint based on publish mode
 */
function getPublishEndpoint(array $input): string {
    $mode = $input['publish_mode'] ?? 'inbox';
    if ($mode === 'direct') {
        return 'https://open.tiktokapis.com/v2/post/publish/video/init/';
    }
    return 'https://open.tiktokapis.com/v2/post/publish/inbox/video/init/';
}

/**
 * Build post_info based on publish mode
 */
function buildPostInfo(array $input): ?array {
    $title = $input['title'] ?? '';
    $description = $input['description'] ?? '';
    $mode = $input['publish_mode'] ?? 'inbox';

    if ($mode === 'direct') {
        // Direct publish requires all these fields
        return [
            'title' => $description ?: $title, // TikTok uses title as caption
            'privacy_level' => 'SELF_ONLY', // Apps no auditadas solo pueden usar SELF_ONLY
            'disable_duet' => false,
            'disable_stitch' => false,
            'disable_comment' => false,
            'video_cover_timestamp_ms' => 1000,
            'brand_content_toggle' => false,
            'brand_organic_toggle' => false,
        ];
    }

    // Inbox mode - simple post_info
    if (!$title && !$description) return null;

    $info = [];
    if ($title) $info['title'] = $title;
    if ($description) $info['description'] = $description;

    return $info;
}

/**
 * Make a POST request to TikTok API
 */
function tiktokRequest(string $url, string $token, $body): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('Error de conexión con TikTok: ' . $curlError);
    }

    return $response;
}
