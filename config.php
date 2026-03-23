<?php
// ============================================================
//  config.php — Configuration principale
//  Les réglages dynamiques sont stockés dans settings.json
//  (créé automatiquement à la première utilisation).
// ============================================================

// --- Fichier de persistance des réglages dynamiques ---
define('SETTINGS_FILE', __DIR__ . '/settings.json');

// --- Chargement / initialisation des réglages ---
function load_settings(): array {
    if (file_exists(SETTINGS_FILE)) {
        $data = json_decode(file_get_contents(SETTINGS_FILE), true);
        if (is_array($data)) return $data;
    }
    // Valeurs par défaut (premier lancement)
    return [
        'phpsessid'  => '12345678_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789abcdef', // Valeur fictive, à remplacer par l'utilisateur
        'admin_hash' => password_hash('admin', PASSWORD_DEFAULT),
        'gallery_title' => 'Illustrations',
        'characters' => [
            ['label' => 'Hitagi Senjougahara', 'tag' => '戦場ヶ原ひたぎ'],
            ['label' => 'Exemple : Hatsune Miku', 'tag' => '初音ミク'],
        ],
    ];
}

function save_settings(array $data): bool {
    return file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Chargement global
$SETTINGS = load_settings();

// --- Constantes dérivées ---
define('PIXIV_PHPSESSID',  $SETTINGS['phpsessid']);
define('PIXIV_CHARACTERS', $SETTINGS['characters']);

define('PIXIV_DEFAULT_PER_PAGE', 28);
define('PIXIV_DEFAULT_ORDER',    'popular_d');
define('PIXIV_DEFAULT_MODE',     'safe');
define('PIXIV_AI_TYPE',          1);
define('PIXIV_GALLERY_TITLE', $SETTINGS['gallery_title'] ?? 'Illustrations');
