<?php
// ============================================================
//  fonctions/seen.php — Gestion des illustrations vues
//  (galerie Artistes suivis)
//
//  Remplace le localStorage par une base SQLite côté serveur,
//  permettant la synchronisation entre tous les appareils.
//
//  Méthodes :
//    GET  ?action=load            → { seen: { id: timestamp, … } }
//    POST action=mark  ids=[…]   → { ok: true, count: N }
//    POST action=purge            → { ok: true, deleted: N }  (usage admin)
//
//  Accès réservé aux sessions administrateur.
// ============================================================
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth ────────────────────────────────────────────────────
$session_lifetime = 7 * 24 * 3600;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (!isset($_SESSION['admin_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

// ── Base de données ──────────────────────────────────────────
define('SEEN_DB_FILE', __DIR__ . '/../data/seen.db');

/**
 * Ouvre (ou crée) la base SQLite et retourne le PDO.
 */
function open_seen_db(): PDO {
    $dir = dirname(SEEN_DB_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $db = new PDO('sqlite:' . SEEN_DB_FILE);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Création de la table si elle n'existe pas encore
    $db->exec('
        CREATE TABLE IF NOT EXISTS seen_illusts (
            illust_id TEXT PRIMARY KEY,
            seen_at   INTEGER NOT NULL
        )
    ');

    return $db;
}

/**
 * Retourne le TTL de purge en jours (depuis settings.json).
 * Valeur par défaut : 90 jours.
 */
function get_seen_ttl(): int {
    global $SETTINGS;
    $ttl = (int)($SETTINGS['seen_ttl_days'] ?? 90);
    return max(1, $ttl); // minimum 1 jour
}

// ── Routage ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET'
    ? ($_GET['action']  ?? 'load')
    : ($_POST['action'] ?? '');

try {
    $db = open_seen_db();

    // ── GET load — retourne tous les IDs vus non expirés ──
    if ($method === 'GET' && $action === 'load') {
        $ttl     = get_seen_ttl();
        $cutoff  = time() - $ttl * 86400;

        // Purge au passage (silencieuse)
        $db->prepare('DELETE FROM seen_illusts WHERE seen_at < ?')->execute([$cutoff]);

        $rows = $db->query('SELECT illust_id, seen_at FROM seen_illusts')->fetchAll(PDO::FETCH_ASSOC);

        $seen = [];
        foreach ($rows as $row) {
            $seen[$row['illust_id']] = (int)$row['seen_at'];
        }

        echo json_encode(['ok' => true, 'seen' => $seen, 'ttl_days' => $ttl]);
        exit;
    }

    // ── POST mark — enregistre un tableau d'IDs comme vus ──
    if ($method === 'POST' && $action === 'mark') {
        $raw = $_POST['ids'] ?? '';
        $ids = is_array($raw)
            ? $raw
            : json_decode($raw, true);

        if (!is_array($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Paramètre ids invalide']);
            exit;
        }

        $now  = time();
        $stmt = $db->prepare('
            INSERT INTO seen_illusts (illust_id, seen_at)
            VALUES (?, ?)
            ON CONFLICT(illust_id) DO UPDATE SET seen_at = excluded.seen_at
        ');

        $count = 0;
        foreach ($ids as $id) {
            $id = (string)$id;
            if ($id === '') continue;
            $stmt->execute([$id, $now]);
            $count++;
        }

        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    // ── POST purge — supprime les entrées expirées (admin) ──
    if ($method === 'POST' && $action === 'purge') {
        $ttl    = get_seen_ttl();
        $cutoff = time() - $ttl * 86400;
        $stmt   = $db->prepare('DELETE FROM seen_illusts WHERE seen_at < ?');
        $stmt->execute([$cutoff]);
        $deleted = $stmt->rowCount();
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}