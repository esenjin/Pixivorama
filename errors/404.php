<?php
// ============================================================
//  errors/404.php — Page introuvable
//
//  Appelé automatiquement par la RewriteRule du .htaccess
//  pour toute URL inexistante, ou manuellement depuis PHP.
//  Fonctionne en sous-dossier ou à la racine du domaine.
// ============================================================
http_response_code(404);

$code    = '404';
$title   = 'Page introuvable';
$desc    = 'La galerie ou la ressource demandée n\'existe pas ou a été supprimée.';
$actions = [
    ['label' => '← Toutes les galeries', 'href' => '{base}index.php', 'primary' => true],
];

$detail = '';
if (!empty($_GET['reason'])) {
    session_start();
    if (!empty($_SESSION['admin_ok'])) {
        $detail = $_GET['reason'];
    }
}

include __DIR__ . '/_base.php';
