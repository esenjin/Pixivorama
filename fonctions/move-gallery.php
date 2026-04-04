<?php
// ============================================================
//  fonctions/move-gallery.php — Déplace une galerie entre
//  l'espace public (galleries/) et l'espace privé (private/).
//
//  POST direction=to_private&slug=...  → public → privée
//  POST direction=to_public&slug=...   → privée → publique
//
//  Réservé aux administrateurs connectés.
// ============================================================
require_once __DIR__ . '/../config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}
if (!isset($_SESSION['admin_ok'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Non autorisé.']);
    exit;
}

define('PRIVATE_DIR', __DIR__ . '/../private');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non supportée.']);
    exit;
}

$direction = $_POST['direction'] ?? '';
$slug      = trim($_POST['slug'] ?? '');

if (!in_array($direction, ['to_private', 'to_public'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Direction invalide.']);
    exit;
}

if (!is_valid_gallery_slug($slug)) {
    http_response_code(400);
    echo json_encode(['error' => 'Slug invalide.']);
    exit;
}

if ($direction === 'to_private') {
    // ── Publique → Privée ────────────────────────────────────
    $src_json = GALLERIES_DIR . '/' . $slug . '.json';
    $src_php  = GALLERIES_DIR . '/' . $slug . '.php';
    $dst_json = PRIVATE_DIR   . '/' . $slug . '.json';
    $dst_php  = PRIVATE_DIR   . '/' . $slug . '.php';

    if (!file_exists($src_json)) {
        echo json_encode(['error' => 'Galerie publique introuvable.']);
        exit;
    }
    if (file_exists($dst_json)) {
        echo json_encode(['error' => "Une galerie privée avec le slug « {$slug} » existe déjà."]);
        exit;
    }

    if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0755, true);

    // Lire le JSON pour déterminer le bon template
    $gdata = json_decode(file_get_contents($src_json), true) ?? [];
    $gtype = $gdata['type'] ?? 'tag';
    // Les galeries publiques sont toujours de type 'tag' (pas de type explicite)
    if (!in_array($gtype, ['illust', 'bookmark', 'following'], true)) {
        $gtype = 'tag';
    }

    // Copier le JSON
    if (!copy($src_json, $dst_json)) {
        echo json_encode(['error' => 'Impossible de copier le fichier JSON.']);
        exit;
    }

    // Regénérer le PHP depuis le bon template privé
    $template = ($gtype === 'tag')
        ? PRIVATE_DIR . '/_template.php'
        : PRIVATE_DIR . '/_special.php';

    if (file_exists($template)) {
        copy($template, $dst_php);
    }

    // Supprimer les fichiers publics
    @unlink($src_json);
    @unlink($src_php);

    // Retirer le slug de l'ordre des galeries publiques
    global $SETTINGS;
    if (!empty($SETTINGS['gallery_order'])) {
        $SETTINGS['gallery_order'] = array_values(
            array_filter($SETTINGS['gallery_order'], fn($s) => $s !== $slug)
        );
        save_settings($SETTINGS);
    }

    echo json_encode(['ok' => true, 'direction' => 'to_private', 'slug' => $slug]);

} else {
    // ── Privée → Publique ────────────────────────────────────
    $src_json = PRIVATE_DIR   . '/' . $slug . '.json';
    $src_php  = PRIVATE_DIR   . '/' . $slug . '.php';
    $dst_json = GALLERIES_DIR . '/' . $slug . '.json';
    $dst_php  = GALLERIES_DIR . '/' . $slug . '.php';

    if (!file_exists($src_json)) {
        echo json_encode(['error' => 'Galerie privée introuvable.']);
        exit;
    }
    if (file_exists($dst_json)) {
        echo json_encode(['error' => "Une galerie publique avec le slug « {$slug} » existe déjà."]);
        exit;
    }

    // Vérifier que c'est bien une galerie de type 'tag' (les spéciales ne peuvent pas être rendues publiques)
    $gdata = json_decode(file_get_contents($src_json), true) ?? [];
    $gtype = $gdata['type'] ?? 'tag';
    if ($gtype !== 'tag') {
        echo json_encode(['error' => 'Seules les galeries par tags peuvent être rendues publiques.']);
        exit;
    }

    if (!is_dir(GALLERIES_DIR)) mkdir(GALLERIES_DIR, 0755, true);

    // Copier le JSON
    if (!copy($src_json, $dst_json)) {
        echo json_encode(['error' => 'Impossible de copier le fichier JSON.']);
        exit;
    }

    // Regénérer le PHP depuis le template public
    $template = GALLERIES_DIR . '/_template.php';
    if (file_exists($template)) {
        copy($template, $dst_php);
    }

    // Supprimer les fichiers privés
    @unlink($src_json);
    @unlink($src_php);

    echo json_encode(['ok' => true, 'direction' => 'to_public', 'slug' => $slug]);
}
