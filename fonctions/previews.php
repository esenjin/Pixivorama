<?php
// ============================================================
//  previews.php — (Re)génération des snapshots d'aperçus
//  de galeries (page d'accueil + espace perso).
//
//  Les snapshots sont des pools d'URLs de vignettes pré-résolues,
//  stockés dans cache/previews/{slug}.json. Voir includes/galleries.php.
//
//  Modes :
//    GET  ?dry=1                    → liste des galeries + état des snapshots
//    GET  ?refresh=1&slug=SLUG      → régénère 1 snapshot (fire-and-forget,
//                                     déclenché par la page d'accueil si périmé)
//    POST action=regen              → régénère TOUS les snapshots (flux SSE)
//    POST action=regen&targets[]=…  → régénère des snapshots spécifiques
//
//  Tout est réservé aux administrateurs connectés.
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

// ── Collecte de toutes les galeries susceptibles d'avoir un aperçu ──

function collect_preview_targets(): array {
    $items = [];

    // Galeries publiques
    foreach (list_galleries() as $g) {
        $items[] = [
            'slug'  => $g['slug'],
            'title' => $g['title'] ?? $g['slug'],
            'scope' => 'public',
            'gtype' => 'tag',
        ];
    }

    // Galeries privées (tags + spéciales)
    if (is_dir(PRIVATE_DIR)) {
        foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
            $slug = basename($f, '.json');
            if (!is_valid_gallery_slug($slug)) continue;
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) continue;
            $items[] = [
                'slug'  => $slug,
                'title' => $d['title'] ?? $slug,
                'scope' => 'private',
                'gtype' => $d['type'] ?? 'tag',
            ];
        }
    }

    return $items;
}

/**
 * Régénère le snapshot d'une cible (publique ou privée).
 */
function regen_preview_target(array $item): int {
    if ($item['scope'] === 'public') {
        return regenerate_gallery_preview($item['slug']);
    }
    return regenerate_private_preview($item['slug'], PRIVATE_DIR);
}

// ── Refresh unitaire fire-and-forget ────────────────────────
//  Appelé en arrière-plan par index.php / private-home.php quand un
//  snapshot est périmé. On ferme la connexion au plus vite pour ne pas
//  faire attendre le déclencheur, puis on régénère.

if (isset($_GET['refresh'])) {
    $slug = trim($_GET['slug'] ?? '');
    header('Content-Type: application/json; charset=utf-8');

    if (!is_valid_gallery_slug($slug)) {
        http_response_code(400);
        echo json_encode(['error' => 'Slug invalide.']);
        exit;
    }

    // Répondre immédiatement puis continuer le travail en arrière-plan.
    echo json_encode(['ok' => true, 'queued' => $slug]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback : forcer l'envoi du buffer et détacher au mieux.
        @ob_end_flush();
        @flush();
    }
    ignore_user_abort(true);

    // Déterminer le scope depuis les fichiers existants.
    if (load_gallery($slug)) {
        regenerate_gallery_preview($slug);
    } elseif (file_exists(PRIVATE_DIR . '/' . $slug . '.json')) {
        regenerate_private_preview($slug, PRIVATE_DIR);
    }
    exit;
}

// ── Dry-run : liste des galeries + fraîcheur des snapshots ──

if (isset($_GET['dry'])) {
    header('Content-Type: application/json; charset=utf-8');
    $targets = collect_preview_targets();
    $result = array_map(function ($item) {
        $f       = preview_file($item['slug']);
        $exists  = file_exists($f);
        $mtime   = $exists ? filemtime($f) : 0;
        $pool    = $exists ? load_preview_pool($item['slug']) : [];
        return [
            'slug'    => $item['slug'],
            'title'   => $item['title'],
            'scope'   => $item['scope'],
            'gtype'   => $item['gtype'],
            'exists'  => $exists,
            'stale'   => preview_is_stale($item['slug']),
            'count'   => count($pool),
            'mtime'   => $mtime,
        ];
    }, $targets);
    echo json_encode(['galleries' => $result, 'count' => count($result)]);
    exit;
}

// ── POST : régénération de tous les snapshots via SSE ───────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Méthode non supportée.']);
    exit;
}

$targets_all = collect_preview_targets();
$targets     = $_POST['targets'] ?? null;

if (is_array($targets) && !empty($targets)) {
    $targets_all = array_values(array_filter(
        $targets_all,
        fn($g) => in_array($g['slug'], $targets, true)
    ));
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
if (ob_get_level()) ob_end_clean();

function sse(string $event, array $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

$total   = count($targets_all);
$success = 0;
$errors  = 0;

sse('start', ['total' => $total]);

foreach ($targets_all as $i => $item) {
    $count  = regen_preview_target($item);
    $ok     = $count > 0;
    if ($ok) $success++; else $errors++;

    sse('progress', [
        'slug'    => $item['slug'],
        'title'   => $item['title'],
        'scope'   => $item['scope'],
        'gtype'   => $item['gtype'],
        'status'  => $ok ? 'ok' : 'empty',
        'message' => $ok ? ($count . ' vignettes') : 'Aucune vignette (snapshot conservé)',
        'count'   => $count,
        'index'   => $i + 1,
        'total'   => $total,
        'percent' => (int) round(($i + 1) / max(1, $total) * 100),
    ]);
}

sse('done', [
    'total'   => $total,
    'success' => $success,
    'errors'  => $errors,
]);
exit;
