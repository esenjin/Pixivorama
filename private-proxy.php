<?php
// ============================================================
//  private-proxy.php — Proxy serveur vers les endpoints Pixiv privés
//  Réservé aux administrateurs connectés (session admin_ok).
//  Gère :
//    - type=tag      → recherche classique (galeries privées par tag)
//    - type=illust   → mes illustrations
//    - type=bookmark → mes bookmarks
//    - type=history  → mon historique de navigation
//    - type=following → illustrations des artistes suivis
// ============================================================
require_once __DIR__ . '/config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}

define('PRIVATE_DIR', __DIR__ . '/private');

header('Content-Type: application/json; charset=utf-8');

$type     = trim($_GET['type']     ?? 'tag');
$page     = max(1, intval($_GET['page']     ?? 1));
$per_page = intval($_GET['per_page'] ?? PIXIV_DEFAULT_PER_PAGE);
$order    = $_GET['order'] ?? PIXIV_DEFAULT_ORDER;

if (!in_array($per_page, [28, 56, 112], true)) $per_page = PIXIV_DEFAULT_PER_PAGE;
if (!in_array($order, ['popular_d', 'date_d'], true)) $order = PIXIV_DEFAULT_ORDER;

// Extraire l'userId depuis le PHPSESSID (format : userId_...)
$phpsessid = PIXIV_PHPSESSID;
$userId    = null;
if (preg_match('/^(\d+)_/', $phpsessid, $m)) {
    $userId = $m[1];
}

if (!$userId) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible de déterminer le userId depuis le PHPSESSID.']);
    exit;
}

// ── Construire l'URL selon le type ──────────────────────────

$url = '';

switch ($type) {

    // ── Galerie privée par tag ───────────────────────────────
case 'tag':
        $tag     = trim($_GET['tag']     ?? '');
        $gallery = trim($_GET['gallery'] ?? '');
        $period  = trim($_GET['period']  ?? '');
        $mode = $_GET['mode'] ?? PIXIV_DEFAULT_MODE;
        if (!in_array($mode, ['safe', 'r18', 'all'], true)) $mode = PIXIV_DEFAULT_MODE;

        if ($tag === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètre tag manquant.']);
            exit;
        }

        $allowed = [];
        if ($gallery !== '' && is_valid_gallery_slug($gallery)) {
            $gfile = PRIVATE_DIR . '/' . $gallery . '.json';
            if (file_exists($gfile)) {
                $gdata = json_decode(file_get_contents($gfile), true);
                if (is_array($gdata)) {
                    $allowed = array_column($gdata['characters'] ?? [], 'tag');
                }
            }
        }
        if (empty($allowed)) {
            foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
                $d = json_decode(file_get_contents($f), true);
                if (!is_array($d) || ($d['type'] ?? 'tag') !== 'tag') continue;
                foreach (($d['characters'] ?? []) as $c) $allowed[] = $c['tag'];
            }
        }
        if (!in_array($tag, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Tag non autorisé.']);
            exit;
        }

        // Date de début selon la période
        $scd = '';
        if ($order === 'popular_d' && $period !== '') {
            $periodMap = [
                'year'   => '-1 year',
                '6month' => '-6 months',
                'month'  => '-1 month',
                'week'   => '-7 days',
                'day'    => '-1 day',
            ];
            if (isset($periodMap[$period])) {
                $scd = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify($periodMap[$period])
                    ->format('Y-m-d');
            }
        }

        $params_arr = [
            'word'   => $tag,
            'order'  => $order,
            'mode' => $mode,
            'p'      => $page,
            's_mode' => 's_tag',
            'lang'   => 'en',
        ];
        if ($scd !== '') $params_arr['scd'] = $scd;

        $params = http_build_query($params_arr, '', '&', PHP_QUERY_RFC3986);
        $url = 'https://www.pixiv.net/ajax/search/artworks/' . rawurlencode($tag) . '?' . $params;
        break;

    // ── Mes illustrations ────────────────────────────────────
    // Endpoint 1 : récupère tous les IDs
    // Endpoint 2 : récupère les détails pour une tranche d'IDs
    case 'illust':
        $params = http_build_query(['lang' => 'en']);
        $url = 'https://www.pixiv.net/ajax/user/' . $userId . '/profile/all?' . $params;
        break;

    // ── Mes bookmarks ────────────────────────────────────────
    // Endpoint correct : /ajax/user/{id}/illusts/bookmarks
    case 'bookmark':
        $params = http_build_query([
            'tag'    => '',
            'offset' => ($page - 1) * $per_page,
            'limit'  => $per_page,
            'rest'   => 'show',   // 'show' = public, 'hide' = privé
            'lang'   => 'en',
        ], '', '&', PHP_QUERY_RFC3986);
        $url = 'https://www.pixiv.net/ajax/user/' . $userId . '/illusts/bookmarks?' . $params;
        break;

    // ── Mon historique ───────────────────────────────────────
    // Endpoint : /ajax/user/{userId}/illusts/history
    case 'history':
        $params = http_build_query([
            'p'     => $page,
            'limit' => $per_page,
            'lang'  => 'en',
        ]);
        $url = 'https://www.pixiv.net/ajax/user/' . $userId . '/illusts/history?' . $params;
        break;

    // ── Illustrations des artistes suivis ────────────────────
    case 'following':
        $params = http_build_query([
            'p'    => $page,
            'mode' => 'all',
            'lang' => 'en',
        ]);
        $url = 'https://www.pixiv.net/ajax/follow_latest/illust?' . $params;
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Type inconnu : ' . htmlspecialchars($type)]);
        exit;
}

// ── Appel cURL ──────────────────────────────────────────────

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
        'Cookie: PHPSESSID=' . $phpsessid,
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
    $msg = $data['message'] ?? 'Réponse invalide de Pixiv.';
    echo json_encode(['error' => $msg]);
    exit;
}

// ── Normalisation de la réponse selon le type ───────────────

$works = [];
$total = 0;

switch ($type) {

    case 'tag':
        $raw   = $data['body']['illustManga']['data'] ?? [];
        $total = $data['body']['illustManga']['total'] ?? 0;
        $raw   = array_slice($raw, 0, $per_page);
        foreach ($raw as $w) {
            $works[] = normalise_work($w);
        }
        break;

    case 'illust':
        // profile/all retourne tous les IDs sous body.illusts (objet {id: null, ...})
        $illustIds = array_keys($data['body']['illusts'] ?? []);
        // Trier par ID décroissant (plus récent en premier)
        rsort($illustIds, SORT_NUMERIC);
        $total = count($illustIds);
        $slice = array_slice($illustIds, ($page - 1) * $per_page, $per_page);
        if (!empty($slice)) {
            $works = fetch_works_by_ids($slice, $userId, $phpsessid);
        }
        break;

    case 'bookmark':
        // body.works = tableau d'œuvres, body.total = total
        $raw   = $data['body']['works'] ?? [];
        $total = $data['body']['total'] ?? 0;
        foreach ($raw as $w) {
            // Les bookmarks peuvent contenir des entrées nulles (œuvres supprimées)
            if (!is_array($w) || empty($w['id'])) continue;
            $works[] = normalise_work($w);
        }
        break;

    case 'history':
        // body.works = tableau, body.total peut exister ou non
        $raw   = $data['body']['works'] ?? [];
        $total = $data['body']['total'] ?? count($raw);
        foreach ($raw as $w) {
            if (!is_array($w) || empty($w['id'])) continue;
            $works[] = normalise_work($w);
        }
        break;

    case 'following':
        // L'API retourne une page fixe d'illustrations (per_page ignoré côté Pixiv).
        // On retourne tout ce que Pixiv donne sans sous-pagination.
        // total = count exact → le JS n'affiche pas de pagination.
        $thumbMap = [];
        foreach (($data['body']['thumbnails']['illust'] ?? []) as $w) {
            if (isset($w['id'])) $thumbMap[(string)$w['id']] = $w;
        }
        $ids = $data['body']['page']['ids'] ?? [];
        foreach ($ids as $id) {
            $key = (string)$id;
            if (isset($thumbMap[$key])) {
                $works[] = normalise_work($thumbMap[$key]);
            }
        }
        $total = count($works);
        break;
}

echo json_encode([
    'works'   => $works,
    'total'   => $total,
    'page'    => $page,
    'perPage' => $per_page,
]);

// ── Helpers ─────────────────────────────────────────────────

function normalise_work(array $w): array {
    $thumb = $w['url'] ?? $w['thumbnail_url'] ?? '';
    if (empty($thumb) && isset($w['image_urls']['medium'])) {
        $thumb = $w['image_urls']['medium'];
    }
    return [
        'id'        => (string)($w['id']        ?? ''),
        'title'     => (string)($w['title']     ?? ''),
        'userName'  => (string)($w['userName']  ?? $w['user_name']  ?? ''),
        'userId'    => (string)($w['userId']    ?? $w['user_id']    ?? ''),
        'thumb'     => (string)$thumb,
        'pageCount' => (int)  ($w['pageCount']  ?? $w['page_count'] ?? 1),
        'tags'      => (array)($w['tags']       ?? []),
    ];
}

function fetch_works_by_ids(array $ids, string $userId, string $phpsessid): array {
    $qs = 'work_category=illust&is_first_page=0&lang=en';
    foreach ($ids as $id) {
        $qs .= '&ids[]=' . rawurlencode((string)$id);
    }
    $url = 'https://www.pixiv.net/ajax/user/' . $userId . '/profile/illusts?' . $qs;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Accept-Language: en-US,en;q=0.9',
            'Referer: https://www.pixiv.net/',
            'Cookie: PHPSESSID=' . $phpsessid,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $d = json_decode($resp, true);
    if (!$d || ($d['error'] ?? false)) return [];

    $works = [];
    foreach (($d['body']['works'] ?? []) as $w) {
        if (is_array($w) && !empty($w['id'])) {
            $works[] = normalise_work($w);
        }
    }
    return $works;
}
