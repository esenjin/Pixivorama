/* ============================================================
   scripts.js — Galerie Pixiv
   ============================================================ */

const PROXY_URL    = window.PIXIV_PROXY_URL    || 'fonctions/pixiv-proxy.php';
const EXTRA_PARAMS = window.PIXIV_EXTRA_PARAMS || '';

// ── Proxy d'images : pixiv.cat en principal, pixiv.re en fallback ──
function pixivThumb(url) {
    return (url || '').replace('https://i.pximg.net', 'https://i.pixiv.cat');
}
function pixivThumbFallback(img) {
    if (!img.src.includes('pixiv.cat')) return;
    img.onerror = null;
    img.src = img.src.replace('https://i.pixiv.cat', 'https://i.pixiv.re');
}

// Appliquer les préférences admin si présentes, sinon valeurs publiques par défaut
const _DEFS = window.PIXIV_DEFAULTS || {};

let currentTag     = window.PIXIV_INITIAL_TAG;
let currentPage    = 1;
let currentPerPage = _DEFS.per_page || 28;
let currentOrder   = _DEFS.order    || 'popular_d';
let currentMode    = _DEFS.mode     || 'safe';
let currentPeriod  = _DEFS.period   !== undefined ? _DEFS.period : '';
let totalWorks     = 0;
let loading        = false;

const gallery    = document.getElementById('gallery');
const statusBar  = document.getElementById('statusBar');
const pagination = document.getElementById('pagination');
const btnToTop   = document.getElementById('btnToTop');
const tooltip    = document.getElementById('imgTooltip');

// ── Sync des pills avec les defaults au chargement ──
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

// ── Seen — suivi des illustrations vues (admin uniquement) ────
//
//  Activé si window.PIXIV_IS_ADMIN === true ET seen_scope !== 'none'.
//  seen_scope : 'following' (défaut legacy) | 'all' | 'none'
//
//  seenIds        : Set<string> — IDs globalement déjà vus (chargés depuis le serveur)
//  newIdsThisLoad : Set<string> — IDs nouveaux dans le tag courant (réinitialisé à chaque
//                                  changement de tag), utilisés pour le bouton "Marquer"
//
const IS_ADMIN      = window.PIXIV_IS_ADMIN      === true;
const SEEN_ENDPOINT = window.PIXIV_SEEN_ENDPOINT || '';
// 'all' = toutes galeries, 'none' = désactivé, tout autre valeur = inactif
const SEEN_SCOPE    = window.PIXIV_SEEN_SCOPE    || 'none';
const SEEN_ACTIVE   = IS_ADMIN && SEEN_SCOPE === 'all' && SEEN_ENDPOINT !== '';

let seenIds        = new Set();
let seenReady      = false;
let newIdsThisLoad = new Set();

/**
 * Charge les IDs vus depuis le serveur.
 * Appelé une seule fois au premier load().
 */
async function initSeenIds() {
    if (!SEEN_ACTIVE) { seenReady = true; return; }
    try {
        const res  = await fetch(SEEN_ENDPOINT + '?action=load');
        const data = await res.json();
        if (data.ok && data.seen) seenIds = new Set(Object.keys(data.seen));
    } catch (e) {
        console.warn('[Pixivorama] Impossible de charger les IDs vus :', e);
    }
    seenReady = true;
}

/**
 * Envoie au serveur les IDs nouveaux du tag courant et met à jour l'UI.
 * Seuls les IDs de newIdsThisLoad (tag actif) sont marqués.
 */
async function markAllSeen() {
    if (!newIdsThisLoad.size) return;

    const ids = [...newIdsThisLoad];

    // Mise à jour optimiste de l'UI
    ids.forEach(id => seenIds.add(id));
    newIdsThisLoad.clear();
    gallery.querySelectorAll('.card.is-new').forEach(card => {
        card.classList.remove('is-new');
        card.querySelector('.badge-new')?.remove();
    });
    updateNewBanner(0);

    // Persistance serveur
    try {
        const fd = new FormData();
        fd.append('action', 'mark');
        fd.append('ids', JSON.stringify(ids));
        const res  = await fetch(SEEN_ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Erreur inconnue');
    } catch (e) {
        console.warn('[Pixivorama] Impossible de sauvegarder les IDs vus :', e);
    }
}

/**
 * Affiche ou masque la bannière "X nouvelles — Marquer comme vues".
 */
function updateNewBanner(count) {
    let banner = document.getElementById('newBanner');
    if (count === 0) { banner?.remove(); return; }
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'newBanner';
        banner.className = 'new-banner';
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
async function load(tag, page) {
    if (loading) return;

    // Initialiser les IDs vus au tout premier appel (une seule fois)
    if (!seenReady) await initSeenIds();

    // Réinitialiser les nouveautés à chaque chargement de tag/page
    newIdsThisLoad = new Set();

    loading = true;
    pagination.style.display = 'none';
    statusBar.textContent = 'Chargement…';
    showSkeletons(currentPerPage > 56 ? 24 : 12);

    try {
        const sep  = EXTRA_PARAMS ? '&' : '';
        const base = PROXY_URL + (EXTRA_PARAMS ? '?' + EXTRA_PARAMS : '');
        const periodParam = (currentOrder === 'popular_d' && currentPeriod)
            ? `&period=${encodeURIComponent(currentPeriod)}` : '';
        const url  = `${base}${sep || '?'}tag=${encodeURIComponent(tag)}&page=${page}`
            + `&per_page=${currentPerPage}&order=${currentOrder}&mode=${currentMode}${periodParam}`;
        const res  = await fetch(url);
        const data = await res.json();

        if (data.error) throw new Error(data.error);

        totalWorks = data.total;
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

// ── Rendu des cartes ──
function render(works) {
    if (!works.length) {
        gallery.innerHTML = `<div class="error-msg" style="grid-column:1/-1">Aucune illustration trouvée.</div>`;
        if (SEEN_ACTIVE) updateNewBanner(0);
        return;
    }
    gallery.innerHTML = works.map((w, i) => {
        const pixivUrl = `https://www.pixiv.net/en/artworks/${w.id}`;
        const delay    = (i % 24) * 25;
        const pages    = w.pageCount > 1
            ? `<span class="badge-pages">${w.pageCount}</span>` : '';
        const r18Badge  = w.xRestrict  >= 1 ? `<span class="badge-r18">18+</span>`  : '';
        const gifBadge  = w.illustType === 2 ? `<span class="badge-gif">GIF</span>`  : '';
        const thumbUrl = pixivThumb(w.thumb);

        // Détection nouveauté (admin uniquement, scope 'all')
        const isNew    = SEEN_ACTIVE && !seenIds.has(String(w.id));
        if (isNew) newIdsThisLoad.add(String(w.id));
        const newBadge = isNew ? `<span class="badge-new">Nouveau</span>` : '';
        const newClass = isNew ? ' is-new' : '';

        return `
        <a class="card${newClass}" href="${pixivUrl}" target="_blank" rel="noopener"
           style="animation-delay:${delay}ms"
           data-id="${escHtml(String(w.id))}"
           data-title="${escHtml(w.title)}"
           data-artist="${escHtml(w.userName)}">
            <div class="thumb-wrap">
                <img src="${thumbUrl}" alt="${escHtml(w.title)}" loading="lazy" onerror="pixivThumbFallback(this)">
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

    if (SEEN_ACTIVE) updateNewBanner(newIdsThisLoad.size);
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
        // Sur mobile, le tooltip reste affiché après le tap — on le masque immédiatement
        card.addEventListener('click', () => tooltip.classList.remove('visible'));
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
                load(currentTag, currentPage);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
}

// ── Helpers ──
// buildPaginationHTML() est défini dans pagination.js (chargé avant ce fichier)
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function resetPage() {
    currentPage = 1;
    load(currentTag, currentPage);
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
    buildPeriodPicker(); // affiché dès le départ (tri par popularité par défaut)

    orderPickerEl.addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        document.querySelectorAll('#orderPicker .pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentOrder = btn.dataset.value;
        if (currentOrder === 'popular_d') buildPeriodPicker();
        else removePeriodPicker();
        resetPage();
    });

    // Si le default admin est "date_d", pas de period picker au départ
    if (currentOrder === 'date_d') removePeriodPicker();
}

document.getElementById('perPagePicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#perPagePicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentPerPage = parseInt(btn.dataset.value, 10);
    resetPage();
});

const contentPickerEl = document.getElementById('contentPicker');
if (contentPickerEl) {
    contentPickerEl.addEventListener('click', e => {
        const btn = e.target.closest('.pill');
        if (!btn || btn.classList.contains('active')) return;
        contentPickerEl.querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentMode = btn.dataset.value;
        resetPage();
    });
}

window.addEventListener('scroll', () => {
    btnToTop.classList.toggle('visible', window.scrollY > 400);
}, { passive: true });
btnToTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

const charSelectorEl = document.getElementById('charSelector');
if (charSelectorEl) {
    // ── Menu déroulant si plus de 5 tags ──
    const allBtns = Array.from(charSelectorEl.querySelectorAll('.char-btn'));
    const THRESHOLD = 5;

    if (allBtns.length > THRESHOLD) {
        // Masquer les boutons au-delà du seuil
        allBtns.slice(THRESHOLD).forEach(b => b.classList.add('char-btn--hidden'));

        const toggle = document.createElement('button');
        toggle.className = 'char-btn char-btn--toggle';
        toggle.textContent = `+ ${allBtns.length - THRESHOLD} tags`;
        toggle.setAttribute('aria-expanded', 'false');
        charSelectorEl.appendChild(toggle);

        let expanded = false;
        toggle.addEventListener('click', () => {
            expanded = !expanded;
            allBtns.slice(THRESHOLD).forEach(b => b.classList.toggle('char-btn--hidden', !expanded));
            toggle.textContent = expanded ? '− Réduire' : `+ ${allBtns.length - THRESHOLD} tags`;
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.classList.toggle('char-btn--toggle-open', expanded);
        });
    }

    charSelectorEl.addEventListener('click', e => {
        const btn = e.target.closest('.char-btn:not(.char-btn--toggle)');
        if (!btn) return;
        document.querySelectorAll('.char-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentTag  = btn.dataset.tag;
        currentPage = 1;
        load(currentTag, currentPage);
    });
}

// ── Init ──
load(currentTag, currentPage);
