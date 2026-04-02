<?php
// ============================================================
//  includes/gallery-page.php — Template commun des pages
//  de galerie (publiques et privées par tags).
//
//  Variables attendues avant l'include :
//
//    string $gallery_title    — Titre affiché dans le <h1>
//    array  $characters       — Tableau de ['label'=>…,'tag'=>…]
//    string $proxy_url        — URL du proxy Pixiv à appeler
//    string $extra_params     — Paramètres extra pour le proxy
//                               (ex: 'gallery=slug')
//    string $back_href        — URL du lien retour dans le header
//    string $back_label       — Texte du lien retour
//    bool   $is_private       — true = galerie privée (badge PRIVÉ,
//                               lien espace perso dans le footer)
//    array  $footer_links     — Liens supplémentaires footer
//                               [['href'=>…,'label'=>…,'icon'=>…?], …]
//                               'icon' est du HTML optionnel (<svg>…)
//    array|null $admin_defs   — Préférences admin ou null
//    string $page_title       — Valeur du <title> complet
// ============================================================

// Valeurs par défaut
$is_private  = $is_private  ?? false;
$footer_links = $footer_links ?? [];
$admin_defs  = $admin_defs  ?? null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($assets_path ?? '../assets/') ?>styles.css">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($assets_path ?? '../assets/') ?>logo.png">
</head>
<body>

<header>
    <p class="site-label">
        <a href="<?= htmlspecialchars($back_href) ?>" style="color:inherit;text-decoration:none;"><?= htmlspecialchars($back_label) ?></a>
        <?php if ($is_private): ?>
        <span style="opacity:.4;margin:0 .5rem;">·</span>
        <span style="opacity:.4;font-size:.55rem;letter-spacing:.3em;">PRIVÉ</span>
        <?php endif; ?>
    </p>
    <h1><?= htmlspecialchars($gallery_title) ?></h1>
    <a class="admin-link" href="<?= htmlspecialchars($admin_href ?? '../admin.php') ?>" title="Administration">
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
        <?php if ($is_private): ?>
        <a class="footer-link" href="<?= htmlspecialchars($back_href) ?>">← Espace perso</a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="<?= htmlspecialchars($index_href ?? '../index.php') ?>">Galeries publiques</a>
        <?php else: ?>
        <a class="footer-link" href="<?= htmlspecialchars($index_href ?? '../index.php') ?>">← Toutes les galeries</a>
        <?php foreach ($footer_links as $fl): ?>
        <span class="footer-sep"></span>
        <a class="footer-link" href="<?= htmlspecialchars($fl['href']) ?>"
           <?= !empty($fl['external']) ? 'target="_blank" rel="noopener"' : '' ?>>
            <?php if (!empty($fl['icon'])): ?>
            <?= $fl['icon'] ?>
            <?php endif; ?>
            <?= htmlspecialchars($fl['label']) ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
        <span class="footer-sep"></span>
    </div>
</footer>

<script>
    window.PIXIV_PER_PAGE    = <?= PIXIV_DEFAULT_PER_PAGE ?>;
    window.PIXIV_INITIAL_TAG = <?= json_encode($characters[0]['tag']) ?>;
    window.PIXIV_PROXY_URL   = <?= json_encode($proxy_url) ?>;
    window.PIXIV_EXTRA_PARAMS = <?= json_encode($extra_params ?? '') ?>;
    <?php if ($admin_defs): ?>
    window.PIXIV_DEFAULTS    = <?= json_encode($admin_defs) ?>;
    <?php endif; ?>
</script>
<script src="<?= htmlspecialchars($assets_path ?? '../assets/') ?>pagination.js"></script>
<script src="<?= htmlspecialchars($assets_path ?? '../assets/') ?>scripts.js"></script>
</body>
</html>
