<?php
// ============================================================
//  errors/503.php — Service temporairement indisponible
//
//  Appelé quand Pixiv est inaccessible (timeout cURL, réseau,
//  réponse HTTP inattendue) ou que le cookie est expiré.
//  Fonctionne en sous-dossier ou à la racine du domaine.
// ============================================================
http_response_code(503);
header('Retry-After: 60');

$code    = '503';
$title   = 'Service indisponible';
$desc    = 'Impossible de contacter Pixiv pour le moment.<br>'
         . 'Le service est peut-être temporairement indisponible, '
         . 'ou votre cookie de session a expiré.';
$actions = [
    ['label' => '← Accueil',     'href' => '{base}index.php', 'primary' => true],
    ['label' => 'Administration', 'href' => '{base}admin.php', 'primary' => false],
];

$detail = '';
if (!empty($_GET['reason'])) {
    session_start();
    if (!empty($_SESSION['admin_ok'])) {
        $detail = $_GET['reason'];
    }
}

include __DIR__ . '/_base.php';
