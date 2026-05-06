<?php
// ============================================================
//  fonctions/move-tag.php — Déplace un tag d'une galerie
//  vers une autre (publique ↔ privée ou même type).
//
//  POST src_slug=…  src_type=public|private
//       dst_slug=…  dst_type=public|private
//       tag_index=N (index 0-based dans characters[])
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

$src_slug  = trim($_POST['src_slug']  ?? '');
$src_type  = trim($_POST['src_type']  ?? ''); // 'public' | 'private'
$dst_slug  = trim($_POST['dst_slug']  ?? '');
$dst_type  = trim($_POST['dst_type']  ?? ''); // 'public' | 'private'
$tag_index = (int)($_POST['tag_index'] ?? -1);

// ── Validation ──────────────────────────────────────────────
if (!is_valid_gallery_slug($src_slug) || !is_valid_gallery_slug($dst_slug)) {
    http_response_code(400);
    echo json_encode(['error' => 'Slug invalide.']);
    exit;
}
if (!in_array($src_type, ['public', 'private'], true) || !in_array($dst_type, ['public', 'private'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Type de galerie invalide.']);
    exit;
}
if ($src_slug === $dst_slug && $src_type === $dst_type) {
    http_response_code(400);
    echo json_encode(['error' => 'Source et destination identiques.']);
    exit;
}
if ($tag_index < 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Index de tag invalide.']);
    exit;
}

// ── Chemins ─────────────────────────────────────────────────
function get_gallery_json_path(string $slug, string $type): string {
    if ($type === 'private') {
        return PRIVATE_DIR . '/' . $slug . '.json';
    }
    return GALLERIES_DIR . '/' . $slug . '.json';
}

$src_file = get_gallery_json_path($src_slug, $src_type);
$dst_file = get_gallery_json_path($dst_slug, $dst_type);

// ── Lecture ──────────────────────────────────────────────────
if (!file_exists($src_file)) {
    echo json_encode(['error' => 'Galerie source introuvable.']);
    exit;
}
if (!file_exists($dst_file)) {
    echo json_encode(['error' => 'Galerie destination introuvable.']);
    exit;
}

$src_data = json_decode(file_get_contents($src_file), true);
$dst_data = json_decode(file_get_contents($dst_file), true);

if (!is_array($src_data) || !is_array($dst_data)) {
    echo json_encode(['error' => 'Données de galerie invalides.']);
    exit;
}

$src_chars = $src_data['characters'] ?? [];
$dst_chars = $dst_data['characters'] ?? [];

if (!isset($src_chars[$tag_index])) {
    echo json_encode(['error' => 'Tag introuvable à cet index.']);
    exit;
}

// Garder au moins un tag dans la galerie source
if (count($src_chars) <= 1) {
    echo json_encode(['error' => 'Impossible de déplacer le seul tag restant dans la galerie source.']);
    exit;
}

// ── Déplacement ──────────────────────────────────────────────
$tag = $src_chars[$tag_index];

// Retirer de la source
array_splice($src_chars, $tag_index, 1);
$src_data['characters'] = array_values($src_chars);

// Ajouter à la destination
$dst_chars[] = $tag;
$dst_data['characters'] = array_values($dst_chars);

// ── Sauvegarde ───────────────────────────────────────────────
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

if (file_put_contents($src_file, json_encode($src_data, $flags)) === false) {
    echo json_encode(['error' => 'Impossible de sauvegarder la galerie source.']);
    exit;
}
if (file_put_contents($dst_file, json_encode($dst_data, $flags)) === false) {
    echo json_encode(['error' => 'Impossible de sauvegarder la galerie destination.']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'tag_label' => $tag['label'] ?? '',
    'tag_tag'   => $tag['tag']   ?? '',
    'dst_title' => $dst_data['title'] ?? $dst_slug,
]);
