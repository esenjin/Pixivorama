<?php
// ============================================================
//  config.php — Configuration principale
//
//  Ce fichier définit les constantes et charge les réglages
//  dynamiques (settings.json). La logique métier est répartie
//  dans les fichiers suivants :
//
//    auth.php               — remember-me multi-appareils
//    includes/galleries.php — CRUD galeries + helpers
// ============================================================

define('SETTINGS_FILE', __DIR__ . '/settings.json');
define('GALLERIES_DIR', __DIR__ . '/galleries');

define('APP_VERSION', '1.4.0');

// ── Constantes Pixiv ────────────────────────────────────────

define('PIXIV_DEFAULT_PER_PAGE', 28);
define('PIXIV_DEFAULT_ORDER',    'popular_d');
define('PIXIV_DEFAULT_MODE',     'safe');
define('PIXIV_AI_TYPE',          1);

// ── Réglages dynamiques ──────────────────────────────────────

function load_settings(): array {
    if (file_exists(SETTINGS_FILE)) {
        $data = json_decode(file_get_contents(SETTINGS_FILE), true);
        if (is_array($data)) return $data;
    }
    return [
        'phpsessid'  => '12345678_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789abcdef',
        'admin_hash' => password_hash('admin', PASSWORD_DEFAULT),
    ];
}

function save_settings(array $data): bool {
    return file_put_contents(
        SETTINGS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

$SETTINGS = load_settings();
define('PIXIV_PHPSESSID', $SETTINGS['phpsessid']);

// ── Inclusions ───────────────────────────────────────────────

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/galleries.php';
