<?php
// ============================================================
//  private/{slug}.php — Galerie privée par tags
//  Nécessite d'être connecté à l'administration.
// ============================================================
require_once __DIR__ . '/../config.php';

session_start();
if (!isset($_SESSION['admin_ok'])) {
    header('Location: ../admin.php');
    exit;
}

define('PRIVATE_DIR', __DIR__);

$slug = basename(__FILE__, '.php');
$file = PRIVATE_DIR . '/' . $slug . '.json';

if (!file_exists($file)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Galerie introuvable</title></head>';
    echo '<body style="font-family:sans-serif;text-align:center;padding:4rem;color:#888">';
    echo '<h1>404</h1><p>Galerie introuvable.</p><a href="../perso.php">← Espace perso</a></body></html>';
    exit;
}

$gallery = json_decode(file_get_contents($file), true);
if (!is_array($gallery)) {
    http_response_code(500); exit;
}

$gallery_title = $gallery['title'];
$characters    = $gallery['characters'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($gallery_title) ?> — Privé — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css">
    <link rel="icon" type="image/png" href="../assets/logo.png">
</head>
<body>

<header>
    <p class="site-label">
        <a href="../perso.php" style="color:inherit;text-decoration:none;">Espace perso</a>
        <span style="opacity:.4;margin:0 .5rem;">·</span>
        <span style="opacity:.4;font-size:.55rem;letter-spacing:.3em;">PRIVÉ</span>
    </p>
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

<!-- Barre de contrôles — sans toggle 18+ (galerie privée = tout affiché) -->
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
</div>

<div class="status-bar" id="statusBar">—</div>
<main class="gallery" id="gallery"></main>
<div class="pagination" id="pagination" style="display:none"></div>

<button class="btn-to-top" id="btnToTop" title="Retour en haut">↑</button>
<div class="img-tooltip" id="imgTooltip"></div>

<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-sep"></span>
        <a class="footer-link" href="../perso.php">← Espace perso</a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="../index.php">Galeries publiques</a>
        <span class="footer-sep"></span>
    </div>
</footer>

<script>
    window.PIXIV_PER_PAGE    = <?= PIXIV_DEFAULT_PER_PAGE ?>;
    window.PIXIV_INITIAL_TAG = <?= json_encode($characters[0]['tag']) ?>;
    // URL de base du proxy — tag et gallery seront ajoutés en paramètres séparés par scripts.js
    window.PIXIV_PROXY_URL   = '../private-proxy.php';
    // Paramètres supplémentaires fixes à inclure dans chaque requête
    window.PIXIV_EXTRA_PARAMS = 'type=tag&gallery=<?= htmlspecialchars($slug) ?>';
</script>
<script src="../assets/scripts.js"></script>
</body>
</html>
