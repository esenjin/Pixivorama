<?php
// ============================================================
//  index.php — Page d'accueil : vitrine de toutes les galeries
// ============================================================
require_once __DIR__ . '/config.php';

$galleries    = list_galleries();
$home_title   = $SETTINGS['home_title']       ?? 'Galeries';
$home_desc    = $SETTINGS['home_description'] ?? 'Illustrations Pixiv par personnage';
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
    <style>
        /* ── Page d'accueil ── */
        .home-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 5rem 2rem 3rem;
            text-align: center;
            position: relative;
        }
        .home-hero::after {
            content: '';
            display: block;
            width: 40px;
            height: 1px;
            background: var(--accent);
            margin: 1.8rem auto 0;
        }
        .home-tagline {
            font-family: 'Josefin Sans', sans-serif;
            font-size: .65rem;
            font-weight: 400;
            letter-spacing: .4em;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-top: 1rem;
        }

        /* ── Grille des galeries ── */
        .galleries-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2px;
            max-width: 1400px;
            margin: 3rem auto 0;
            padding: 0 2rem 6rem;
        }

        /* ── Carte galerie ── */
        .gallery-card {
            position: relative;
            background: var(--card-bg);
            overflow: hidden;
            cursor: pointer;
            text-decoration: none;
            display: block;
            aspect-ratio: 4 / 3;
            opacity: 0;
            transform: translateY(18px);
            animation: fadeUp .55s forwards;
        }
        .gallery-card:nth-child(1) { animation-delay: .05s; }
        .gallery-card:nth-child(2) { animation-delay: .12s; }
        .gallery-card:nth-child(3) { animation-delay: .19s; }
        .gallery-card:nth-child(4) { animation-delay: .26s; }
        .gallery-card:nth-child(5) { animation-delay: .33s; }
        .gallery-card:nth-child(6) { animation-delay: .40s; }

        /* Mosaïque d'images en arrière-plan */
        .gc-mosaic {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-template-rows: repeat(2, 1fr);
            gap: 2px;
            transition: transform .6s ease;
        }
        .gallery-card:hover .gc-mosaic {
            transform: scale(1.03);
        }
        .gc-mosaic-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            filter: brightness(.55) saturate(.8);
            transition: filter .4s;
        }
        .gallery-card:hover .gc-mosaic-img {
            filter: brightness(.4) saturate(.7);
        }

        /* Placeholder pendant le chargement */
        .gc-mosaic-placeholder {
            background: var(--surface);
            animation: shimmer 1.6s infinite;
            background: linear-gradient(90deg, #1a1a1e 25%, #22222a 50%, #1a1a1e 75%);
            background-size: 200% 100%;
        }

        /* Overlay dégradé */
        .gc-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                to top,
                rgba(13,13,15,.92) 0%,
                rgba(13,13,15,.4)  50%,
                rgba(13,13,15,.1)  100%
            );
            transition: background .4s;
        }
        .gallery-card:hover .gc-overlay {
            background: linear-gradient(
                to top,
                rgba(13,13,15,.96) 0%,
                rgba(13,13,15,.55) 50%,
                rgba(13,13,15,.2)  100%
            );
        }

        /* Contenu texte */
        .gc-content {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 1.6rem 1.8rem;
        }
        .gc-label {
            font-family: 'Josefin Sans', sans-serif;
            font-size: .58rem;
            font-weight: 400;
            letter-spacing: .35em;
            color: var(--accent);
            text-transform: uppercase;
            margin-bottom: .5rem;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity .35s .05s, transform .35s .05s;
        }
        .gallery-card:hover .gc-label { opacity: 1; transform: translateY(0); }
        .gc-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(1.4rem, 2.5vw, 2rem);
            font-weight: 300;
            font-style: italic;
            color: var(--text);
            line-height: 1.15;
            margin-bottom: .7rem;
            transition: color .3s;
        }
        .gallery-card:hover .gc-title { color: #fff; }
        .gc-tags {
            display: flex;
            flex-wrap: wrap;
            gap: .4rem;
            max-height: 0;
            overflow: hidden;
            transition: max-height .4s ease, opacity .35s;
            opacity: 0;
        }
        .gallery-card:hover .gc-tags {
            max-height: 6rem;
            opacity: 1;
        }
        .gc-tag {
            font-family: 'Josefin Sans', sans-serif;
            font-size: .6rem;
            font-weight: 300;
            letter-spacing: .1em;
            color: var(--text-muted);
            background: rgba(255,255,255,.05);
            border: 1px solid var(--border);
            padding: .25rem .6rem;
            border-radius: 2px;
        }
        .gc-arrow {
            position: absolute;
            top: 1.4rem;
            right: 1.6rem;
            font-size: 1rem;
            color: var(--accent);
            opacity: 0;
            transform: translateX(-6px);
            transition: opacity .3s, transform .3s;
        }
        .gallery-card:hover .gc-arrow { opacity: 1; transform: translateX(0); }

        /* ── Page vide ── */
        .home-empty {
            text-align: center;
            padding: 6rem 2rem;
            color: var(--text-muted);
        }
        .home-empty p { font-size: .8rem; letter-spacing: .1em; margin-bottom: 1.5rem; }
        .home-empty a {
            font-size: .65rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: var(--accent);
            text-decoration: none;
            border: 1px solid var(--accent);
            padding: .6rem 1.4rem;
            border-radius: 4px;
            transition: background .2s;
        }
        .home-empty a:hover { background: rgba(200,169,126,.1); }

        /* ── Responsive ── */
        @media (max-width: 700px) {
            .galleries-grid {
                grid-template-columns: 1fr;
                padding: 0 1rem 4rem;
                gap: 2px;
            }
            .gallery-card { aspect-ratio: 16 / 9; }
        }
        @media (min-width: 701px) and (max-width: 1100px) {
            .galleries-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
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
        <a class="footer-link" href="https://esenjin.xyz/blog/mes-waifus/" target="_blank" rel="noopener">
            <svg class="footer-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
            </svg>
            Article
        </a>
        <span class="footer-sep"></span>
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
