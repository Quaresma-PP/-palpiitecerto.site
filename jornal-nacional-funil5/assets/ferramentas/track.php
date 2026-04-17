<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../../painel/config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['page_slug']) || empty($input['visitor_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$validPages = array_keys(FUNNEL_PAGES);
$pageSlug = $input['page_slug'];
if (!in_array($pageSlug, $validPages, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid page_slug']);
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$deviceType = 'desktop';
if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) {
    $deviceType = 'tablet';
} elseif (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry/i', $ua)) {
    $deviceType = 'mobile';
}

$funnelId = (strpos(__DIR__, 'funil2') !== false) ? 2 : 1;

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) {
    $ip = trim(explode(',', $ip)[0]);
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO pageviews (page_slug, visitor_id, session_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term, referrer, user_agent, ip_address, device_type, funnel_id, created_at)
        VALUES (:page_slug, :visitor_id, :session_id, :utm_source, :utm_medium, :utm_campaign, :utm_content, :utm_term, :referrer, :user_agent, :ip_address, :device_type, :funnel_id, NOW())
    ");

    $stmt->execute([
        ':page_slug'    => $pageSlug,
        ':funnel_id'    => $funnelId,
        ':visitor_id'   => substr($input['visitor_id'], 0, 36),
        ':session_id'   => substr($input['session_id'] ?? '', 0, 36),
        ':utm_source'   => substr($input['utm_source'] ?? '', 0, 255) ?: null,
        ':utm_medium'   => substr($input['utm_medium'] ?? '', 0, 255) ?: null,
        ':utm_campaign' => substr($input['utm_campaign'] ?? '', 0, 255) ?: null,
        ':utm_content'  => substr($input['utm_content'] ?? '', 0, 255) ?: null,
        ':utm_term'     => substr($input['utm_term'] ?? '', 0, 255) ?: null,
        ':referrer'     => substr($input['referrer'] ?? '', 0, 2048) ?: null,
        ':user_agent'   => substr($ua, 0, 512),
        ':ip_address'   => substr($ip, 0, 45),
        ':device_type'  => $deviceType,
    ]);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
