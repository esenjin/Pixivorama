<?php
// ============================================================
//  private-home.php — Page d'accueil de l'espace perso :
//  vitrine des galeries privées (tags + spéciales).
//  Accessible uniquement après connexion à admin.php.
// ============================================================
require_once __DIR__ . '/config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}
if (!isset($_SESSION['admin_ok'])) {
    header('Location: admin.php');
    exit;
}

define('PRIVATE_DIR', __DIR__ . '/private');

// ── Helpers (dupliqués depuis perso.php pour éviter le double include) ──
if (!function_exists('load_private_gallery')) {
    function load_private_gallery(string $slug): ?array {
        if (!is_valid_gallery_slug($slug)) return null;
        $f = PRIVATE_DIR . '/' . $slug . '.json';
        if (!file_exists($f)) return null;
        $d = json_decode(file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }
}

if (!function_exists('list_private_galleries')) {
    function list_private_galleries(): array {
        if (!is_dir(PRIVATE_DIR)) return [];
        $files   = glob(PRIVATE_DIR . '/*.json') ?: [];
        $results = [];
        foreach ($files as $f) {
            $slug = basename($f, '.json');
            if (!is_valid_gallery_slug($slug)) continue;
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) continue;
            $results[] = array_merge(['slug' => $slug], $d);
        }
        usort($results, fn($a, $b) => strcmp($a['slug'], $b['slug']));
        return $results;
    }
}

const SPECIAL_TYPES_HOME = [
    'illust'    => ['label' => 'Mes illustrations', 'icon' => '✦'],
    'bookmark'  => ['label' => 'Mes bookmarks',     'icon' => '♡'],
    'following' => ['label' => 'Artistes suivis',   'icon' => '◈'],
];

// ── Chargement et tri ────────────────────────────────────────
$all_galleries = list_private_galleries();

if (!empty($SETTINGS['private_gallery_order']) && is_array($SETTINGS['private_gallery_order'])) {
    $orderMap = array_flip($SETTINGS['private_gallery_order']);
    usort($all_galleries, function($a, $b) use ($orderMap) {
        $ia = $orderMap[$a['slug']] ?? PHP_INT_MAX;
        $ib = $orderMap[$b['slug']] ?? PHP_INT_MAX;
        return $ia <=> $ib;
    });
}

$tag_galleries     = array_values(array_filter($all_galleries, fn($g) => ($g['type'] ?? 'tag') === 'tag'));
$special_galleries = array_values(array_filter($all_galleries, fn($g) => ($g['type'] ?? 'tag') !== 'tag'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace perso — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300;1,400&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>

<!-- Hero -->
<header class="home-hero">
    <p class="site-label">Espace personnel</p>
    <h1>Galeries privées</h1>
    <a class="admin-link" href="admin.php" title="Administration">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
        </svg>
    </a>
</header>

<?php if (empty($all_galleries)): ?>
<div class="home-empty">
    <p>Aucune galerie privée pour l'instant.</p>
    <a href="perso.php">Créer une galerie</a>
</div>
<?php else: ?>

<!-- ══ Galeries spéciales ══════════════════════════════════════ -->
<?php if (!empty($special_galleries)): ?>
<section class="private-home-section">
    <div class="private-home-section-header">
        <span class="private-home-section-label">Pixiv personnel</span>
        <div class="private-home-section-rule"></div>
    </div>
    <div class="galleries-grid private-galleries-grid">
        <?php foreach ($special_galleries as $g):
            $stype = $g['type'];
            $info  = SPECIAL_TYPES_HOME[$stype] ?? ['label' => $stype, 'icon' => '·'];
        ?>
        <a class="gallery-card private-gallery-card private-gallery-card--special"
           href="private/<?= htmlspecialchars($g['slug']) ?>.php"
           data-slug="<?= htmlspecialchars($g['slug']) ?>"
           data-type="<?= htmlspecialchars($stype) ?>">

            <!-- Fond animé (rempli par JS pour illust/bookmark/following) -->
            <div class="gc-mosaic" id="mosaic-<?= htmlspecialchars($g['slug']) ?>">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="gc-mosaic-placeholder"></div>
                <?php endfor; ?>
            </div>

            <div class="gc-overlay"></div>

            <!-- Icône centrale flottante -->
            <div class="pgc-special-icon"><?= $info['icon'] ?></div>

            <div class="gc-content">
                <span class="gc-label">Pixiv · <?= htmlspecialchars($info['label']) ?></span>
                <h2 class="gc-title"><?= htmlspecialchars($g['title']) ?></h2>
            </div>

            <span class="gc-arrow">→</span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ══ Galeries privées par tags ══════════════════════════════ -->
<?php if (!empty($tag_galleries)): ?>
<section class="private-home-section">
    <div class="private-home-section-header">
        <span class="private-home-section-label">Galeries par tags</span>
        <div class="private-home-section-rule"></div>
    </div>
    <div class="galleries-grid private-galleries-grid"
         id="privateTagGrid">
        <?php foreach ($tag_galleries as $g): ?>
        <a class="gallery-card private-gallery-card"
           href="private/<?= htmlspecialchars($g['slug']) ?>.php"
           data-slug="<?= htmlspecialchars($g['slug']) ?>"
           data-tags="<?= htmlspecialchars(json_encode(array_column($g['characters'] ?? [], 'tag'))) ?>">

            <!-- Mosaïque (remplie par JS) -->
            <div class="gc-mosaic" id="mosaic-<?= htmlspecialchars($g['slug']) ?>">
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="gc-mosaic-placeholder"></div>
                <?php endfor; ?>
            </div>

            <div class="gc-overlay"></div>

            <div class="gc-content">
                <span class="gc-label">Galerie privée</span>
                <h2 class="gc-title"><?= htmlspecialchars($g['title']) ?></h2>
                <div class="gc-tags">
                    <?php foreach ($g['characters'] ?? [] as $char): ?>
                    <span class="gc-tag"><?= htmlspecialchars($char['label']) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <span class="gc-arrow">→</span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<!-- Pied de page -->
<footer class="site-footer">
    <div class="footer-inner">
        <span class="footer-sep"></span>
        <a class="footer-link" href="index.php">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Galeries publiques
        </a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="perso.php">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            Gérer les galeries
        </a>
        <span class="footer-sep"></span>
        <a class="footer-link" href="admin.php">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
            </svg>
            Administration
        </a>
        <span class="footer-sep"></span>
    </div>
</footer>

<script>
/* ── Aperçus des galeries par tags ── */
(async function loadTagPreviews() {
    const INTERVAL    = 4000;
    const MAX_TAGS    = 6;
    const IMGS_PER_TAG = 2;

    const cards = document.querySelectorAll('.private-gallery-card[data-tags]');

    for (const card of cards) {
        const slug = card.dataset.slug;
        let tags;
        try { tags = JSON.parse(card.dataset.tags); } catch { continue; }
        if (!tags.length) continue;

        const chosen = [...tags].sort(() => Math.random() - .5).slice(0, MAX_TAGS);

        const results = await Promise.all(chosen.map(async tag => {
            try {
                const res  = await fetch(`fonctions/pixiv-proxy.php?tag=${encodeURIComponent(tag)}&page=1&per_page=28&order=popular_d&mode=safe&gallery=${encodeURIComponent(slug)}&private=1`);
                const data = await res.json();
                if (!data.works?.length) return [];
                return data.works
                    .sort(() => Math.random() - .5)
                    .slice(0, IMGS_PER_TAG)
                    .map(w => (w.thumb || '').replace('https://i.pximg.net', 'https://i.pixiv.cat'));
            } catch { return []; }
        }));

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

        if (pool.length < 6) {
            const mosaic = document.getElementById('mosaic-' + slug);
            if (mosaic) {
                mosaic.innerHTML = '';
                mosaic.style.cssText = 'position:absolute;inset:0;display:flex;align-items:center;justify-content:center;';
                mosaic.innerHTML = `<span style="
                    font-family:'Josefin Sans',sans-serif;
                    font-size:.6rem;
                    letter-spacing:.18em;
                    text-transform:uppercase;
                    color:rgba(122,120,112,.5);
                    text-align:center;
                    padding:1rem;
                    pointer-events:none;
                ">Aperçu indisponible</span>`;
            }
            continue;
        }

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
            imgA.onerror = function() {
                if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
            };
            visible.add(url);

            wrapper.appendChild(imgA);
            wrapper.appendChild(imgB);
            mosaic.appendChild(wrapper);
            cells.push({ imgA, imgB, front: 'A', current: url });
        }

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
                    cell.imgB.onerror = function() {
                        if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
                    };
                    cell.imgB.onload = () => {
                        cell.imgA.style.opacity = '0';
                        cell.imgB.style.opacity = '1';
                        cell.front = 'B';
                    };
                } else {
                    cell.imgA.src = nextUrl;
                    cell.imgA.onerror = function() {
                        if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
                    };
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

/* ── Aperçus des galeries spéciales (illust, bookmark, following) ── */
(async function loadSpecialPreviews() {
    const INTERVAL = 4500;

    const cards = document.querySelectorAll('.private-gallery-card--special[data-slug]');

    for (const card of cards) {
        const slug  = card.dataset.slug;
        const stype = card.dataset.type;

        let proxyUrl = '';
        if (stype === 'illust')    proxyUrl = `fonctions/private-proxy.php?type=illust&page=1`;
        else if (stype === 'bookmark') proxyUrl = `fonctions/private-proxy.php?type=bookmark&page=1`;
        else if (stype === 'following') proxyUrl = `fonctions/private-proxy.php?type=following&page=1`;
        else continue;

        try {
            const res  = await fetch(proxyUrl);
            const data = await res.json();
            if (!data.works?.length) continue;

            const pool = data.works
                .sort(() => Math.random() - .5)
                .slice(0, 12)
                .map(w => (w.thumb || w.image_urls?.square_medium || '').replace('https://i.pximg.net', 'https://i.pixiv.cat'))
                .filter(Boolean);

            if (pool.length < 6) continue;

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
                imgA.onerror = function() {
                    if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
                };
                visible.add(url);

                wrapper.appendChild(imgA);
                wrapper.appendChild(imgB);
                mosaic.appendChild(wrapper);
                cells.push({ imgA, imgB, front: 'A', current: url });
            }

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
                        cell.imgB.onerror = function() {
                            if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
                        };
                        cell.imgB.onload = () => {
                            cell.imgA.style.opacity = '0';
                            cell.imgB.style.opacity = '1';
                            cell.front = 'B';
                        };
                    } else {
                        cell.imgA.src = nextUrl;
                        cell.imgA.onerror = function() {
                            if (this.src.includes('pixiv.cat')) { this.onerror = null; this.src = this.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re'); }
                        };
                        cell.imgA.onload = () => {
                            cell.imgB.style.opacity = '0';
                            cell.imgA.style.opacity = '1';
                            cell.front = 'A';
                        };
                    }
                }, INTERVAL);
            }, Math.random() * INTERVAL);

        } catch { /* silencieux */ }
    }
})();
</script>

</body>
</html>
