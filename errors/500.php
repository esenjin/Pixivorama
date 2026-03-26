<?php
// ============================================================
//  errors/500.php — Erreur serveur interne
//
//  Appelé quand un fichier JSON est corrompu, qu'un include
//  échoue, ou qu'une opération fichier est impossible.
//  Fonctionne en sous-dossier ou à la racine du domaine.
// ============================================================
http_response_code(500);

$code    = '500';
$title   = 'Erreur interne';
$desc    = 'Une erreur inattendue s\'est produite côté serveur.<br>'
         . 'Si le problème persiste, vérifiez les fichiers de configuration et les logs PHP.';
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
