<?php
// ============================================================
//  galleries/{slug}.php — Page de galerie générée dynamiquement
//  Ce fichier est le TEMPLATE à placer dans le dossier galleries/.
//  Il charge sa configuration depuis galleries/{slug}.json.
// ============================================================
require_once __DIR__ . '/../config.php';

// Session optionnelle (pour les préférences admin)
if (session_status() === PHP_SESSION_NONE) session_start();

// Détermine le slug depuis le nom du fichier lui-même
$slug = basename(__FILE__, '.php');

$gallery = load_gallery($slug);
if (!$gallery) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Galerie introuvable</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:4rem;color:#888">';
    echo '<h1>404</h1><p>Galerie introuvable.</p><a href="../index.php">← Accueil</a></body></html>';
    exit;
}

$gallery_title = $gallery['title'];
$characters    = $gallery['characters'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($gallery_title) ?> — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css">
    <link rel="icon" type="image/png" href="../assets/logo.png">
</head>
<body>

<header>
    <p class="site-label"><a href="../index.php" style="color:inherit;text-decoration:none;">Galerie</a></p>
    <h1><?= htmlspecialchars($gallery_title) ?></h1>
    <a class="admin-link" href="../admin.php" title="Administration">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
        </svg>
    </a>
</header>

<!-- Sélecteur de personnages -->
<nav class="selector-wrap" id="charSelector">
    <?php foreach ($characters as $i => $char): ?>
    <button
        class="char-btn<?= $i === 0 ? ' active' : '' ?>"
        data-tag="<?= htmlspecialchars($char['tag'], ENT_QUOTES) ?>">
        <?= htmlspecialchars($char['label']) ?>
    </button>
    <?php endforeach; ?>
</nav>

<!-- Barre de contrôles -->
<div class="controls-bar">
    <div class="control-group">
        <span class="control-label">Tri</span>
        <div class="control-pills" id="orderPicker">
            <button class="pill active" data-value="popular_d">Populaires</button>
            <button class="pill"        data-value="date_d">Récentes</button>
        </div>
    </div>
    <div class="control-group">
        <span class="control-label">Par page</span>
        <div class="control-pills" id="perPagePicker">
            <button class="pill active" data-value="28">28</button>
            <button class="pill"        data-value="56">56</button>
            <button class="pill"        data-value="112">112</button>
        </div>
    </div>
    <div class="control-group">
        <span class="control-label">Contenu</span>
        <div class="control-pills" id="contentPicker">
            <button class="pill active" data-value="safe">Safe</button>
            <button class="pill"        data-value="r18">18+</button>
            <button class="pill"        data-value="all">Tout</button>
        </div>
    </div>
</div>

<div class="status-bar" id="statusBar">—</div>
<main class="gallery" id="gallery"></main>
<div class="pagination" id="pagination" style="display:none"></div>

<button class="btn-to-top" id="btnToTop" title="Retour en haut">↑</button>
<div class="img-tooltip" id="imgTooltip"></div>

<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-sep"></span>
        <a class="footer-link" href="../index.php">← Toutes les galeries</a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
            </svg>
            Dépôt
        </a>
        <span class="footer-sep"></span>
        <?php if (!empty($gallery['footer_link_label']) && !empty($gallery['footer_link_url'])): ?>
        <a class="footer-link" href="<?= htmlspecialchars($gallery['footer_link_url']) ?>" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            <?= htmlspecialchars($gallery['footer_link_label']) ?>
        </a>
        <span class="footer-sep"></span>
        <?php endif; ?>
    </div>
</footer>

<?php
// Injecter les préférences admin si la session est active
$admin_defs = null;
if (!empty($_SESSION['admin_ok'])) {
    $admin_defs = get_admin_gallery_defaults($SETTINGS);
}
?>
<script>
    window.PIXIV_PER_PAGE    = <?= PIXIV_DEFAULT_PER_PAGE ?>;
    window.PIXIV_INITIAL_TAG = <?= json_encode($characters[0]['tag']) ?>;
    window.PIXIV_PROXY_URL   = '../pixiv-proxy.php';
    <?php if ($admin_defs): ?>
    window.PIXIV_DEFAULTS    = <?= json_encode($admin_defs) ?>;
    <?php endif; ?>
</script>
<script src="../assets/scripts.js"></script>
</body>
</html>