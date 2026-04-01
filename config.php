<?php
// ============================================================
//  config.php — Configuration principale
//  Les réglages dynamiques sont dans settings.json.
//  Chaque galerie est un fichier JSON dans galleries/.
// ============================================================

define('SETTINGS_FILE',  __DIR__ . '/settings.json');
define('GALLERIES_DIR',  __DIR__ . '/galleries');

define('APP_VERSION', '1.4.0');

// ── Réglages globaux ────────────────────────────────────────

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
    return file_put_contents(SETTINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

$SETTINGS = load_settings();
define('PIXIV_PHPSESSID', $SETTINGS['phpsessid']);

// ── Remember-me (multi-appareils) ───────────────────────────
//
//  Chaque appareil reçoit un token distinct. Ils sont stockés
//  sous la clé "remember_tokens" dans settings.json :
//
//    "remember_tokens": {
//      "<sha256 du token>": <timestamp d'expiration Unix>
//    }
//
//  Un maximum de REMEMBER_MAX_TOKENS tokens simultanés est
//  conservé ; les plus anciens sont évincés au-delà.
//  Les tokens expirés sont purgés à chaque lecture/écriture.

define('REMEMBER_COOKIE',     'pxv_rm');
define('REMEMBER_TTL',        7 * 24 * 3600); // 7 jours
define('REMEMBER_MAX_TOKENS', 10);            // appareils simultanés max

/**
 * Retourne le tableau des tokens valides (expirés purgés).
 * Structure : [ sha256_hash => expiry_timestamp, ... ]
 */
function remember_tokens(): array {
    global $SETTINGS;
    $tokens = $SETTINGS['remember_tokens'] ?? [];

    // Compatibilité avec l'ancien format à token unique
    if (isset($SETTINGS['remember_hash']) && isset($SETTINGS['remember_exp'])) {
        if (time() < $SETTINGS['remember_exp']) {
            $tokens[$SETTINGS['remember_hash']] = $SETTINGS['remember_exp'];
        }
        unset($SETTINGS['remember_hash'], $SETTINGS['remember_exp']);
    }

    // Purger les tokens expirés
    $now = time();
    foreach ($tokens as $hash => $exp) {
        if ($now >= $exp) unset($tokens[$hash]);
    }

    return $tokens;
}

/**
 * Génère et enregistre un token de reconnexion pour l'appareil courant.
 * Pose le cookie côté client, stocke le hash côté serveur.
 * Évince les tokens les plus anciens si la limite est dépassée.
 */
function remember_set(): void {
    global $SETTINGS;

    $token   = bin2hex(random_bytes(32)); // 64 chars hex
    $hash    = hash('sha256', $token);
    $expiry  = time() + REMEMBER_TTL;

    $tokens = remember_tokens();
    $tokens[$hash] = $expiry;

    // Limiter le nombre de tokens simultanés : évincer les plus anciens
    if (count($tokens) > REMEMBER_MAX_TOKENS) {
        asort($tokens); // tri croissant par timestamp d'expiration
        $tokens = array_slice($tokens, -REMEMBER_MAX_TOKENS, null, true);
    }

    $SETTINGS['remember_tokens'] = $tokens;
    // Supprimer les anciennes clés format v1 si elles traînent
    unset($SETTINGS['remember_hash'], $SETTINGS['remember_exp']);
    save_settings($SETTINGS);

    setcookie(REMEMBER_COOKIE, $token, [
        'expires'  => $expiry,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Vérifie le cookie de reconnexion de l'appareil courant.
 * Retourne true et effectue une rotation du token si valide.
 */
function remember_check(): bool {
    global $SETTINGS;

    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($token === '') return false;

    $hash   = hash('sha256', $token);
    $tokens = remember_tokens();

    if (!isset($tokens[$hash])) return false;

    // Rotation : supprimer l'ancien token, en émettre un nouveau
    unset($tokens[$hash]);
    $SETTINGS['remember_tokens'] = $tokens;
    save_settings($SETTINGS);

    remember_set(); // pose un nouveau cookie + enregistre un nouveau hash
    return true;
}

/**
 * Révoque le token de l'appareil courant (déconnexion).
 * Les tokens des autres appareils restent intacts.
 */
function remember_clear(): void {
    global $SETTINGS;

    $token = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($token !== '') {
        $hash   = hash('sha256', $token);
        $tokens = remember_tokens();
        unset($tokens[$hash]);
        $SETTINGS['remember_tokens'] = $tokens;
        save_settings($SETTINGS);
    }

    setcookie(REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ── Galeries ────────────────────────────────────────────────

/**
 * Valide un slug de galerie : a-z, 0-9, tirets, max 20 caractères.
 */
function is_valid_gallery_slug(string $slug): bool {
    return (bool) preg_match('/^[a-z0-9\-]{1,20}$/', $slug);
}

/**
 * Chemin vers le fichier JSON d'une galerie.
 */
function gallery_file(string $slug): string {
    return GALLERIES_DIR . '/' . $slug . '.json';
}

/**
 * Charge une galerie par son slug.
 * Retourne null si inexistante ou invalide.
 */
function load_gallery(string $slug): ?array {
    if (!is_valid_gallery_slug($slug)) return null;
    $file = gallery_file($slug);
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/**
 * Sauvegarde une galerie.
 */
function save_gallery(string $slug, array $data): bool {
    if (!is_dir(GALLERIES_DIR)) mkdir(GALLERIES_DIR, 0755, true);
    return file_put_contents(gallery_file($slug), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

/**
 * Supprime une galerie (JSON + PHP).
 */
function delete_gallery(string $slug): bool {
    $json = gallery_file($slug);
    $php  = GALLERIES_DIR . '/' . $slug . '.php';
    $ok   = true;
    if (file_exists($json)) $ok = unlink($json) && $ok;
    if (file_exists($php))  $ok = unlink($php)  && $ok;
    return $ok;
}

/**
 * Crée (ou recrée) le fichier PHP d'une galerie depuis le template.
 */
function create_gallery_php(string $slug): bool {
    $template = GALLERIES_DIR . '/_template.php';
    $dest     = GALLERIES_DIR . '/' . $slug . '.php';
    if (!file_exists($template)) {
        $content = "<?php\n// Auto-generated by Pixivorama\n"
            . "require_once __DIR__ . '/../config.php';\n"
            . "\$slug    = basename(__FILE__, '.php');\n"
            . "\$gallery = load_gallery(\$slug);\n"
            . "if (!\$gallery) { http_response_code(404); echo '404'; exit; }\n"
            . "include __DIR__ . '/_template.php';\n";
        return file_put_contents($dest, $content) !== false;
    }
    return copy($template, $dest);
}

/**
 * Retourne la liste de toutes les galeries.
 */
function list_galleries(): array {
    if (!is_dir(GALLERIES_DIR)) return [];
    $files   = glob(GALLERIES_DIR . '/*.json') ?: [];
    $results = [];
    foreach ($files as $file) {
        $slug = basename($file, '.json');
        if (!is_valid_gallery_slug($slug)) continue;
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) continue;
        $results[] = array_merge(['slug' => $slug], $data);
    }
    usort($results, fn($a, $b) => strcmp($a['slug'], $b['slug']));
    return $results;
}

// ── Constantes Pixiv ────────────────────────────────────────

define('PIXIV_DEFAULT_PER_PAGE', 28);
define('PIXIV_DEFAULT_ORDER',    'popular_d');
define('PIXIV_DEFAULT_MODE',     'safe');
define('PIXIV_AI_TYPE',          1);

// ── Préférences d'affichage admin ───────────────────────────

function get_admin_gallery_defaults(array $settings): array {
    $saved = $settings['gallery_defaults'] ?? [];
    return [
        'order'    => in_array($saved['order']    ?? '', ['popular_d', 'date_d'], true)
                        ? $saved['order'] : PIXIV_DEFAULT_ORDER,
        'period'   => in_array($saved['period']   ?? '', ['', 'day', 'week', 'month', '6month', 'year'], true)
                        ? $saved['period'] : '',
        'per_page' => in_array((int)($saved['per_page'] ?? 0), [28, 56], true)
                        ? (int)$saved['per_page'] : PIXIV_DEFAULT_PER_PAGE,
        'mode'     => in_array($saved['mode']     ?? '', ['safe', 'r18', 'all'], true)
                        ? $saved['mode'] : PIXIV_DEFAULT_MODE,
    ];
}
