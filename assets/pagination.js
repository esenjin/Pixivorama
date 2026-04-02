/* ============================================================
   pagination.js — Construction du HTML de pagination
   Partagé par : scripts.js, scripts-special.js, recherche.php
   ============================================================ */

/**
 * Construit le HTML de la barre de pagination.
 *
 * @param {number} page       - Page courante (1-indexée)
 * @param {number} totalPages - Nombre total de pages
 * @returns {string}          - HTML prêt à injecter
 */
function buildPaginationHTML(page, totalPages) {
    const SIBLINGS = 2;
    const parts = [];

    parts.push(
        `<button class="page-btn page-edge" data-page="1"
                 ${page === 1 ? 'disabled' : ''} title="Première page">«</button>`
    );
    parts.push(
        `<button class="page-btn" data-page="${page - 1}"
                 ${page <= 1 ? 'disabled' : ''}>← Préc.</button>`
    );

    let rangeStart = Math.max(1, page - SIBLINGS);
    let rangeEnd   = Math.min(totalPages, page + SIBLINGS);

    if (rangeEnd - rangeStart < SIBLINGS * 2) {
        if (rangeStart === 1) rangeEnd   = Math.min(totalPages, rangeStart + SIBLINGS * 2);
        else                  rangeStart = Math.max(1, rangeEnd - SIBLINGS * 2);
    }

    if (rangeStart > 1) {
        parts.push(`<button class="page-btn page-num" data-page="1">1</button>`);
        if (rangeStart > 2) parts.push(`<span class="page-ellipsis">…</span>`);
    }

    for (let p = rangeStart; p <= rangeEnd; p++) {
        parts.push(
            `<button class="page-btn page-num${p === page ? ' active' : ''}"
                     data-page="${p}" ${p === page ? 'disabled' : ''}>${p}</button>`
        );
    }

    if (rangeEnd < totalPages) {
        if (rangeEnd < totalPages - 1) parts.push(`<span class="page-ellipsis">…</span>`);
        parts.push(
            `<button class="page-btn page-num" data-page="${totalPages}">${totalPages}</button>`
        );
    }

    parts.push(
        `<button class="page-btn" data-page="${page + 1}"
                 ${page >= totalPages ? 'disabled' : ''}>Suiv. →</button>`
    );
    parts.push(
        `<button class="page-btn page-edge" data-page="${totalPages}"
                 ${page === totalPages ? 'disabled' : ''} title="Dernière page">»</button>`
    );

    return parts.join('');
}
