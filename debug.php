<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    $token = getAccessToken();

    if ($action === 'creator_info') {
        $ch = curl_init('https://open.tiktokapis.com/v2/post/publish/creator_info/query/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        echo $response;
    } elseif ($action === 'test_direct') {
        // Test direct publish with minimal body
        $body = [
            'post_info' => [
                'title' => 'Test #test',
                'privacy_level' => 'SELF_ONLY',
                'disable_duet' => false,
                'disable_stitch' => false,
                'disable_comment' => false,
                'video_cover_timestamp_ms' => 1000,
                'brand_content_toggle' => false,
                'brand_organic_toggle' => false,
            ],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => 1000000,
                'chunk_size' => 1000000,
                'total_chunk_count' => 1,
            ],
        ];

        $ch = curl_init('https://open.tiktokapis.com/v2/post/publish/video/init/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json; charset=UTF-8',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        echo $response;
    } else {
        echo json_encode([
            'usage' => '?action=creator_info o ?action=test_direct',
            'token_exists' => file_exists(TOKEN_FILE),
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
