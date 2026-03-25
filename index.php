<?php
// ============================================================
//  index.php — Page d'accueil : vitrine de toutes les galeries
// ============================================================
require_once __DIR__ . '/config.php';

$galleries    = list_galleries();

// Appliquer l'ordre personnalisé défini dans l'administration
if (!empty($SETTINGS['gallery_order']) && is_array($SETTINGS['gallery_order'])) {
    $orderMap = array_flip($SETTINGS['gallery_order']);
    usort($galleries, function($a, $b) use ($orderMap) {
        $ia = $orderMap[$a['slug']] ?? PHP_INT_MAX;
        $ib = $orderMap[$b['slug']] ?? PHP_INT_MAX;
        return $ia <=> $ib;
    });
}

$home_title   = $SETTINGS['home_title']            ?? 'Galeries';
$home_desc    = $SETTINGS['home_description']      ?? 'Illustrations Pixiv par personnage';
$home_fl_label = $SETTINGS['home_footer_link_label'] ?? '';
$home_fl_url   = $SETTINGS['home_footer_link_url']   ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($home_title) ?> — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">

</head>
<body>

<!-- Hero -->
<header class="home-hero">
    <p class="site-label">Pixivorama</p>
    <h1><?= htmlspecialchars($home_title) ?></h1>
    <?php if ($home_desc !== ''): ?>
    <p class="home-tagline"><?= htmlspecialchars($home_desc) ?></p>
    <?php endif; ?>
    <a class="admin-link" href="admin.php" title="Administration">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
        </svg>
    </a>
</header>

<?php if (empty($galleries)): ?>
<div class="home-empty">
    <p>Aucune galerie pour l'instant.</p>
    <a href="admin.php?tab=galleries">Créer une galerie</a>
</div>
<?php else: ?>

<div class="galleries-grid" id="galleriesGrid">
    <?php foreach ($galleries as $g): ?>
    <a class="gallery-card"
       href="galleries/<?= htmlspecialchars($g['slug']) ?>.php"
       data-slug="<?= htmlspecialchars($g['slug']) ?>"
       data-tags="<?= htmlspecialchars(json_encode(array_column($g['characters'], 'tag'))) ?>">

        <!-- Mosaïque (remplie par JS) -->
        <div class="gc-mosaic" id="mosaic-<?= htmlspecialchars($g['slug']) ?>">
            <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="gc-mosaic-placeholder"></div>
            <?php endfor; ?>
        </div>

        <div class="gc-overlay"></div>

        <div class="gc-content">
            <span class="gc-label">Galerie</span>
            <h2 class="gc-title"><?= htmlspecialchars($g['title']) ?></h2>
            <div class="gc-tags">
                <?php foreach ($g['characters'] as $char): ?>
                <span class="gc-tag"><?= htmlspecialchars($char['label']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <span class="gc-arrow">→</span>
    </a>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Pied de page -->
<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-sep"></span>
        <a class="footer-link" href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
            </svg>
            Dépôt Git
        </a>
        <span class="footer-sep"></span>
        <?php if ($home_fl_label !== '' && $home_fl_url !== ''): ?>
        <a class="footer-link" href="<?= htmlspecialchars($home_fl_url) ?>" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            <?= htmlspecialchars($home_fl_label) ?>
        </a>
        <span class="footer-sep"></span>
        <?php endif; ?>
    </div>
</footer>

<script>
/* ── Chargement des aperçus pour chaque galerie ── */
(async function loadPreviews() {
    const cards = document.querySelectorAll('.gallery-card[data-slug]');

    for (const card of cards) {
        const slug = card.dataset.slug;
        let tags;
        try { tags = JSON.parse(card.dataset.tags); } catch { continue; }
        if (!tags.length) continue;

        // Choisir jusqu'à 3 tags au hasard parmi la liste
        const shuffled = [...tags].sort(() => Math.random() - .5);
        const chosen   = shuffled.slice(0, Math.min(3, shuffled.length));

        // Récupérer quelques images par tag
        const thumbs = [];
        for (const tag of chosen) {
            if (thumbs.length >= 6) break;
            try {
                const res  = await fetch(`pixiv-proxy.php?tag=${encodeURIComponent(tag)}&page=1&per_page=28&order=popular_d&mode=safe&gallery=${encodeURIComponent(slug)}`);
                const data = await res.json();
                if (data.works) {
                    // Prendre 2 images au hasard dans les 10 premières
                    const pool = data.works.slice(0, 10).sort(() => Math.random() - .5);
                    for (const w of pool.slice(0, 2)) {
                        if (thumbs.length < 6) {
                            thumbs.push(w.thumb.replace('https://i.pximg.net', 'https://i.pixiv.re'));
                        }
                    }
                }
            } catch {}
        }

        // Remplir la mosaïque
        const mosaic  = document.getElementById('mosaic-' + slug);
        if (!mosaic) continue;

        const cells = mosaic.querySelectorAll('.gc-mosaic-placeholder');
        thumbs.forEach((url, i) => {
            if (i >= cells.length) return;
            const img      = document.createElement('img');
            img.className  = 'gc-mosaic-img';
            img.src        = url;
            img.alt        = '';
            img.loading    = 'lazy';
            img.addEventListener('load', () => {
                cells[i].replaceWith(img);
            });
            img.addEventListener('error', () => { /* garde le placeholder */ });
            // Déclenche la tentative
            const tmp = new Image();
            tmp.src = url;
            tmp.onload  = () => { cells[i].replaceWith(img); };
        });
    }
})();
</script>

</body>
</html>