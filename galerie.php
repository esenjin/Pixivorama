<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(PIXIV_GALLERY_TITLE) ?> — Galerie Pixiv</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>

<header>
    <p class="site-label">Galerie</p>
    <h1><?= htmlspecialchars(PIXIV_GALLERY_TITLE) ?></h1>
</header>

<!-- Sélecteur de personnages généré depuis config.php -->
<nav class="selector-wrap" id="charSelector">
    <?php foreach (PIXIV_CHARACTERS as $i => $char): ?>
    <button
        class="char-btn<?= $i === 0 ? ' active' : '' ?>"
        data-tag="<?= htmlspecialchars($char['tag'], ENT_QUOTES) ?>"
    >
        <?= htmlspecialchars($char['label']) ?>
    </button>
    <?php endforeach; ?>
</nav>

<!-- Barre de contrôles -->
<div class="controls-bar">
    <!-- Tri -->
    <div class="control-group">
        <span class="control-label">Tri</span>
        <div class="control-pills" id="orderPicker">
            <button class="pill active" data-value="popular_d">Populaires</button>
            <button class="pill"        data-value="date_d">Récentes</button>
        </div>
    </div>

    <!-- Par page -->
    <div class="control-group">
        <span class="control-label">Par page</span>
        <div class="control-pills" id="perPagePicker">
            <button class="pill active" data-value="28">28</button>
            <button class="pill"        data-value="56">56</button>
            <button class="pill"        data-value="112">112</button>
        </div>
    </div>

    <!-- Contenu 18+ -->
    <div class="control-group">
        <span class="control-label">Contenu 18+</span>
        <label class="toggle-switch">
            <input type="checkbox" id="r18Toggle">
            <span class="toggle-track">
                <span class="toggle-thumb"></span>
            </span>
        </label>
    </div>
</div>

<div class="status-bar" id="statusBar">—</div>

<main class="gallery" id="gallery"></main>

<div class="pagination" id="pagination" style="display:none"></div>

<!-- Bouton retour en haut -->
<button class="btn-to-top" id="btnToTop" title="Retour en haut">↑</button>

<!-- Tooltip titre illustration -->
<div class="img-tooltip" id="imgTooltip"></div>

<!-- Pied de page -->
<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-sep"></span>
        <a class="footer-link" href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
            </svg>
            Accéder au dépôt
        </a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="https://esenjin.xyz/blog/mes-waifus/" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            Lire l'article
        </a>
        <span class="footer-sep"></span>
    </div>
</footer>

<!-- Variables PHP → JS -->
<script>
    window.PIXIV_PER_PAGE    = <?= PIXIV_DEFAULT_PER_PAGE ?>;
    window.PIXIV_INITIAL_TAG = <?= json_encode(PIXIV_CHARACTERS[0]['tag']) ?>;
</script>
<script src="assets/scripts.js"></script>

</body>
</html>
