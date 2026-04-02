<?php
// ============================================================
//  private/{slug}.php — Galerie privée par tags
//  Template généré dans private/ à la création de chaque galerie.
//  Nécessite d'être connecté à l'administration.
// ============================================================
require_once __DIR__ . '/../config.php';

$session_lifetime = 7 * 24 * 3600;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params(['lifetime' => $session_lifetime, 'path' => '/', 'secure' => isset($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start();
if (!isset($_SESSION['admin_ok'])) {
    header('Location: ../admin.php');
    exit;
}

define('PRIVATE_DIR', __DIR__);

$slug = basename(__FILE__, '.php');
$file = PRIVATE_DIR . '/' . $slug . '.json';

if (!file_exists($file)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Galerie introuvable</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:4rem;color:#888">';
    echo '<h1>404</h1><p>Galerie introuvable.</p><a href="../perso.php">← Espace perso</a></body></html>';
    exit;
}

$gallery = json_decode(file_get_contents($file), true);
if (!is_array($gallery)) { http_response_code(500); exit; }

$gallery_title = $gallery['title'];
$characters    = $gallery['characters'];

// ── Paramètres transmis au template commun ──────────────────
$page_title   = htmlspecialchars($gallery_title) . ' — Privé — Pixivorama';
$back_href    = '../perso.php';
$back_label   = 'Espace perso';
$is_private   = true;
$proxy_url    = '../fonctions/private-proxy.php';
$extra_params = 'type=tag&gallery=' . rawurlencode($slug);
$assets_path  = '../assets/';
$admin_href   = '../admin.php';
$index_href   = '../index.php';
$footer_links = [];
$admin_defs   = get_admin_gallery_defaults($SETTINGS);

require __DIR__ . '/../includes/gallery-page.php';
