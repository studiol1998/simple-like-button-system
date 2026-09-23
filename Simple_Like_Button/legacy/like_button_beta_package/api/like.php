<?php
require '../config.php';
require '../classes/LikeService.php';

header('Content-Type: application/json');

$service = new LikeService($pdo);

$data = json_decode(file_get_contents('php://input'), true);
$pageId = trim($data['page_id'] ?? '');

/* page_id未指定 */
if ($pageId === '') {
    echo json_encode([
        'success' => false,
        'message' => 'page_id missing'
    ]);
    exit;
}

$setting = $service->getMode();

/* システム停止中 */
if ($setting['mode'] === 'disabled') {
    echo json_encode([
        'success' => false,
        'message' => '利用できません'
    ]);
    exit;
}

/* メンテナンスモード */
if ($setting['mode'] === 'maintenance') {
    echo json_encode([
        'success' => false,
        'message' => $setting['message']
    ]);
    exit;
}

/* 6時間10回制限 */
if (!$service->canLike()) {
    echo json_encode([
        'success' => false,
        'message' => '6時間で10回までです'
    ]);
    exit;
}

$service->addLike($pageId);

echo json_encode([
    'success' => true
]);
