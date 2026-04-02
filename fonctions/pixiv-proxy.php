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
    $allowed_tags = [];
    if ($gallery !== '' && is_valid_gallery_slug($gallery)) {
        $gdata = load_gallery($gallery);
        if ($gdata) $allowed_tags = array_column($gdata['characters'], 'tag');
    }
    if (empty($allowed_tags)) {
        foreach (list_galleries() as $g) {
            foreach ($g['characters'] as $char) $allowed_tags[] = $char['tag'];
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

/**
 * Retourne le chemin du fichier cache pour une clé donnée.
 */
function cache_path(string $key): string {
    return CACHE_DIR . '/' . $key . '.json';
}

/**
 * Lit le cache si valide, retourne null sinon.
 */
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

/**
 * Écrit une valeur en cache.
 */
function cache_set(string $key, string $value): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
        // Protège le dossier contre l'accès direct
        file_put_contents(CACHE_DIR . '/.htaccess', "Order allow,deny\nDeny from all\n");
    }
    file_put_contents(cache_path($key), $value, LOCK_EX);
}

// Construire la clé de cache (sans free_search ni gallery, qui n'affectent pas la réponse Pixiv)
$cache_key = hash('sha256', implode('|', [$tag, $page, $per_page, $order, $mode, $period]));

// Servir depuis le cache si disponible
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

// Tags indiquant une illustration générée par IA (filtre complémentaire à ai_type)
// Certains artistes ne cochent pas la case IA lors de la publication.
define('AI_TAGS', [
    'AI', 'AI-generated', 'AIart', 'AIartwork', 'AIgenerated',
    'AIアート', 'AIイラスト', 'AIのべりすと', 'ai少女',
    'AI生成', 'AI生成作品', 'AI絵', 'AI绘画',
]);

/**
 * Retourne true si l'œuvre comporte un tag IA connu.
 */
function has_ai_tag(array $work): bool {
    $workTags = $work['tags'] ?? [];
    foreach ($workTags as $t) {
        // Les tags Pixiv peuvent être une chaîne ou un tableau ['tag'=>…,'romaji'=>…]
        $tagName = is_array($t) ? ($t['tag'] ?? '') : (string)$t;
        if (in_array($tagName, AI_TAGS, true)) return true;
    }
    return false;
}

$raw_all   = $data['body']['illustManga']['data'] ?? [];
$total_raw = $data['body']['illustManga']['total'] ?? 0;

// Filtrer les œuvres IA (ai_type + tags explicites)
$filtered = array_values(array_filter($raw_all, function($work) {
    if (($work['aiType'] ?? 0) >= 2) return false;
    if (has_ai_tag($work)) return false;
    return true;
}));

$raw_works = array_slice($filtered, 0, $per_page);
// Ajuster le total estimé proportionnellement au ratio de filtrage
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

// Mettre en cache uniquement les réponses valides
cache_set($cache_key, $output);

header('X-Cache: MISS');
echo $output;
