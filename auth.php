<?php
// ============================================================
//  auth.php — Gestion de l'authentification persistante
//  (remember-me multi-appareils)
//
//  Dépend de : config.php (pour $SETTINGS et save_settings())
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
// ============================================================

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

    $token  = bin2hex(random_bytes(32)); // 64 chars hex
    $hash   = hash('sha256', $token);
    $expiry = time() + REMEMBER_TTL;

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
