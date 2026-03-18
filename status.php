<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$publishId = $_GET['id'] ?? '';

if (!$publishId) {
    die(json_encode(['error' => 'Usa ?id=TU_PUBLISH_ID']));
}

try {
    $token = getAccessToken();

    $ch = curl_init('https://open.tiktokapis.com/v2/post/publish/status/fetch/');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['publish_id' => $publishId]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    echo $response;
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
