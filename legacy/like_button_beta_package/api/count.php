<?php
require '../config.php';
require '../classes/LikeService.php';

header('Content-Type: application/json');

$pageId = $_GET['page_id'] ?? '';

$service = new LikeService($pdo);

echo json_encode([
    'count' => $service->getCount($pageId)
]);
