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
            Dépôt Git (v<?= APP_VERSION ?>)
        </a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="galleries/recherche.php">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            Recherche libre
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
/* ── Chargement des aperçus + carousel dynamique ── */
(async function loadPreviews() {
    const INTERVAL   = 4000;
    const MAX_TAGS   = 6;
    const IMGS_PER_TAG = 2; // → 12 images max au total

    const cards = document.querySelectorAll('.gallery-card[data-slug]');

    for (const card of cards) {
        const slug = card.dataset.slug;
        let tags;
        try { tags = JSON.parse(card.dataset.tags); } catch { continue; }
        if (!tags.length) continue;

        // Sélectionner au plus MAX_TAGS tags au hasard
        const chosen = [...tags].sort(() => Math.random() - .5).slice(0, MAX_TAGS);

        // Charger tous les tags EN PARALLÈLE
        const results = await Promise.all(chosen.map(async tag => {
            try {
                const res  = await fetch(`pixiv-proxy.php?tag=${encodeURIComponent(tag)}&page=1&per_page=28&order=popular_d&mode=safe&gallery=${encodeURIComponent(slug)}`);
                const data = await res.json();
                if (!data.works?.length) return [];
                return data.works
                    .sort(() => Math.random() - .5)
                    .slice(0, IMGS_PER_TAG)
                    .map(w => w.thumb.replace('https://i.pximg.net', 'https://i.pixiv.re'));
            } catch { return []; }
        }));

        // Round-robin inter-tags pour construire le pool final
        const pool = [];
        const seen = new Set();
        const maxLen = Math.max(...results.map(r => r.length));
        for (let i = 0; i < maxLen; i++) {
            for (const urls of results) {
                if (i < urls.length && !seen.has(urls[i])) {
                    seen.add(urls[i]);
                    pool.push(urls[i]);
                }
            }
        }

        if (pool.length < 6) continue;

        // Pré-charger
        pool.forEach(url => { const img = new Image(); img.src = url; });

        const mosaic = document.getElementById('mosaic-' + slug);
        if (!mosaic) continue;

        mosaic.innerHTML = '';
        const cells   = [];
        const visible = new Set();

        for (let i = 0; i < 6; i++) {
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'position:relative;overflow:hidden;width:100%;height:100%;';

            const imgA = document.createElement('img');
            const imgB = document.createElement('img');
            [imgA, imgB].forEach(img => {
                img.className = 'gc-mosaic-img';
                img.alt       = '';
                img.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:brightness(.55) saturate(.8);transition:opacity .9s ease;';
            });
            imgA.style.opacity = '1';
            imgB.style.opacity = '0';

            const url = pool[i];
            imgA.src  = url;
            visible.add(url);

            wrapper.appendChild(imgA);
            wrapper.appendChild(imgB);
            mosaic.appendChild(wrapper);
            cells.push({ imgA, imgB, front: 'A', current: url });
        }

        // Carousel
        setTimeout(() => {
            setInterval(() => {
                const cell      = cells[Math.floor(Math.random() * cells.length)];
                const available = pool.filter(url => !visible.has(url));
                if (!available.length) return;

                const nextUrl = available[Math.floor(Math.random() * available.length)];
                visible.delete(cell.current);
                visible.add(nextUrl);
                cell.current = nextUrl;

                if (cell.front === 'A') {
                    cell.imgB.src = nextUrl;
                    cell.imgB.onload = () => {
                        cell.imgA.style.opacity = '0';
                        cell.imgB.style.opacity = '1';
                        cell.front = 'B';
                    };
                } else {
                    cell.imgA.src = nextUrl;
                    cell.imgA.onload = () => {
                        cell.imgB.style.opacity = '0';
                        cell.imgA.style.opacity = '1';
                        cell.front = 'A';
                    };
                }
            }, INTERVAL);
        }, Math.random() * INTERVAL);
    }
})();
</script>

</body>
</html>