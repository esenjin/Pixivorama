/* ============================================================
   scripts-special.js — Galeries spéciales Pixiv (sans tags)
   ============================================================ */

const PROXY_URL    = window.PIXIV_PROXY_URL    || 'private-proxy.php';
const EXTRA_PARAMS = window.PIXIV_EXTRA_PARAMS || '';
const HAS_ORDER    = window.PIXIV_HAS_ORDER   !== false;
const HAS_PERPAGE  = window.PIXIV_HAS_PERPAGE !== false;

const IS_FOLLOWING = EXTRA_PARAMS.includes('type=following');

const GALLERY_SLUG   = location.pathname.split('/').pop().replace('.php', '');
const SEEN_STORE_KEY = `pixiv_seen_${GALLERY_SLUG}`;

// Préférences admin
const _DEFS = window.PIXIV_DEFAULTS || {};

let currentPage    = 1;
let currentPerPage = _DEFS.per_page || 28;
let currentOrder   = _DEFS.order    || 'popular_d';
let currentMode    = _DEFS.mode     || 'safe';
let currentPeriod  = _DEFS.period   !== undefined ? _DEFS.period : '';
let loading        = false;

// IDs déjà vus, chargés depuis localStorage — structure : { id: timestampSeconds }
// Les entrées de plus de SEEN_TTL_DAYS jours sont purgées automatiquement au chargement.
const SEEN_TTL_DAYS = 90;

let seenMap = loadSeenMap();       // Map<id, timestamp>
let seenIds = new Set(seenMap.keys()); // Set rapide pour les lookups

// ── Sync des pills avec les defaults admin au chargement ──
(function syncDefaultPills() {
    if (!window.PIXIV_DEFAULTS) return;
    const d = window.PIXIV_DEFAULTS;
    if (d.order) {
        document.querySelectorAll('#orderPicker .pill').forEach(b => {
            b.classList.toggle('active', b.dataset.value === d.order);
        });
    }
    if (d.per_page) {
        document.querySelectorAll('#perPagePicker .pill').forEach(b => {
            b.classList.toggle('active', b.dataset.value == d.per_page);
        });
    }
    if (d.mode) {
        document.querySelectorAll('#contentPicker .pill').forEach(b => {
            b.classList.toggle('active', b.dataset.value === d.mode);
        });
    }
})();

// IDs de la session courante (pour le bouton "marquer comme vues")
let newIdsThisLoad = new Set();

const gallery    = document.getElementById('gallery');
const statusBar  = document.getElementById('statusBar');
const pagination = document.getElementById('pagination');
const btnToTop   = document.getElementById('btnToTop');
const tooltip    = document.getElementById('imgTooltip');

// ── Gestion localStorage des IDs vus ──

/**
 * Charge le Map depuis localStorage et purge les entrées expirées.
 * Retourne un Map<string, number> (id → timestamp Unix en secondes).
 */
function loadSeenMap() {
    const cutoff = Math.floor(Date.now() / 1000) - SEEN_TTL_DAYS * 86400;
    try {
        const raw = localStorage.getItem(SEEN_STORE_KEY);
        if (!raw) return new Map();

        const parsed = JSON.parse(raw);

        // Compatibilité avec l'ancien format (tableau simple d'IDs sans timestamp)
        if (Array.isArray(parsed)) {
            // Migration : on leur attribue "maintenant" pour ne pas les expirer aussitôt
            const now = Math.floor(Date.now() / 1000);
            return new Map(parsed.map(id => [String(id), now]));
        }

        // Format normal : objet { id: timestamp }
        const map = new Map();
        for (const [id, ts] of Object.entries(parsed)) {
            if (ts >= cutoff) map.set(id, ts); // entrées expirées silencieusement écartées
        }
        return map;
    } catch { return new Map(); }
}

function saveSeenMap() {
    try {
        const obj = Object.fromEntries(seenMap);
        localStorage.setItem(SEEN_STORE_KEY, JSON.stringify(obj));
    } catch {}
}

function markAllSeen() {
    const now = Math.floor(Date.now() / 1000);
    newIdsThisLoad.forEach(id => {
        seenMap.set(id, now);
        seenIds.add(id);
    });
    saveSeenMap();
    newIdsThisLoad.clear();
    // Retirer visuellement les badges new
    gallery.querySelectorAll('.card.is-new').forEach(card => {
        card.classList.remove('is-new');
        card.querySelector('.badge-new')?.remove();
    });
    updateNewBanner(0);
}

// ── Bannière "X nouveautés — Marquer comme vues" ──
function updateNewBanner(count) {
    let banner = document.getElementById('newBanner');
    if (count === 0) {
        banner?.remove();
        return;
    }
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'newBanner';
        banner.className = 'new-banner';
        // Insérer juste avant la galerie
        gallery.parentNode.insertBefore(banner, gallery);
    }
    banner.innerHTML = `
        <span class="new-banner-count">
            <span class="new-banner-dot"></span>
            ${count} nouvelle${count > 1 ? 's' : ''} illustration${count > 1 ? 's' : ''}
        </span>
        <button class="new-banner-btn" id="markSeenBtn">Marquer comme vues</button>
    `;
    document.getElementById('markSeenBtn').addEventListener('click', markAllSeen);
}

// ── Squelettes de chargement ──
function showSkeletons(n = 12) {
    gallery.innerHTML = Array.from({length: n}, () => `
        <div class="skeleton">
            <div class="skeleton-thumb"></div>
            <div class="skeleton-line"></div>
        </div>
    `).join('');
}

// ── Chargement principal ──
async function load(page) {
    if (loading) return;
    loading = true;
    pagination.style.display = 'none';
    statusBar.textContent = 'Chargement…';
    showSkeletons(currentPerPage > 56 ? 24 : 12);

    try {
        const base = PROXY_URL + (EXTRA_PARAMS ? '?' + EXTRA_PARAMS + '&' : '?');
        const periodParam = (HAS_ORDER && currentOrder === 'popular_d' && currentPeriod)
            ? `&period=${encodeURIComponent(currentPeriod)}` : '';
        let url = `${base}page=${page}&per_page=${currentPerPage}`;
        if (HAS_ORDER) url += `&order=${currentOrder}${periodParam}`;
        if (window.PIXIV_HAS_MODE !== false) url += `&mode=${currentMode}`;

        const res  = await fetch(url);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        // Sur la 1ère page, réinitialiser les nouveautés de cette session
        if (page === 1) newIdsThisLoad = new Set();

        render(data.works);
        updateStatus(data.total, page, data.perPage);
        updatePagination(page, data.total, data.perPage);
    } catch (err) {
        gallery.innerHTML = `
            <div class="error-msg" style="grid-column:1/-1">
                <strong>Erreur</strong>${escHtml(err.message)}
            </div>`;
        statusBar.textContent = '—';
        document.getElementById('newBanner')?.remove();
    } finally {
        loading = false;
    }
}

// ── Rendu des cartes ──
function render(works) {
    if (!works || !works.length) {
        gallery.innerHTML = `<div class="error-msg" style="grid-column:1/-1">Aucune illustration trouvée.</div>`;
        if (IS_FOLLOWING) updateNewBanner(0);
        return;
    }

    gallery.innerHTML = works.map((w, i) => {
        const pixivUrl = `https://www.pixiv.net/en/artworks/${w.id}`;
        const delay    = (i % 24) * 25;
        const pages    = w.pageCount > 1 ? `<span class="badge-pages">${w.pageCount}</span>` : '';
        const r18Badge  = w.xRestrict  >= 1 ? `<span class="badge-r18">18+</span>`  : '';
        const gifBadge  = w.illustType === 2 ? `<span class="badge-gif">GIF</span>`  : '';
        const thumbUrl = w.thumb.replace('https://i.pximg.net', 'https://i.pixiv.re');

        // Détection nouveauté (uniquement pour following)
        const isNew = IS_FOLLOWING && !seenIds.has(w.id);
        if (isNew) newIdsThisLoad.add(w.id);

        const newBadge = isNew ? `<span class="badge-new">Nouveau</span>` : '';
        const newClass = isNew ? ' is-new' : '';

        return `
        <a class="card${newClass}" href="${pixivUrl}" target="_blank" rel="noopener"
           style="animation-delay:${delay}ms"
           data-id="${escHtml(w.id)}"
           data-title="${escHtml(w.title)}"
           data-artist="${escHtml(w.userName)}">
            <div class="thumb-wrap">
                <img src="${thumbUrl}" alt="${escHtml(w.title)}" loading="lazy">
                ${pages}
                ${r18Badge}
                ${gifBadge}
                ${newBadge}
            </div>
            <div class="card-info">
                <div class="card-artist">${escHtml(w.userName)}</div>
            </div>
        </a>`;
    }).join('');

    // Mettre à jour la bannière avec le nombre de nouvelles illustrations
    if (IS_FOLLOWING) updateNewBanner(newIdsThisLoad.size);

    attachTooltips();
}

// ── Tooltip titre ──
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

// ── Status bar ──
function updateStatus(total, page, perPage) {
    const pp = perPage || currentPerPage;
    const totalPages = Math.ceil(total / pp);
    if (!total) { statusBar.textContent = 'Aucune illustration.'; return; }
    statusBar.textContent = `${total.toLocaleString('fr-FR')} illustration${total > 1 ? 's' : ''} — page ${page} / ${totalPages}`;
}

// ── Pagination ──
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
                load(currentPage);
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
    load(currentPage);
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
    document.getElementById('periodPickerWrap')?.remove();

    const bar = document.querySelector('.controls-bar');
    if (!bar) return;

    const group = document.createElement('div');
    group.className = 'control-group period-picker-group';
    group.id = 'periodPickerWrap';
    group.innerHTML = `
        <span class="control-label">Période</span>
        <div class="control-pills" id="periodPicker">
            ${PERIOD_OPTIONS.map(o =>
                `<button class="pill${o.value === currentPeriod ? ' active' : ''}"
                         data-value="${o.value}">${o.label}</button>`
            ).join('')}
        </div>`;

    const orderGroup = document.getElementById('orderPicker')?.closest('.control-group');
    if (orderGroup && orderGroup.nextSibling) {
        bar.insertBefore(group, orderGroup.nextSibling);
    } else {
        bar.appendChild(group);
    }

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
const orderPickerEl = document.getElementById('orderPicker');
if (orderPickerEl) {
    // Afficher ou non le period picker selon le défaut
    if (currentOrder === 'popular_d') buildPeriodPicker();
    // Si date_d, pas de picker période

    orderPickerEl.addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        orderPickerEl.querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentOrder = btn.dataset.value;
        if (currentOrder === 'popular_d') buildPeriodPicker();
        else removePeriodPicker();
        resetPage();
    });
}

const perPagePickerEl = document.getElementById('perPagePicker');
if (perPagePickerEl) {
    perPagePickerEl.addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        perPagePickerEl.querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentPerPage = parseInt(btn.dataset.value, 10);
        resetPage();
    });
}

const contentPickerSpEl = document.getElementById('contentPicker');
if (contentPickerSpEl) {
    contentPickerSpEl.addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        contentPickerSpEl.querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentMode = btn.dataset.value;
        resetPage();
    });
}

window.addEventListener('scroll', () => {
    btnToTop.classList.toggle('visible', window.scrollY > 400);
}, { passive: true });
btnToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

load(currentPage);