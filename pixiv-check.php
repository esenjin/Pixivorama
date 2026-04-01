<?php
// ============================================================
//  pixiv-check.php — Vérifie la validité du cookie Pixiv
//  Appelé en AJAX depuis admin.php (utilisateur connecté).
//
//  Sans paramètre  → vérifie PIXIV_PHPSESSID (cookie enregistré)
//  ?sessid=...     → vérifie le PHPSESSID fourni (validation pré-enregistrement)
// ============================================================

require_once __DIR__ . '/config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}

header('Content-Type: application/json; charset=utf-8');

// Utiliser le PHPSESSID fourni en paramètre GET si présent,
// sinon celui déjà enregistré dans la configuration.
$phpsessid = isset($_GET['sessid']) ? trim($_GET['sessid']) : PIXIV_PHPSESSID;
$userId    = null;

if (preg_match('/^(\d+)_/', $phpsessid, $m)) {
    $userId = $m[1];
}

if (!$userId) {
    echo json_encode(['valid' => false, 'reason' => 'Format de PHPSESSID invalide (userId introuvable).']);
    exit;
}

// On interroge /ajax/user/{userId} pour valider la session ET récupérer le pseudo.
$url = 'https://www.pixiv.net/ajax/user/' . $userId;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: application/json',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.pixiv.net/',
        'Cookie: PHPSESSID=' . $phpsessid,
    ],
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

// Erreur réseau
if ($curl_err) {
    echo json_encode(['valid' => false, 'reason' => 'Erreur réseau : ' . $curl_err]);
    exit;
}

if ($http_code !== 200) {
    echo json_encode(['valid' => false, 'reason' => 'Pixiv a répondu HTTP ' . $http_code]);
    exit;
}

$data = json_decode($response, true);

// Pixiv retourne error=true si la session est invalide ou le profil inaccessible
if (!$data || ($data['error'] ?? false) === true) {
    $msg = $data['message'] ?? 'Session expirée ou cookie invalide.';
    echo json_encode(['valid' => false, 'reason' => $msg]);
    exit;
}

// Le pseudo est dans body.name
$username = $data['body']['name'] ?? null;

echo json_encode([
    'valid'    => true,
    'username' => $username,
]);