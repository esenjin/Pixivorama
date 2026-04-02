<?php
// ============================================================
//  regen.php — Régénération des fichiers PHP de galeries
//  Endpoint AJAX réservé aux administrateurs connectés.
//  Retourne un flux JSON (text/event-stream via SSE, ou JSON).
//
//  GET  ?dry=1          → simulation (ne touche rien, retourne la liste)
//  POST action=regen    → régénère tout
//  POST action=regen&targets[]=slug1&targets[]=slug2 → régénère des galeries spécifiques
// ============================================================
require_once __DIR__ . '/../config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}
if (!isset($_SESSION['admin_ok'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non autorisé.']);
    exit;
}

define('PRIVATE_DIR', __DIR__ . '/../private');

// ── Collecte de toutes les galeries à régénérer ─────────────

function collect_galleries(): array {
    $items = [];

    // Galeries publiques (galleries/)
    $pub_template = GALLERIES_DIR . '/_template.php';
    foreach (glob(GALLERIES_DIR . '/*.json') ?: [] as $f) {
        $slug = basename($f, '.json');
        if (!is_valid_gallery_slug($slug)) continue;
        $data = json_decode(file_get_contents($f), true);
        if (!is_array($data)) continue;
        $items[] = [
            'slug'     => $slug,
            'title'    => $data['title'] ?? $slug,
            'type'     => 'public',
            'template' => $pub_template,
            'dest'     => GALLERIES_DIR . '/' . $slug . '.php',
        ];
    }

    // Galeries privées (private/)
    if (is_dir(PRIVATE_DIR)) {
        foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
            $slug = basename($f, '.json');
            if (!is_valid_gallery_slug($slug)) continue;
            $data = json_decode(file_get_contents($f), true);
            if (!is_array($data)) continue;
            $gtype    = $data['type'] ?? 'tag';
            $template = ($gtype === 'tag')
                ? PRIVATE_DIR . '/_template.php'
                : PRIVATE_DIR . '/_special.php';
            $items[] = [
                'slug'     => $slug,
                'title'    => $data['title'] ?? $slug,
                'type'     => 'private:' . $gtype,
                'template' => $template,
                'dest'     => PRIVATE_DIR . '/' . $slug . '.php',
            ];
        }
    }

    return $items;
}

// ── Régénère un fichier PHP depuis son template ──────────────

function regen_one(array $item): array {
    $template = $item['template'];
    $dest     = $item['dest'];

    if (!file_exists($template)) {
        return [
            'slug'    => $item['slug'],
            'title'   => $item['title'],
            'type'    => $item['type'],
            'status'  => 'error',
            'message' => 'Template introuvable : ' . basename($template),
        ];
    }

    // Conserver la date de modification avant écrasement (pour le rapport)
    $was_existing = file_exists($dest);
    $mtime_before = $was_existing ? filemtime($dest) : null;

    if (!copy($template, $dest)) {
        return [
            'slug'    => $item['slug'],
            'title'   => $item['title'],
            'type'    => $item['type'],
            'status'  => 'error',
            'message' => 'Impossible d\'écrire ' . basename($dest),
        ];
    }

    return [
        'slug'    => $item['slug'],
        'title'   => $item['title'],
        'type'    => $item['type'],
        'status'  => 'ok',
        'message' => $was_existing ? 'Mis à jour' : 'Créé',
        'updated' => true,
    ];
}

// ── Dry-run : liste sans toucher les fichiers ────────────────

if (isset($_GET['dry'])) {
    header('Content-Type: application/json; charset=utf-8');
    $galleries = collect_galleries();
    $result = array_map(function ($item) {
        $template_ok = file_exists($item['template']);
        $dest_exists = file_exists($item['dest']);
        return [
            'slug'        => $item['slug'],
            'title'       => $item['title'],
            'type'        => $item['type'],
            'template_ok' => $template_ok,
            'dest_exists' => $dest_exists,
            'dest'        => basename(dirname($item['dest'])) . '/' . basename($item['dest']),
        ];
    }, $galleries);
    echo json_encode(['galleries' => $result, 'count' => count($result)]);
    exit;
}

// ── Health check : état de santé des galeries ───────────────
//  GET ?health=1  → vérifie sync PHP/template + accessibilité Pixiv

if (isset($_GET['health'])) {
    header('Content-Type: application/json; charset=utf-8');
    $galleries = collect_galleries();

    $results = [];
    foreach ($galleries as $item) {
        $template_ok  = file_exists($item['template']);
        $dest_exists  = file_exists($item['dest']);
        $template_mtime = $template_ok ? filemtime($item['template']) : 0;
        $dest_mtime     = $dest_exists  ? filemtime($item['dest'])     : 0;

        // Sync : le .php doit être >= au template (créé ou régénéré après)
        $sync_ok = $dest_exists && $template_ok && ($dest_mtime >= $template_mtime);
        $sync_status = !$dest_exists   ? 'missing'
                     : (!$template_ok  ? 'no_template'
                     : ($sync_ok       ? 'ok' : 'outdated'));

        // Premier tag disponible pour le test Pixiv (type tag uniquement)
        $pixiv_status = 'skipped';
        $pixiv_note   = '';

        // Charger le JSON pour récupérer le premier tag
        $json_path = ($item['type'] === 'public')
            ? GALLERIES_DIR . '/' . $item['slug'] . '.json'
            : PRIVATE_DIR   . '/' . $item['slug'] . '.json';

        $gdata = [];
        if (file_exists($json_path)) {
            $gdata = json_decode(file_get_contents($json_path), true) ?? [];
        }

        $gtype = $gdata['type'] ?? ($item['type'] === 'public' ? 'public' : 'tag');

        if (in_array($gtype, ['public', 'tag'], true)) {
            $first_tag = $gdata['characters'][0]['tag'] ?? null;
            if ($first_tag) {
                $ch = curl_init(
                    'https://www.pixiv.net/ajax/search/artworks/' . rawurlencode($first_tag)
                    . '?' . http_build_query([
                        'word' => $first_tag, 'order' => 'popular_d',
                        'mode' => 'safe', 'p' => 1, 's_mode' => 's_tag',
                        'ai_type' => 1, 'lang' => 'en',
                    ])
                );
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                        'Accept: application/json',
                        'Referer: https://www.pixiv.net/',
                        'Cookie: PHPSESSID=' . PIXIV_PHPSESSID,
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $resp     = curl_exec($ch);
                $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr     = curl_error($ch);
                curl_close($ch);

                if ($cerr) {
                    $pixiv_status = 'error';
                    $pixiv_note   = 'cURL : ' . $cerr;
                } elseif ($http !== 200) {
                    $pixiv_status = 'error';
                    $pixiv_note   = 'HTTP ' . $http;
                } else {
                    $pdata = json_decode($resp, true);
                    if (!$pdata || ($pdata['error'] ?? false)) {
                        $pixiv_status = 'error';
                        $pixiv_note   = $pdata['message'] ?? 'Réponse invalide';
                    } else {
                        $count = $pdata['body']['illustManga']['total'] ?? 0;
                        $pixiv_status = $count > 0 ? 'ok' : 'empty';
                        $pixiv_note   = $count > 0
                            ? $count . ' illust.'
                            : 'Aucun résultat';
                    }
                }
            }
        } else {
            $pixiv_note = ucfirst($gtype);
        }

        $results[] = [
            'slug'         => $item['slug'],
            'title'        => $item['title'],
            'type'         => $item['type'],
            'gtype'        => $gtype,
            'sync_status'  => $sync_status,
            'pixiv_status' => $pixiv_status,
            'pixiv_note'   => $pixiv_note,
            'dest_mtime'   => $dest_mtime,
            'tpl_mtime'    => $template_mtime,
        ];
    }

    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

// ── POST : régénération effective via SSE ────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Méthode non supportée.']);
    exit;
}

$all_galleries = collect_galleries();
$targets       = $_POST['targets'] ?? null;

// Filtrer si des cibles spécifiques sont demandées
if (is_array($targets) && !empty($targets)) {
    $all_galleries = array_values(array_filter(
        $all_galleries,
        fn($g) => in_array($g['slug'], $targets, true)
    ));
}

// Désactiver le buffering pour le streaming SSE
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // Nginx
if (ob_get_level()) ob_end_clean();

function sse(string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

$total   = count($all_galleries);
$success = 0;
$errors  = 0;

sse('start', ['total' => $total]);

foreach ($all_galleries as $i => $item) {
    $result = regen_one($item);

    if ($result['status'] === 'ok') $success++;
    else $errors++;

    sse('progress', array_merge($result, [
        'index'   => $i + 1,
        'total'   => $total,
        'percent' => (int) round(($i + 1) / max(1, $total) * 100),
    ]));

    // Petite pause pour rendre le flux perceptible sur des listes courtes
    usleep(80000); // 80 ms
}

sse('done', [
    'total'   => $total,
    'success' => $success,
    'errors'  => $errors,
]);
exit;
