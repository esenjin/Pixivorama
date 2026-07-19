<?php
// ============================================================
//  vestikan-callback.php — Retour du flux Vestikan (Pattern A)
//
//  C'est l'URL enregistrée comme redirect_uri dans l'admin
//  Vestikan. Elle reçoit ?code&state, vérifie le state, échange
//  le code en back-channel, et récupère le vestikan_id.
//
//  Pixivorama est un site MONO-UTILISATEUR sans comptes locaux :
//  le seul accès protégé est celui du maître (vous). Comme
//  Vestikan est lui aussi mono-utilisateur, une identité Vestikan
//  validée PROUVE que c'est bien vous → on ouvre l'accès admin,
//  exactement comme un login mot de passe réussi.
//
//  (Pattern A de INTEGRATION.md : pas de base de comptes, pas de
//   liaison vestikan_id → compte local à gérer.)
// ============================================================

require_once __DIR__ . '/config.php';   // charge auth.php (remember_set) + $SETTINGS

$configFile = __DIR__ . '/vestikan-config.php';

// Intégration non configurée : on ne doit pas atterrir ici, mais par
// prudence on renvoie vers le login classique plutôt que de planter.
if (!is_file($configFile)) {
    header('Location: admin.php');
    exit;
}

require __DIR__ . '/vestikan-sdk.php';

// La session doit être active AVANT complete() : le state anti-CSRF y a
// été déposé par begin(). config.php ne démarre pas la session lui-même,
// donc on s'en assure ici (le SDK le ferait aussi, mais on veut la même
// session que celle utilisée par admin.php / perso.php).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $vk = new Vestikan(require $configFile);
} catch (VestikanException $e) {
    error_log('[Vestikan] config invalide au callback : ' . $e->getMessage());
    // On redirige vers le login avec un message générique.
    header('Location: admin.php?vk_error=1');
    exit;
}

try {
    // Vérifie le state, échange le code, renvoie le vestikan_id.
    // Toute anomalie (state invalide, code refusé, réseau…) lève.
    $vestikanId = $vk->complete();
} catch (VestikanException $e) {
    // Échec = refus d'authentification. On n'ouvre AUCUNE session.
    error_log('[Vestikan] échec du flux : ' . $e->getMessage());
    header('Location: admin.php?vk_error=1');
    exit;
}

// ── Identité maître prouvée → on ouvre l'accès admin ──────────
// Même effet qu'un login mot de passe réussi dans admin.php :
//   $_SESSION['admin_ok'] = true; puis remember_set();
$_SESSION['admin_ok'] = true;

// Trace facultative de la provenance de la connexion (utile pour debug).
$_SESSION['auth_via']     = 'vestikan';
$_SESSION['vestikan_id']  = $vestikanId;

// Cookie remember-me 7 jours, comme pour le login classique.
remember_set();

// ── Redirection vers la destination mémorisée à begin() ───────
$dest = $vk->popReturnTo() ?: 'admin.php';

// Re-validation défensive : on n'accepte qu'un chemin interne relatif
// (le return_to a déjà été filtré à l'aller, mais on ne fait pas confiance
//  aveuglément à une valeur ressortie de session).
if (!is_string($dest) || $dest === ''
    || $dest[0] === '/'
    || strncmp($dest, '//', 2) === 0
    || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $dest)) {
    $dest = 'admin.php';
}

header('Location: ' . $dest);
exit;
