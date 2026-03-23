/* ============================================================
   scripts.js — Galerie Pixiv
   ============================================================ */

const PROXY_URL = 'pixiv-proxy.php';

let currentTag     = window.PIXIV_INITIAL_TAG;
let currentPage    = 1;
let currentPerPage = 28;
let currentOrder   = 'popular_d';
let currentMode    = 'safe';      // 'safe' | 'r18' | 'all'
let totalWorks     = 0;
let loading        = false;

const gallery    = document.getElementById('gallery');
const statusBar  = document.getElementById('statusBar');
const pagination = document.getElementById('pagination');
const btnToTop   = document.getElementById('btnToTop');
const tooltip    = document.getElementById('imgTooltip');

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
    loading = true;
    pagination.style.display = 'none';
    statusBar.textContent = 'Chargement…';
    showSkeletons(currentPerPage > 56 ? 24 : 12);

    try {
        const url = `${PROXY_URL}?tag=${encodeURIComponent(tag)}&page=${page}`
            + `&per_page=${currentPerPage}&order=${currentOrder}&mode=${currentMode}`;
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
        return;
    }

    gallery.innerHTML = works.map((w, i) => {
        const pixivUrl = `https://www.pixiv.net/en/artworks/${w.id}`;
        const delay    = (i % 24) * 25;
        const pages    = w.pageCount > 1
            ? `<span class="badge-pages">${w.pageCount}</span>` : '';
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

    // Tooltip sur les cartes
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
        card.addEventListener('mouseleave', () => {
            tooltip.classList.remove('visible');
        });
    });
}

function positionTooltip(e) {
    const margin = 14;
    let x = e.clientX + margin;
    let y = e.clientY + margin;
    // Eviter le débordement à droite
    if (x + tooltip.offsetWidth + margin > window.innerWidth) {
        x = e.clientX - tooltip.offsetWidth - margin;
    }
    tooltip.style.left = x + 'px';
    tooltip.style.top  = y + 'px';
}

// ── Status bar ──
function updateStatus(total, page, perPage) {
    const pp         = perPage || currentPerPage;
    const totalPages = Math.ceil(total / pp);
    statusBar.textContent = `${total.toLocaleString('fr-FR')} illustration${total > 1 ? 's' : ''} — page ${page} / ${totalPages}`;
}

// ── Pagination ──
function updatePagination(page, total, perPage) {
    const pp         = perPage || currentPerPage;
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
    load(currentTag, currentPage);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Contrôles ──

// Tri
document.getElementById('orderPicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#orderPicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentOrder = btn.dataset.value;
    resetPage();
});

// Par page
document.getElementById('perPagePicker').addEventListener('click', e => {
    const btn = e.target.closest('.pill');
    if (!btn || btn.classList.contains('active')) return;
    document.querySelectorAll('#perPagePicker .pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentPerPage = parseInt(btn.dataset.value, 10);
    resetPage();
});

// Toggle 18+
document.getElementById('r18Toggle').addEventListener('change', e => {
    currentMode = e.target.checked ? 'r18' : 'safe';
    resetPage();
});

// ── Bouton retour en haut ──
window.addEventListener('scroll', () => {
    btnToTop.classList.toggle('visible', window.scrollY > 400);
}, { passive: true });

btnToTop.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// ── Sélecteur de personnages ──
document.getElementById('charSelector').addEventListener('click', e => {
    const btn = e.target.closest('.char-btn');
    if (!btn) return;
    document.querySelectorAll('.char-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentTag  = btn.dataset.tag;
    currentPage = 1;
    load(currentTag, currentPage);
});

// ── Init ──
load(currentTag, currentPage);
