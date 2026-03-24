<?php
// ============================================================
//  pixiv-proxy.php — Proxy serveur vers l'API Pixiv
//  Peut être appelé depuis la racine ou depuis galleries/
// ============================================================
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$tag      = trim($_GET['tag']      ?? '');
$page     = max(1, intval($_GET['page']     ?? 1));
$per_page = intval($_GET['per_page'] ?? PIXIV_DEFAULT_PER_PAGE);
$order    = $_GET['order'] ?? PIXIV_DEFAULT_ORDER;
$mode     = $_GET['mode']  ?? PIXIV_DEFAULT_MODE;
$gallery  = trim($_GET['gallery'] ?? '');   // slug de la galerie concernée

if (!in_array($per_page, [28, 56, 112], true)) $per_page = PIXIV_DEFAULT_PER_PAGE;
if (!in_array($order, ['popular_d', 'date_d'], true)) $order = PIXIV_DEFAULT_ORDER;
if (!in_array($mode, ['safe', 'r18', 'all'], true)) $mode = PIXIV_DEFAULT_MODE;

if ($tag === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre tag manquant.']);
    exit;
}

// Vérifie que le tag est bien dans la galerie demandée
$allowed_tags = [];
if ($gallery !== '' && is_valid_gallery_slug($gallery)) {
    $gdata = load_gallery($gallery);
    if ($gdata) {
        $allowed_tags = array_column($gdata['characters'], 'tag');
    }
}

// Fallback : parcourir toutes les galeries
if (empty($allowed_tags)) {
    foreach (list_galleries() as $g) {
        foreach ($g['characters'] as $char) {
            $allowed_tags[] = $char['tag'];
        }
    }
}

if (!in_array($tag, $allowed_tags, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Tag non autorisé.']);
    exit;
}

$params = http_build_query([
    'word'    => $tag,
    'order'   => $order,
    'mode'    => $mode,
    'p'       => $page,
    's_mode'  => 's_tag',
    'ai_type' => PIXIV_AI_TYPE,
    'lang'    => 'en',
], '', '&', PHP_QUERY_RFC3986);

$url = 'https://www.pixiv.net/ajax/search/artworks/' . rawurlencode($tag) . '?' . $params;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: application/json',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.pixiv.net/',
        'Cookie: PHPSESSID=' . PIXIV_PHPSESSID,
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    http_response_code(502);
    echo json_encode(['error' => 'Erreur réseau : ' . $curl_err]);
    exit;
}
if ($http_code !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Pixiv a répondu avec le code HTTP ' . $http_code]);
    exit;
}

$data = json_decode($response, true);
if (!$data || ($data['error'] ?? false)) {
    http_response_code(502);
    echo json_encode(['error' => 'Réponse invalide de Pixiv.']);
    exit;
}

$raw_works = $data['body']['illustManga']['data'] ?? [];
$total     = $data['body']['illustManga']['total'] ?? 0;
$raw_works = array_slice($raw_works, 0, $per_page);

$works = [];
foreach ($raw_works as $work) {
    $works[] = [
        'id'        => $work['id'],
        'title'     => $work['title'],
        'userName'  => $work['userName'],
        'userId'    => $work['userId'],
        'thumb'     => $work['url'] ?? '',
        'pageCount' => $work['pageCount'] ?? 1,
        'tags'      => $work['tags'] ?? [],
    ];
}

echo json_encode([
    'works'   => $works,
    'total'   => $total,
    'page'    => $page,
    'perPage' => $per_page,
]);
