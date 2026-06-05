<?php
// ============================================================
//  backup.php — Import / Export de sauvegardes de galeries
//  Endpoint AJAX réservé aux administrateurs connectés.
//
//  GET  ?action=list                    → liste des sauvegardes
//  POST action=export                   → crée un ZIP dans saves/
//  POST action=delete&file=...          → supprime une sauvegarde
//  POST action=analyze (+ file/upload)  → analyse un ZIP
//  POST action=import  (+ selections)   → importe des galeries
//  POST action=restore (+ savefile)     → restauration complète
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

define('SAVES_DIR',   __DIR__ . '/../saves');
define('PRIVATE_DIR', __DIR__ . '/../private');
define('SEEN_DB_FILE_BACKUP', __DIR__ . '/../data/seen.db');

if (!is_dir(SAVES_DIR)) {
    mkdir(SAVES_DIR, 0755, true);
    file_put_contents(SAVES_DIR . '/.htaccess', "Order allow,deny\nDeny from all\n");
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helpers seen.db ──────────────────────────────────────────

/**
 * Lit seen.db et retourne un tableau [ [illust_id, seen_at], … ].
 * Retourne un tableau vide si la base n'existe pas ou est illisible.
 */
function seen_db_export(): array {
    if (!file_exists(SEEN_DB_FILE_BACKUP)) return [];
    try {
        $db   = new PDO('sqlite:' . SEEN_DB_FILE_BACKUP);
        $rows = $db->query('SELECT illust_id, seen_at FROM seen_illusts ORDER BY seen_at DESC')
                   ->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Importe un tableau [ [illust_id, seen_at], … ] dans seen.db.
 * Crée la base et le dossier si nécessaire.
 * Fusionne avec les données existantes (UPSERT).
 */
function seen_db_import(array $rows): int {
    if (empty($rows)) return 0;
    $dir = dirname(SEEN_DB_FILE_BACKUP);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    try {
        $db = new PDO('sqlite:' . SEEN_DB_FILE_BACKUP);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE IF NOT EXISTS seen_illusts (
            illust_id TEXT PRIMARY KEY,
            seen_at   INTEGER NOT NULL
        )');
        $stmt  = $db->prepare('
            INSERT INTO seen_illusts (illust_id, seen_at)
            VALUES (?, ?)
            ON CONFLICT(illust_id) DO UPDATE SET seen_at = MAX(seen_at, excluded.seen_at)
        ');
        $count = 0;
        $db->beginTransaction();
        foreach ($rows as $row) {
            $id = (string)($row['illust_id'] ?? '');
            $ts = (int)($row['seen_at']    ?? 0);
            if ($id === '' || $ts <= 0) continue;
            $stmt->execute([$id, $ts]);
            $count++;
        }
        $db->commit();
        return $count;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Remplace intégralement seen.db par les données fournies.
 * Utilisé lors d'une restauration complète.
 */
function seen_db_restore(array $rows): int {
    $dir = dirname(SEEN_DB_FILE_BACKUP);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    // Supprimer l'ancienne base proprement
    if (file_exists(SEEN_DB_FILE_BACKUP)) @unlink(SEEN_DB_FILE_BACKUP);
    return seen_db_import($rows);
}

// ── GET : liste des sauvegardes ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $files = glob(SAVES_DIR . '/backup_*.zip') ?: [];
    rsort($files);
    $saves = [];
    foreach ($files as $f) {
        $saves[] = [
            'file' => basename($f),
            'size' => filesize($f),
            'date' => filemtime($f),
        ];
    }
    echo json_encode(['saves' => $saves]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'download') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non supportée.']);
    exit;
}

switch ($action) {

    case 'export':
        if (!class_exists('ZipArchive')) {
            echo json_encode(['error' => "L'extension ZipArchive n'est pas disponible sur ce serveur."]);
            exit;
        }
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.zip';
        $filepath = SAVES_DIR . '/' . $filename;
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            echo json_encode(['error' => 'Impossible de créer le fichier ZIP.']); exit;
        }
        $count = 0;
        foreach (glob(GALLERIES_DIR . '/*.json') ?: [] as $f) {
            $slug = basename($f, '.json');
            if (!is_valid_gallery_slug($slug)) continue;
            $zip->addFile($f, 'galleries/' . basename($f)); $count++;
        }
        if (is_dir(PRIVATE_DIR)) {
            foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
                $slug = basename($f, '.json');
                if (!is_valid_gallery_slug($slug)) continue;
                $zip->addFile($f, 'private/' . basename($f)); $count++;
            }
        }

        // ── Export seen.db → seen_data.json dans le ZIP ──
        $seenRows = seen_db_export();
        $zip->addFromString('seen_data.json', json_encode($seenRows, JSON_UNESCAPED_UNICODE));

        $zip->addFromString('backup_meta.json', json_encode([
            'version'    => APP_VERSION,
            'created_at' => date('c'),
            'galleries'  => $count,
            'seen_count' => count($seenRows),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();
        // Garder les 20 plus récentes
        $allSaves = glob(SAVES_DIR . '/backup_*.zip') ?: []; rsort($allSaves);
        foreach (array_slice($allSaves, 20) as $old) @unlink($old);
        echo json_encode([
            'ok'         => true,
            'file'       => $filename,
            'size'       => filesize($filepath),
            'count'      => $count,
            'seen_count' => count($seenRows),
        ]);
        exit;

    case 'download':
        $file = basename($_GET['file'] ?? $_POST['file'] ?? '');
        if (!preg_match('/^backup_[\d_\-]+\.zip$/', $file)) {
            echo json_encode(['error' => 'Nom de fichier invalide.']); exit;
        }
        $path = SAVES_DIR . '/' . $file;
        if (!file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'Fichier introuvable.']); exit;
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        exit;

    case 'delete':
        $file = basename($_POST['file'] ?? '');
        if (!preg_match('/^backup_[\d_\-]+\.zip$/', $file)) {
            echo json_encode(['error' => 'Nom de fichier invalide.']); exit;
        }
        $path = SAVES_DIR . '/' . $file;
        if (!file_exists($path)) { echo json_encode(['error' => 'Fichier introuvable.']); exit; }
        unlink($path);
        echo json_encode(['ok' => true]);
        exit;

    case 'analyze':
        if (!class_exists('ZipArchive')) {
            echo json_encode(['error' => "L'extension ZipArchive n'est pas disponible."]); exit;
        }
        $zipPath = null;
        if (!empty($_FILES['zipfile']['tmp_name']) && $_FILES['zipfile']['error'] === UPLOAD_ERR_OK) {
            $zipPath = $_FILES['zipfile']['tmp_name'];
        } elseif (!empty($_POST['savefile'])) {
            $name = basename($_POST['savefile']);
            if (!preg_match('/^backup_[\d_\-]+\.zip$/', $name)) {
                echo json_encode(['error' => 'Nom de fichier invalide.']); exit;
            }
            $zipPath = SAVES_DIR . '/' . $name;
        }
        if (!$zipPath || !file_exists($zipPath)) {
            echo json_encode(['error' => 'Aucun fichier fourni.']); exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            echo json_encode(['error' => 'Impossible d\'ouvrir le ZIP. Fichier corrompu ?']); exit;
        }
        $existingPublic = [];
        foreach (glob(GALLERIES_DIR . '/*.json') ?: [] as $f) {
            $s = basename($f, '.json'); if (is_valid_gallery_slug($s)) $existingPublic[] = $s;
        }
        $existingPrivate = [];
        if (is_dir(PRIVATE_DIR)) {
            foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
                $s = basename($f, '.json'); if (is_valid_gallery_slug($s)) $existingPrivate[] = $s;
            }
        }
        $meta = [];
        $metaRaw = $zip->getFromName('backup_meta.json');
        if ($metaRaw) $meta = json_decode($metaRaw, true) ?? [];

        // Détecter la présence de seen_data.json
        $hasSeen  = $zip->locateName('seen_data.json') !== false;
        $seenCount = 0;
        if ($hasSeen) {
            $seenRaw = $zip->getFromName('seen_data.json');
            $seenArr = $seenRaw ? (json_decode($seenRaw, true) ?? []) : [];
            $seenCount = count($seenArr);
        }

        $galleries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zipName = $zip->statIndex($i)['name'];
            if (preg_match('#^galleries/([a-z0-9\-]{1,20})\.json$#', $zipName, $m)) {
                $data = json_decode($zip->getFromIndex($i), true);
                if (!is_array($data)) continue;
                $galleries[] = ['slug' => $m[1], 'title' => $data['title'] ?? $m[1],
                    'type' => 'public', 'chars' => count($data['characters'] ?? []),
                    'conflict' => in_array($m[1], $existingPublic, true)];
            }
            if (preg_match('#^private/([a-z0-9\-]{1,20})\.json$#', $zipName, $m)) {
                $data = json_decode($zip->getFromIndex($i), true);
                if (!is_array($data)) continue;
                $galleries[] = ['slug' => $m[1], 'title' => $data['title'] ?? $m[1],
                    'type' => 'private:' . ($data['type'] ?? 'tag'),
                    'chars' => count($data['characters'] ?? []),
                    'conflict' => in_array($m[1], $existingPrivate, true)];
            }
        }
        $zip->close();
        echo json_encode([
            'ok'        => true,
            'meta'      => $meta,
            'galleries' => $galleries,
            'source'    => basename($zipPath),
            'has_seen'  => $hasSeen,
            'seen_count' => $seenCount,
        ]);
        exit;

    case 'import':
        if (!class_exists('ZipArchive')) {
            echo json_encode(['error' => "L'extension ZipArchive n'est pas disponible."]); exit;
        }
        $zipPath = null;
        if (!empty($_FILES['zipfile']['tmp_name']) && $_FILES['zipfile']['error'] === UPLOAD_ERR_OK) {
            $zipPath = $_FILES['zipfile']['tmp_name'];
        } elseif (!empty($_POST['savefile'])) {
            $name = basename($_POST['savefile']);
            if (!preg_match('/^backup_[\d_\-]+\.zip$/', $name)) {
                echo json_encode(['error' => 'Nom de fichier invalide.']); exit;
            }
            $zipPath = SAVES_DIR . '/' . $name;
        }
        if (!$zipPath || !file_exists($zipPath)) {
            echo json_encode(['error' => 'Aucun fichier fourni.']); exit;
        }
        $selections = json_decode($_POST['selections'] ?? '[]', true);
        if (!is_array($selections) || empty($selections)) {
            echo json_encode(['error' => 'Aucune galerie sélectionnée.']); exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            echo json_encode(['error' => 'Impossible d\'ouvrir le ZIP.']); exit;
        }
        $results = [];
        foreach ($selections as $sel) {
            $origSlug = $sel['original_slug'] ?? '';
            $newSlug  = trim($sel['new_slug'] ?? $origSlug);
            $gtype    = $sel['type'] ?? 'public';
            if (!is_valid_gallery_slug($origSlug) || !is_valid_gallery_slug($newSlug)) {
                $results[] = ['slug' => $origSlug, 'status' => 'error', 'message' => 'Slug invalide.']; continue;
            }
            $isPrivate = str_starts_with($gtype, 'private');
            $content   = $zip->getFromName(($isPrivate ? 'private/' : 'galleries/') . $origSlug . '.json');
            if ($content === false) {
                $results[] = ['slug' => $origSlug, 'status' => 'error', 'message' => 'Entrée introuvable dans le ZIP.']; continue;
            }
            $data = json_decode($content, true);
            if (!is_array($data)) {
                $results[] = ['slug' => $origSlug, 'status' => 'error', 'message' => 'JSON invalide.']; continue;
            }
            if ($isPrivate) {
                if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0755, true);
                file_put_contents(PRIVATE_DIR . '/' . $newSlug . '.json',
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $tpl = (($data['type'] ?? 'tag') === 'tag') ? PRIVATE_DIR . '/_template.php' : PRIVATE_DIR . '/_special.php';
                if (file_exists($tpl)) copy($tpl, PRIVATE_DIR . '/' . $newSlug . '.php');
            } else {
                if (!is_dir(GALLERIES_DIR)) mkdir(GALLERIES_DIR, 0755, true);
                file_put_contents(GALLERIES_DIR . '/' . $newSlug . '.json',
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $tpl = GALLERIES_DIR . '/_template.php';
                if (file_exists($tpl)) copy($tpl, GALLERIES_DIR . '/' . $m[1] . '.php');
            }
            $results[] = ['slug' => $newSlug, 'status' => 'ok',
                'message' => ($newSlug !== $origSlug) ? "Importé (renommé depuis « {$origSlug} »)" : 'Importé'];
        }

        // ── Import seen_data.json si présent et demandé ──
        $importSeen  = filter_var($_POST['import_seen'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $seenImported = 0;
        if ($importSeen) {
            $seenRaw = $zip->getFromName('seen_data.json');
            if ($seenRaw !== false) {
                $seenArr      = json_decode($seenRaw, true) ?? [];
                $seenImported = seen_db_import($seenArr);
            }
        }

        $zip->close();
        $ok = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
        echo json_encode([
            'ok'           => true,
            'results'      => $results,
            'success'      => $ok,
            'errors'       => count($results) - $ok,
            'seen_imported' => $seenImported,
        ]);
        exit;

    case 'restore':
        if (!class_exists('ZipArchive')) {
            echo json_encode(['error' => "L'extension ZipArchive n'est pas disponible."]); exit;
        }
        $name = basename($_POST['savefile'] ?? '');
        if (!preg_match('/^backup_[\d_\-]+\.zip$/', $name)) {
            echo json_encode(['error' => 'Nom de fichier invalide.']); exit;
        }
        $zipPath = SAVES_DIR . '/' . $name;
        if (!file_exists($zipPath)) {
            echo json_encode(['error' => 'Sauvegarde introuvable.']); exit;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            echo json_encode(['error' => 'Impossible d\'ouvrir le ZIP.']); exit;
        }
        // Supprimer toutes les galeries existantes
        foreach (glob(GALLERIES_DIR . '/*.json') ?: [] as $f) {
            $s = basename($f, '.json');
            if (is_valid_gallery_slug($s)) { @unlink($f); @unlink(GALLERIES_DIR . '/' . $s . '.php'); }
        }
        if (is_dir(PRIVATE_DIR)) {
            foreach (glob(PRIVATE_DIR . '/*.json') ?: [] as $f) {
                $s = basename($f, '.json');
                if (is_valid_gallery_slug($s)) { @unlink($f); @unlink(PRIVATE_DIR . '/' . $s . '.php'); }
            }
        }
        global $SETTINGS;
        unset($SETTINGS['gallery_order'], $SETTINGS['private_gallery_order']);
        save_settings($SETTINGS);
        // Extraire galeries
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zipName = $zip->statIndex($i)['name'];
            $content = $zip->getFromIndex($i);
            if (preg_match('#^galleries/([a-z0-9\-]{1,20})\.json$#', $zipName, $m)) {
                if (!is_dir(GALLERIES_DIR)) mkdir(GALLERIES_DIR, 0755, true);
                file_put_contents(GALLERIES_DIR . '/' . $m[1] . '.json', $content);
                $tpl = GALLERIES_DIR . '/_template.php';
                if (file_exists($tpl)) copy($tpl, GALLERIES_DIR . '/' . $m[1] . '.php');
                $count++;
            }
            if (preg_match('#^private/([a-z0-9\-]{1,20})\.json$#', $zipName, $m)) {
                if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0755, true);
                file_put_contents(PRIVATE_DIR . '/' . $m[1] . '.json', $content);
                $data  = json_decode($content, true);
                $gtype = $data['type'] ?? 'tag';
                $tpl   = ($gtype === 'tag') ? PRIVATE_DIR . '/_template.php' : PRIVATE_DIR . '/_special.php';
                if (file_exists($tpl)) copy($tpl, PRIVATE_DIR . '/' . $m[1] . '.php');
                $count++;
            }
        }

        // ── Restauration seen_data.json (remplace l'existant) ──
        $seenRestored = 0;
        $seenRaw = $zip->getFromName('seen_data.json');
        if ($seenRaw !== false) {
            $seenArr      = json_decode($seenRaw, true) ?? [];
            $seenRestored = seen_db_restore($seenArr);
        }

        $zip->close();
        echo json_encode(['ok' => true, 'count' => $count, 'seen_restored' => $seenRestored]);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue.']);
        exit;
}
