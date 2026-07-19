<?php
// ============================================================
//  vestikan-login.php — Bouton « Se connecter avec Vestikan »
//
//  Démarre le flux d'authentification Vestikan (Pattern A).
//  Le SDK génère le state anti-CSRF, mémorise la destination de
//  retour, puis redirige vers l'écran d'autorisation Vestikan.
//  Ne revient jamais (le retour se fait sur vestikan-callback.php).
//
//  Si vestikan-config.php est absent, l'intégration n'est pas
//  configurée : on renvoie simplement vers le login classique.
// ============================================================

$configFile = __DIR__ . '/vestikan-config.php';

if (!is_file($configFile)) {
    header('Location: admin.php');
    exit;
}

require __DIR__ . '/vestikan-sdk.php';

try {
    $vk = new Vestikan(require $configFile);
} catch (VestikanException $e) {
    // Config incomplète : ne pas bloquer l'accès au login mot de passe.
    error_log('[Vestikan] config invalide : ' . $e->getMessage());
    header('Location: admin.php');
    exit;
}

// Destination applicative après connexion réussie.
// On accepte un ?return_to interne seulement (anti open-redirect) :
//   - non vide,
//   - pas d'URL absolue (schéma ://),
//   - pas de chemin protocol-relative (//host…),
//   - pas de chemin absolu serveur (/…) : on veut un chemin relatif au projet.
$returnTo  = 'admin.php';
$candidate = $_GET['return_to'] ?? '';
if (is_string($candidate) && $candidate !== ''
    && $candidate[0] !== '/'
    && strncmp($candidate, '//', 2) !== 0
    && !preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate)) {
    $returnTo = $candidate;
}

// Redirige vers Vestikan ; ne revient pas.
$vk->begin($returnTo);
