<?php
// ============================================================
//  pixiv-proxy.php — Proxy serveur vers l'API Pixiv
//  Inclut un cache fichier côté serveur (TTL configurable).
// ============================================================
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$tag         = trim($_GET['tag']         ?? '');
$page        = max(1, intval($_GET['page']     ?? 1));
$per_page    = intval($_GET['per_page'] ?? PIXIV_DEFAULT_PER_PAGE);
$order       = $_GET['order']   ?? PIXIV_DEFAULT_ORDER;
$mode        = $_GET['mode']    ?? PIXIV_DEFAULT_MODE;
$gallery     = trim($_GET['gallery']    ?? '');
$period      = trim($_GET['period']     ?? '');
$free_search = !empty($_GET['free_search']);
$is_private  = !empty($_GET['private']);

if (!in_array($per_page, [28, 56], true)) $per_page = PIXIV_DEFAULT_PER_PAGE;
if (!in_array($order, ['popular_d', 'date_d'], true)) $order = PIXIV_DEFAULT_ORDER;
if (!in_array($mode, ['safe', 'r18', 'all'], true)) $mode = PIXIV_DEFAULT_MODE;

if ($tag === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre tag manquant.']);
    exit;
}

// Vérifie que le tag appartient à une galerie autorisée
if (!$free_search) {
    // Détecte si la requête vient d'une session admin (pour les aperçus des galeries privées)
    $is_admin_session = false;
    if ($is_private) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $is_admin_session = !empty($_SESSION['admin_ok']);
    }

    $allowed_tags = [];
    if ($gallery !== '' && is_valid_gallery_slug($gallery)) {
        // Cherche d'abord dans les galeries publiques
        $gdata = load_gallery($gallery);
        if ($gdata) {
            $allowed_tags = array_column($gdata['characters'], 'tag');
        } elseif ($is_admin_session) {
            // Cherche dans les galeries privées si session admin
            $priv_file = __DIR__ . '/../private/' . $gallery . '.json';
            if (file_exists($priv_file)) {
                $priv_data = json_decode(file_get_contents($priv_file), true);
                if (is_array($priv_data)) {
                    $allowed_tags = array_column($priv_data['characters'] ?? [], 'tag');
                }
            }
        }
    }
    if (empty($allowed_tags)) {
        // Collecte tous les tags des galeries publiques
        foreach (list_galleries() as $g) {
            foreach ($g['characters'] as $char) $allowed_tags[] = $char['tag'];
        }
        // Si admin, inclut aussi tous les tags des galeries privées par tags
        if ($is_admin_session) {
            $priv_dir = __DIR__ . '/../private';
            if (is_dir($priv_dir)) {
                foreach (glob($priv_dir . '/*.json') ?: [] as $f) {
                    $slug = basename($f, '.json');
                    if (!is_valid_gallery_slug($slug)) continue;
                    $d = json_decode(file_get_contents($f), true);
                    if (!is_array($d) || ($d['type'] ?? 'tag') !== 'tag') continue;
                    foreach ($d['characters'] ?? [] as $char) {
                        $allowed_tags[] = $char['tag'];
                    }
                }
            }
        }
    }
    if (!in_array($tag, $allowed_tags, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Tag non autorisé.']);
        exit;
    }
}

// ── Cache fichier ────────────────────────────────────────────

define('CACHE_DIR', __DIR__ . '/../cache');
define('CACHE_TTL', 600); // secondes (10 minutes)

function cache_path(string $key): string {
    return CACHE_DIR . '/' . $key . '.json';
}

function cache_get(string $key): ?string {
    $path = cache_path($key);
    if (!file_exists($path)) return null;
    if (time() - filemtime($path) > CACHE_TTL) {
        @unlink($path);
        return null;
    }
    $data = file_get_contents($path);
    return $data !== false ? $data : null;
}

function cache_set(string $key, string $value): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
        file_put_contents(CACHE_DIR . '/.htaccess', "Order allow,deny\nDeny from all\n");
    }
    file_put_contents(cache_path($key), $value, LOCK_EX);
}

$cache_key = hash('sha256', implode('|', [$tag, $page, $per_page, $order, $mode, $period]));

$cached = cache_get($cache_key);
if ($cached !== null) {
    header('X-Cache: HIT');
    echo $cached;
    exit;
}

// ── Date de début selon la période (scd) ──
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

$params = [
    'word'    => $tag,
    'order'   => $order,
    'mode'    => $mode,
    'p'       => $page,
    's_mode'  => 's_tag',
    'ai_type' => PIXIV_AI_TYPE,
    'lang'    => 'en',
];
if ($scd !== '') $params['scd'] = $scd;

$url = 'https://www.pixiv.net/ajax/search/artworks/' . rawurlencode($tag) . '?'
     . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

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

if ($curl_err) { http_response_code(502); echo json_encode(['error' => 'Erreur réseau : ' . $curl_err]); exit; }
if ($http_code !== 200) { http_response_code(502); echo json_encode(['error' => 'Pixiv HTTP ' . $http_code]); exit; }

$data = json_decode($response, true);
if (!$data || ($data['error'] ?? false)) { http_response_code(502); echo json_encode(['error' => 'Réponse invalide de Pixiv.']); exit; }

function has_ai_tag(array $work): bool {
    $blocked  = get_blocked_tags();
    $workTags = $work['tags'] ?? [];
    foreach ($workTags as $t) {
        $tagName = is_array($t) ? ($t['tag'] ?? '') : (string)$t;
        if (in_array($tagName, $blocked, true)) return true;
    }
    return false;
}

$raw_all   = $data['body']['illustManga']['data'] ?? [];
$total_raw = $data['body']['illustManga']['total'] ?? 0;

$filtered = array_values(array_filter($raw_all, function($work) {
    if (($work['aiType'] ?? 0) >= 2) return false;
    if (has_ai_tag($work)) return false;
    return true;
}));

$raw_works = array_slice($filtered, 0, $per_page);
$total = ($total_raw > 0 && count($raw_all) > 0)
    ? (int) round($total_raw * count($filtered) / count($raw_all))
    : count($filtered);

$works = [];
foreach ($raw_works as $work) {
    $works[] = [
        'id'          => $work['id'],
        'title'       => $work['title'],
        'userName'    => $work['userName'],
        'userId'      => $work['userId'],
        'thumb'       => $work['url'] ?? '',
        'pageCount'   => $work['pageCount'] ?? 1,
        'tags'        => $work['tags'] ?? [],
        'xRestrict'   => $work['xRestrict'] ?? 0,
        'illustType'  => $work['illustType'] ?? 0,
    ];
}

$output = json_encode(['works' => $works, 'total' => $total, 'page' => $page, 'perPage' => $per_page]);

cache_set($cache_key, $output);

header('X-Cache: MISS');
echo $output;
