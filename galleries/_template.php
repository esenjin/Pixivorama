<?php
// ============================================================
//  galleries/{slug}.php — Page de galerie publique
//  Template généré dans galleries/ à la création de chaque galerie.
// ============================================================
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$slug    = basename(__FILE__, '.php');
$gallery = load_gallery($slug);

if (!$gallery) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Galerie introuvable</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:4rem;color:#888">';
    echo '<h1>404</h1><p>Galerie introuvable.</p><a href="../index.php">← Accueil</a></body></html>';
    exit;
}

$gallery_title = $gallery['title'];
$characters    = $gallery['characters'];

// ── Paramètres transmis au template commun ──────────────────
$page_title  = htmlspecialchars($gallery_title) . ' — Pixivorama';
$back_href   = '../index.php';
$back_label  = 'Galerie';
$is_private  = false;
$proxy_url   = '../fonctions/pixiv-proxy.php';
$extra_params = 'gallery=' . rawurlencode($slug);
$assets_path = '../assets/';
$admin_href  = '../admin.php';
$index_href  = '../index.php';

// Liens footer (dépôt Git + lien personnalisé si configuré)
$footer_links = [
    [
        'href'     => 'https://git.crystalyx.net/Esenjin_Asakha/Pixivorama',
        'label'    => 'Dépôt',
        'external' => true,
        'icon'     => '<svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>',
    ],
];
if (!empty($gallery['footer_link_label']) && !empty($gallery['footer_link_url'])) {
    $footer_links[] = [
        'href'     => $gallery['footer_link_url'],
        'label'    => $gallery['footer_link_label'],
        'external' => true,
        'icon'     => '<svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
    ];
}

// Préférences admin
$admin_defs = !empty($_SESSION['admin_ok']) ? get_admin_gallery_defaults($SETTINGS) : null;

require __DIR__ . '/../includes/gallery-page.php';
