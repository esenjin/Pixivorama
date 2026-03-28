<?php
// ============================================================
//  galleries/recherche.php — Recherche libre par tag Pixiv
//  Page publique — aucune authentification requise.
// ============================================================
require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche libre — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css">
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <style>
        /* ── Champ de recherche libre ── */
        .search-wrap {
            display: flex;
            justify-content: center;
            padding: 2rem 2rem 0;
        }
        .search-form {
            display: flex;
            align-items: stretch;
            width: 100%;
            max-width: 560px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            transition: border-color .25s;
        }
        .search-form:focus-within {
            border-color: var(--accent-dim);
        }
        .search-input {
            flex: 1;
            background: var(--surface);
            border: none;
            color: var(--text);
            font-family: 'Josefin Sans', sans-serif;
            font-size: .85rem;
            font-weight: 300;
            letter-spacing: .06em;
            padding: .8rem 1.1rem;
            outline: none;
            min-width: 0;
        }
        .search-input::placeholder {
            color: var(--text-muted);
            opacity: .7;
        }
        .search-btn {
            background: rgba(200,169,126,.08);
            border: none;
            border-left: 1px solid var(--border);
            color: var(--accent);
            font-family: 'Josefin Sans', sans-serif;
            font-size: .65rem;
            font-weight: 400;
            letter-spacing: .2em;
            text-transform: uppercase;
            padding: .8rem 1.4rem;
            cursor: pointer;
            transition: background .2s;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .search-btn:hover { background: rgba(200,169,126,.16); }

        .search-hint {
            text-align: center;
            font-size: .6rem;
            color: var(--text-muted);
            letter-spacing: .08em;
            padding: .6rem 2rem 0;
        }
        .search-hint em {
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
            font-size: .85rem;
            color: var(--text-muted);
        }

        /* Tag actif */
        .active-tag-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .6rem;
            padding: 1rem 2rem 0;
            font-size: .65rem;
            letter-spacing: .2em;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .active-tag-label {
            color: var(--accent);
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.15rem;
            font-style: italic;
            font-weight: 300;
            letter-spacing: .04em;
            text-transform: none;
        }

        /* État vide initial */
        .search-empty {
            text-align: center;
            padding: 5rem 2rem;
            color: var(--text-muted);
            grid-column: 1 / -1;
        }
        .search-empty-icon {
            font-size: 2.2rem;
            display: block;
            margin-bottom: 1.2rem;
            opacity: .25;
            font-family: 'Cormorant Garamond', serif;
            font-style: italic;
        }
        .search-empty p {
            font-size: .68rem;
            letter-spacing: .2em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<header>
    <p class="site-label"><a href="../index.php" style="color:inherit;text-decoration:none;">Galerie</a></p>
    <h1>Recherche libre</h1>
    <a class="admin-link" href="../admin.php" title="Administration">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="8" r="4"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
        </svg>
    </a>
</header>

<!-- Champ de recherche -->
<div class="search-wrap">
    <div class="search-form">
        <input
            type="text"
            class="search-input"
            id="searchInput"
            placeholder="Entrez un tag Pixiv…"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            maxlength="200"
        >
        <button type="button" class="search-btn" id="searchBtn">Rechercher</button>
    </div>
</div>
<p class="search-hint">
    Pour plus d'informations sur les tags, voir <a href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama/wiki/Bien-choisir-ses-tags-Pixiv" target="_blank" style="color:var(--text-muted);" rel="noopener">notre wiki</a>.
</p>

<!-- Tag actif (masqué jusqu'à la première recherche) -->
<div class="active-tag-bar" id="activeTagBar" style="display:none">
    <span>Tag :</span>
    <span class="active-tag-label" id="activeTagLabel"></span>
</div>

<!-- Contrôles (masqués jusqu'à la première recherche) -->
<div class="controls-bar" id="controlsBar" style="display:none">
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

<div class="status-bar" id="statusBar" style="display:none">—</div>

<main class="gallery" id="gallery">
    <div class="search-empty" id="emptyState">
        <span class="search-empty-icon">✦</span>
        <p>Entrez un tag pour commencer</p>
    </div>
</main>

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
    </div>
</footer>

<script>
const PROXY_URL = '../pixiv-proxy.php';

let currentTag     = '';
let currentPage    = 1;
let currentPerPage = 28;
let currentOrder   = 'popular_d';
let currentMode    = 'safe'; // 'safe' | 'r18' | 'all'
let currentPeriod  = '';
let loading        = false;

const gallery        = document.getElementById('gallery');
const statusBar      = document.getElementById('statusBar');
const pagination     = document.getElementById('pagination');
const btnToTop       = document.getElementById('btnToTop');
const tooltip        = document.getElementById('imgTooltip');
const controlsBar    = document.getElementById('controlsBar');
const activeTagBar   = document.getElementById('activeTagBar');
const activeTagLabel = document.getElementById('activeTagLabel');
const searchInput    = document.getElementById('searchInput');
const searchBtn      = document.getElementById('searchBtn');

// ── Démarrer une recherche ──
function startSearch() {
    const tag = searchInput.value.trim();
    if (!tag) return;

    currentTag  = tag;
    currentPage = 1;

    // Révéler les éléments de contrôle
    controlsBar.style.display  = '';
    statusBar.style.display    = '';
    activeTagBar.style.display = '';
    activeTagLabel.textContent = tag;

    if (currentOrder === 'popular_d') buildPeriodPicker();
    load(tag, 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Squelettes ──
function showSkeletons(n = 12) {
    gallery.innerHTML = Array.from({length: n}, () => `
        <div class="skeleton">
            <div class="skeleton-thumb"></div>
            <div class="skeleton-line"></div>
        </div>
    `).join('');
}

// ── Chargement ──
async function load(tag, page) {
    if (loading) return;
    loading = true;
    pagination.style.display = 'none';
    statusBar.textContent = 'Chargement…';
    showSkeletons(currentPerPage > 56 ? 24 : 12);

    try {
        const periodParam = (currentOrder === 'popular_d' && currentPeriod)
            ? `&period=${encodeURIComponent(currentPeriod)}` : '';
        const url = `${PROXY_URL}?free_search=1&tag=${encodeURIComponent(tag)}&page=${page}`
            + `&per_page=${currentPerPage}&order=${currentOrder}&mode=${currentMode}${periodParam}`;

        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        render(data.works);
        updateStatus(data.total, page, data.perPage);
        updatePagination(page, data.total, data.perPage);
    } catch (err) {
        gallery.innerHTML = `
            <div class="error-msg" style="grid-column:1/-1">
                <strong>Erreur</strong>${escHtml(err.message)}
            </div>`;
        statusBar.textContent = '—';
    } finally {
        loading = false;
    }
}

// ── Rendu ──
function render(works) {
    if (!works || !works.length) {
        gallery.innerHTML = `<div class="error-msg" style="grid-column:1/-1">Aucune illustration trouvée pour ce tag.</div>`;
        return;
    }
    gallery.innerHTML = works.map((w, i) => {
        const pixivUrl = `https://www.pixiv.net/en/artworks/${w.id}`;
        const delay    = (i % 24) * 25;
        const pages    = w.pageCount > 1 ? `<span class="badge-pages">${w.pageCount}</span>` : '';
        const thumbUrl = w.thumb.replace('https://i.pximg.net', 'https://i.pixiv.re');
        return `
        <a class="card" href="${pixivUrl}" target="_blank" rel="noopener"
           style="animation-delay:${delay}ms"
           data-title="${escHtml(w.title)}"
           data-artist="${escHtml(w.userName)}">
            <div class="thumb-wrap">
                <img src="${thumbUrl}" alt="${escHtml(w.title)}" loading="lazy">
                ${pages}
            </div>
            <div class="card-info">
                <div class="card-artist">${escHtml(w.userName)}</div>
            </div>
        </a>`;
    }).join('');
    attachTooltips();
}

// ── Tooltip ──
function attachTooltips() {
    gallery.querySelectorAll('.card').forEach(card => {
        card.addEventListener('mouseenter', e => {
            tooltip.textContent = card.dataset.title;
            tooltip.classList.add('visible');
            positionTooltip(e);
        });
        card.addEventListener('mousemove', positionTooltip);
        card.addEventListener('mouseleave', () => tooltip.classList.remove('visible'));
    });
}
function positionTooltip(e) {
    const margin = 14;
    let x = e.clientX + margin;
    let y = e.clientY + margin;
    if (x + tooltip.offsetWidth + margin > window.innerWidth) x = e.clientX - tooltip.offsetWidth - margin;
    tooltip.style.left = x + 'px';
    tooltip.style.top  = y + 'px';
}

// ── Status & Pagination ──
function updateStatus(total, page, perPage) {
    const pp = perPage || currentPerPage;
    const totalPages = Math.ceil(total / pp);
    statusBar.textContent = `${total.toLocaleString('fr-FR')} illustration${total > 1 ? 's' : ''} — page ${page} / ${totalPages}`;
}

function updatePagination(page, total, perPage) {
    const pp = perPage || currentPerPage;
    const totalPages = Math.ceil(total / pp);
    if (totalPages <= 1) { pagination.style.display = 'none'; return; }
    pagination.style.display = 'flex';
    pagination.innerHTML = buildPaginationHTML(page, totalPages);
    pagination.querySelectorAll('[data-page]').forEach(btn => {
        btn.addEventListener('click', () => {
            const p = parseInt(btn.dataset.page, 10);
            if (!isNaN(p) && p !== currentPage) {
                currentPage = p;
                load(currentTag, currentPage);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
}

function buildPaginationHTML(page, totalPages) {
    const SIBLINGS = 2;
    const parts = [];
    parts.push(`<button class="page-btn page-edge" data-page="1" ${page === 1 ? 'disabled' : ''} title="Première page">«</button>`);
    parts.push(`<button class="page-btn" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>← Préc.</button>`);
    let rangeStart = Math.max(1, page - SIBLINGS);
    let rangeEnd   = Math.min(totalPages, page + SIBLINGS);
    if (rangeEnd - rangeStart < SIBLINGS * 2) {
        if (rangeStart === 1) rangeEnd = Math.min(totalPages, rangeStart + SIBLINGS * 2);
        else rangeStart = Math.max(1, rangeEnd - SIBLINGS * 2);
    }
    if (rangeStart > 1) {
        parts.push(`<button class="page-btn page-num" data-page="1">1</button>`);
        if (rangeStart > 2) parts.push(`<span class="page-ellipsis">…</span>`);
    }
    for (let p = rangeStart; p <= rangeEnd; p++) {
        parts.push(`<button class="page-btn page-num${p === page ? ' active' : ''}" data-page="${p}" ${p === page ? 'disabled' : ''}>${p}</button>`);
    }
    if (rangeEnd < totalPages) {
        if (rangeEnd < totalPages - 1) parts.push(`<span class="page-ellipsis">…</span>`);
        parts.push(`<button class="page-btn page-num" data-page="${totalPages}">${totalPages}</button>`);
    }
    parts.push(`<button class="page-btn" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Suiv. →</button>`);
    parts.push(`<button class="page-btn page-edge" data-page="${totalPages}" ${page === totalPages ? 'disabled' : ''} title="Dernière page">»</button>`);
    return parts.join('');
}

// ── Helpers ──
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function resetPage() {
    currentPage = 1;
    if (currentTag) load(currentTag, currentPage);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Sélecteur de période ──
const PERIOD_OPTIONS = [
    { value: '',       label: '∞' },
    { value: 'year',   label: '1 an' },
    { value: '6month', label: '6 mois' },
    { value: 'month',  label: '1 mois' },
    { value: 'week',   label: '7 jours' },
    { value: 'day',    label: '24h' },
];

function buildPeriodPicker() {
    removePeriodPicker();
    const bar = document.querySelector('.controls-bar');
    if (!bar) return;
    const group = document.createElement('div');
    group.className = 'control-group period-picker-group';
    group.id = 'periodPickerWrap';
    group.innerHTML = `
        <span class="control-label">Période</span>
        <div class="control-pills" id="periodPicker">
            ${PERIOD_OPTIONS.map(o =>
                `<button class="pill${o.value === currentPeriod ? ' active' : ''}" data-value="${o.value}">${o.label}</button>`
            ).join('')}
        </div>`;
    const orderGroup = document.getElementById('orderPicker')?.closest('.control-group');
    if (orderGroup && orderGroup.nextSibling) bar.insertBefore(group, orderGroup.nextSibling);
    else bar.appendChild(group);
    group.querySelector('#periodPicker').addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        group.querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentPeriod = btn.dataset.value;
        resetPage();
    });
}
function removePeriodPicker() {
    document.getElementById('periodPickerWrap')?.remove();
    currentPeriod = '';
}

// ── Contrôles ──
document.getElementById('orderPicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#orderPicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentOrder = btn.dataset.value;
    if (currentOrder === 'popular_d') buildPeriodPicker();
    else removePeriodPicker();
    resetPage();
});

document.getElementById('perPagePicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#perPagePicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentPerPage = parseInt(btn.dataset.value, 10);
    resetPage();
});

document.getElementById('contentPicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#contentPicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentMode = btn.dataset.value;
    resetPage();
});

// ── Événements de recherche ──
searchBtn.addEventListener('click', startSearch);
searchInput.addEventListener('keydown', e => { if (e.key === 'Enter') startSearch(); });

// ── Scroll / top ──
window.addEventListener('scroll', () => {
    btnToTop.classList.toggle('visible', window.scrollY > 400);
}, { passive: true });
btnToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
</script>

</body>
</html>