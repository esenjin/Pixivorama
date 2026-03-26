<?php
// ============================================================
//  errors/403.php — Accès refusé
//
//  Appelé quand un utilisateur tente d'accéder à une galerie
//  privée, une ressource protégée, ou un fichier bloqué par
//  .htaccess. Fonctionne en sous-dossier ou à la racine.
//
//  Redirect depuis PHP :
//    header('Location: ../errors/403.php'); exit;
//    // ou avec détail (visible admins seulement) :
//    header('Location: ../errors/403.php?reason=' . urlencode($msg)); exit;
// ============================================================
http_response_code(403);

$code    = '403';
$title   = 'Accès refusé';
$desc    = 'Vous n\'avez pas les droits nécessaires pour accéder à cette page.<br>'
         . 'Connectez-vous à l\'administration pour continuer.';
$actions = [
    ['label' => '← Accueil',     'href' => '{base}index.php', 'primary' => true],
    ['label' => 'Administration', 'href' => '{base}admin.php', 'primary' => false],
];

// Détail technique : visible uniquement si connecté en admin
$detail = '';
if (!empty($_GET['reason'])) {
    session_start();
    if (!empty($_SESSION['admin_ok'])) {
        $detail = $_GET['reason'];
    }
}

include __DIR__ . '/_base.php';
