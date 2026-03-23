<?php
// ============================================================
//  pixiv-proxy.php — Proxy serveur vers l'API Pixiv
//  Appelé en AJAX par galerie.php
//  Le cookie ne quitte jamais le serveur.
// ============================================================

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

// --- Validation des paramètres entrants ---
$tag      = trim($_GET['tag']      ?? '');
$page     = max(1, intval($_GET['page']     ?? 1));
$per_page = intval($_GET['per_page'] ?? PIXIV_DEFAULT_PER_PAGE);
$order    = $_GET['order'] ?? PIXIV_DEFAULT_ORDER;
$mode     = $_GET['mode']  ?? PIXIV_DEFAULT_MODE;

// Validation per_page
if (!in_array($per_page, [28, 56, 112], true)) $per_page = PIXIV_DEFAULT_PER_PAGE;

// Validation order
if (!in_array($order, ['popular_d', 'date_d'], true)) $order = PIXIV_DEFAULT_ORDER;

// Validation mode
if (!in_array($mode, ['safe', 'r18', 'all'], true)) $mode = PIXIV_DEFAULT_MODE;

if ($tag === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre tag manquant.']);
    exit;
}

// Vérifie que le tag demandé fait partie de la liste autorisée
$allowed_tags = array_column(PIXIV_CHARACTERS, 'tag');
if (!in_array($tag, $allowed_tags, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Tag non autorisé.']);
    exit;
}

// --- Construction de l'URL Pixiv AJAX ---
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

// --- Requête cURL vers Pixiv ---
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

// --- Extraction et nettoyage des données utiles ---
$raw_works = $data['body']['illustManga']['data'] ?? [];
$total     = $data['body']['illustManga']['total'] ?? 0;

// Pixiv renvoie toujours 60 résultats max par page ;
// on tronque côté serveur selon per_page demandé.
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
