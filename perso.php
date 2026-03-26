<?php
// ============================================================
//  perso.php — Espace personnel privé
//  Accessible uniquement après connexion à admin.php.
//  Gère les galeries privées (type tag) et spéciales
//  (illust, bookmark, following).
// ============================================================
require_once __DIR__ . '/config.php';

// ── Session longue durée (7 jours) ──
$session_lifetime = 7 * 24 * 3600;
ini_set('session.gc_maxlifetime', $session_lifetime);
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (!isset($_SESSION['admin_ok'])) {
    header('Location: admin.php');
    exit;
}

define('PRIVATE_DIR', __DIR__ . '/private');

// ── Types de galeries spéciales disponibles ──────────────────
const SPECIAL_TYPES = [
    'illust'    => ['label' => 'Mes illustrations',     'icon' => '✦', 'hint' => 'Vos illustrations publiées sur Pixiv'],
    'bookmark'  => ['label' => 'Mes bookmarks',         'icon' => '♡', 'hint' => 'Vos illustrations mises en favori'],
    'following' => ['label' => 'Artistes suivis',       'icon' => '◈', 'hint' => 'Dernières illustrations de vos abonnements'],
];

// ── Helpers galeries privées ─────────────────────────────────

function private_gallery_file(string $slug): string {
    return PRIVATE_DIR . '/' . $slug . '.json';
}

function load_private_gallery(string $slug): ?array {
    if (!is_valid_gallery_slug($slug)) return null;
    $f = private_gallery_file($slug);
    if (!file_exists($f)) return null;
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function save_private_gallery(string $slug, array $data): bool {
    if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0755, true);
    return file_put_contents(private_gallery_file($slug), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function delete_private_gallery(string $slug): bool {
    $json = private_gallery_file($slug);
    $php  = PRIVATE_DIR . '/' . $slug . '.php';
    $ok   = true;
    if (file_exists($json)) $ok = unlink($json) && $ok;
    if (file_exists($php))  $ok = unlink($php)  && $ok;
    return $ok;
}

function create_private_gallery_php(string $slug, string $type): bool {
    if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0755, true);
    $dest     = PRIVATE_DIR . '/' . $slug . '.php';
    $template = ($type === 'tag')
        ? PRIVATE_DIR . '/_template.php'
        : PRIVATE_DIR . '/_special.php';
    if (!file_exists($template)) {
        return false;
    }
    return copy($template, $dest);
}

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

// ── Traitement POST ──────────────────────────────────────────
global $SETTINGS;
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Créer une galerie privée ---
    if ($action === 'create_private_gallery') {
        $slug  = trim($_POST['gallery_slug']  ?? '');
        $title = trim($_POST['gallery_title'] ?? '');
        $type  = trim($_POST['gallery_type']  ?? 'tag');

        if (!is_valid_gallery_slug($slug)) {
            $error = 'Slug invalide : a-z, 0-9, tirets, 20 caractères max.';
        } elseif ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } elseif (file_exists(private_gallery_file($slug))) {
            $error = "Une galerie avec le slug « {$slug} » existe déjà.";
        } else {
            if ($type === 'tag') {
                $labels     = $_POST['char_label'] ?? [];
                $tags       = $_POST['char_tag']   ?? [];
                $characters = buildPrivateCharacters($labels, $tags);
                if (empty($characters)) {
                    $error = 'Ajoutez au moins un tag.';
                } else {
                    $gdata = ['type' => 'tag', 'title' => $title, 'characters' => $characters];
                    save_private_gallery($slug, $gdata);
                    create_private_gallery_php($slug, 'tag');
                    $success = "Galerie « {$title} » créée.";
                }
            } elseif (array_key_exists($type, SPECIAL_TYPES)) {
                $gdata = ['type' => $type, 'title' => $title];
                save_private_gallery($slug, $gdata);
                create_private_gallery_php($slug, $type);
                $success = "Galerie spéciale « {$title} » créée.";
            } else {
                $error = 'Type de galerie inconnu.';
            }
        }
    }

    // --- Mettre à jour une galerie privée ---
    elseif ($action === 'update_private_gallery') {
        $slug  = trim($_POST['gallery_slug']  ?? '');
        $title = trim($_POST['gallery_title'] ?? '');

        if (!is_valid_gallery_slug($slug)) {
            $error = 'Slug invalide.';
        } elseif ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } else {
            $existing = load_private_gallery($slug);
            if (!$existing) { $error = 'Galerie introuvable.'; }
            else {
                $type = $existing['type'] ?? 'tag';
                if ($type === 'tag') {
                    $labels     = $_POST['char_label'] ?? [];
                    $tags       = $_POST['char_tag']   ?? [];
                    $characters = buildPrivateCharacters($labels, $tags);
                    if (empty($characters)) {
                        $error = 'Conservez au moins un tag.';
                    } else {
                        save_private_gallery($slug, array_merge($existing, ['title' => $title, 'characters' => $characters]));
                        $success = "Galerie « {$title} » mise à jour.";
                    }
                } else {
                    save_private_gallery($slug, array_merge($existing, ['title' => $title]));
                    $success = "Galerie « {$title} » mise à jour.";
                }
            }
        }
    }

    // --- Supprimer une galerie privée ---
    elseif ($action === 'delete_private_gallery') {
        $slug = trim($_POST['gallery_slug'] ?? '');
        if (is_valid_gallery_slug($slug) && delete_private_gallery($slug)) {
            $success = "Galerie « {$slug} » supprimée.";
        } else {
            $error = 'Impossible de supprimer la galerie.';
        }
    }

    // PRG
    $qs = '?';
    if ($success) $qs .= 'msg=' . urlencode($success) . '&mt=success';
    if ($error)   $qs .= 'msg=' . urlencode($error)   . '&mt=error';
    header('Location: perso.php' . $qs);
    exit;
}

if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
    if (($_GET['mt'] ?? '') === 'success') $success = $msg;
    else $error = $msg;
}

function buildPrivateCharacters(array $labels, array $tags): array {
    $out = [];
    for ($i = 0; $i < count($labels); $i++) {
        $label = trim($labels[$i] ?? '');
        $tag   = trim($tags[$i]   ?? '');
        if ($label !== '' && $tag !== '') {
            $out[] = ['label' => $label, 'tag' => $tag];
        }
    }
    return $out;
}

$private_galleries = list_private_galleries();

// Appliquer l'ordre personnalisé
if (!empty($SETTINGS['private_gallery_order']) && is_array($SETTINGS['private_gallery_order'])) {
    $orderMap = array_flip($SETTINGS['private_gallery_order']);
    usort($private_galleries, function($a, $b) use ($orderMap) {
        $ia = $orderMap[$a['slug']] ?? PHP_INT_MAX;
        $ib = $orderMap[$b['slug']] ?? PHP_INT_MAX;
        return $ia <=> $ib;
    });
}

// ── Séparation tag / spéciales ───────────────────────────────
$tag_galleries     = array_values(array_filter($private_galleries, fn($g) => ($g['type'] ?? 'tag') === 'tag'));
$special_galleries = array_values(array_filter($private_galleries, fn($g) => ($g['type'] ?? 'tag') !== 'tag'));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espace perso — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;1,300;1,400&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>

<div class="admin-wrap">

    <!-- Header -->
    <div class="admin-header">
        <div>
            <p class="site-label" style="text-align:left;margin-bottom:.5rem;">Espace personnel</p>
            <h1>Galeries privées</h1>
        </div>
        <div style="display:flex;gap:1.5rem;align-items:center;">
            <a href="index.php">← Accueil</a>
            <a href="admin.php">Administration</a>
            <a href="admin.php?logout=1">Déconnexion</a>
        </div>
    </div>

    <!-- Alertes -->
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= $error ?></div>
    <?php endif; ?>

    <!-- ══ Galeries spéciales ══ -->
    <section class="admin-section">
        <p class="section-title">Galeries Pixiv personnelles</p>
        <p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;margin-bottom:1.4rem;line-height:1.6;">
            Accès direct à vos données Pixiv personnelles. Ces galeries utilisent votre cookie de session et sont entièrement privées.
        </p>

        <?php if (empty($special_galleries)): ?>
        <!-- Grille des types disponibles (aucune spéciale créée) -->
        <div class="special-types-grid">
            <?php foreach (SPECIAL_TYPES as $stype => $info): ?>
            <div class="special-type-card">
                <span class="special-type-icon"><?= $info['icon'] ?></span>
                <span class="special-type-label"><?= htmlspecialchars($info['label']) ?></span>
                <span class="special-type-hint"><?= htmlspecialchars($info['hint']) ?></span>
                <button class="btn-add" style="margin-top:.8rem;width:auto;padding:.4rem 1rem;"
                        onclick="openSpecialCreate('<?= $stype ?>', '<?= htmlspecialchars(addslashes($info['label'])) ?>')">
                    + Créer
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>

        <!-- Liste des galeries spéciales existantes -->
        <div class="gallery-list" style="margin-bottom:1.5rem;">
            <?php foreach ($special_galleries as $g):
                $stype = $g['type'];
                $info  = SPECIAL_TYPES[$stype] ?? ['label' => $stype, 'icon' => '·'];
            ?>
            <div class="gallery-item" id="pgi-<?= htmlspecialchars($g['slug']) ?>" data-slug="<?= htmlspecialchars($g['slug']) ?>">
                <div class="gallery-item-header" onclick="toggleGallery('pgi-<?= htmlspecialchars($g['slug']) ?>')">
                    <div style="display:flex;align-items:center;gap:.7rem;flex:1;min-width:0;">
                        <span class="special-badge"><?= $info['icon'] ?></span>
                        <div class="gallery-item-info">
                            <span class="gallery-item-title"><?= htmlspecialchars($g['title']) ?></span>
                            <span class="gallery-item-meta">
                                <code><?= htmlspecialchars($g['slug']) ?></code>
                                · <?= htmlspecialchars($info['label']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="gallery-item-actions">
                        <a href="private/<?= htmlspecialchars($g['slug']) ?>.php" target="_blank" class="btn-small">Voir</a>
                        <span class="gallery-chevron" id="chev-pgi-<?= htmlspecialchars($g['slug']) ?>">▾</span>
                    </div>
                </div>
                <div class="gallery-item-body" id="body-pgi-<?= htmlspecialchars($g['slug']) ?>" style="display:none">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_private_gallery">
                        <input type="hidden" name="gallery_slug" value="<?= htmlspecialchars($g['slug']) ?>">
                        <div class="field" style="margin-bottom:.8rem;">
                            <label>Titre affiché</label>
                            <input type="text" name="gallery_title"
                                   value="<?= htmlspecialchars($g['title']) ?>" required>
                        </div>
                        <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1rem;">
                            <button type="submit" class="btn-primary" style="margin-top:0;flex:1;min-width:120px;">Enregistrer</button>
                            <button type="button" class="btn-danger btn-delete"
                                    onclick="confirmDelete('<?= htmlspecialchars($g['slug'], ENT_QUOTES) ?>', '<?= htmlspecialchars($g['title'], ENT_QUOTES) ?>')">
                                Supprimer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Boutons pour créer les types manquants -->
        <?php
        $existing_types = array_column($special_galleries, 'type');
        $missing_types  = array_diff(array_keys(SPECIAL_TYPES), $existing_types);
        if (!empty($missing_types)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem;">
            <?php foreach ($missing_types as $mtype):
                $info = SPECIAL_TYPES[$mtype]; ?>
            <button class="btn-add" style="width:auto;padding:.4rem 1rem;"
                    onclick="openSpecialCreate('<?= $mtype ?>', '<?= htmlspecialchars(addslashes($info['label'])) ?>')">
                <?= $info['icon'] ?> + <?= htmlspecialchars($info['label']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- ══ Galeries privées par tags ══ -->
    <section class="admin-section">
        <p class="section-title">Galeries privées par tags</p>
        <p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;margin-bottom:1.4rem;line-height:1.6;">
            Identiques aux galeries publiques, mais uniquement accessibles une fois connecté.
        </p>

        <?php if (!empty($tag_galleries)): ?>
        <div class="gallery-list" style="margin-bottom:1.5rem;">
            <?php foreach ($tag_galleries as $g): ?>
            <div class="gallery-item" id="pgi-<?= htmlspecialchars($g['slug']) ?>" data-slug="<?= htmlspecialchars($g['slug']) ?>">
                <div class="gallery-item-header" onclick="toggleGallery('pgi-<?= htmlspecialchars($g['slug']) ?>')">
                    <div class="gallery-item-info">
                        <span class="gallery-item-title"><?= htmlspecialchars($g['title']) ?></span>
                        <span class="gallery-item-meta">
                            <code><?= htmlspecialchars($g['slug']) ?></code>
                            · <?= count($g['characters'] ?? []) ?> tag<?= count($g['characters'] ?? []) > 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <div class="gallery-item-actions">
                        <a href="private/<?= htmlspecialchars($g['slug']) ?>.php" target="_blank" class="btn-small">Voir</a>
                        <span class="gallery-chevron" id="chev-pgi-<?= htmlspecialchars($g['slug']) ?>">▾</span>
                    </div>
                </div>
                <div class="gallery-item-body" id="body-pgi-<?= htmlspecialchars($g['slug']) ?>" style="display:none">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_private_gallery">
                        <input type="hidden" name="gallery_slug" value="<?= htmlspecialchars($g['slug']) ?>">

                        <div class="field" style="margin-bottom:.8rem;">
                            <label>Titre de la galerie</label>
                            <input type="text" name="gallery_title"
                                   value="<?= htmlspecialchars($g['title']) ?>" required>
                        </div>

                        <div class="char-row-header">
                            <span></span><span>Label affiché</span><span>Tag Pixiv</span><span></span>
                        </div>
                        <div class="char-list" id="pcl-<?= htmlspecialchars($g['slug']) ?>">
                            <?php foreach ($g['characters'] ?? [] as $char): ?>
                            <div class="char-row">
                                <span class="drag-handle" draggable="true">⠿</span>
                                <input type="text" name="char_label[]"
                                       value="<?= htmlspecialchars($char['label']) ?>" required>
                                <input type="text" name="char_tag[]"
                                       value="<?= htmlspecialchars($char['tag']) ?>" required>
                                <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add"
                                onclick="addRow('pcl-<?= htmlspecialchars($g['slug']) ?>')">+ Ajouter un tag</button>

                        <div style="display:flex;gap:.8rem;flex-wrap:wrap;margin-top:1.2rem;">
                            <button type="submit" class="btn-primary" style="margin-top:0;flex:1;min-width:120px;">Enregistrer</button>
                            <button type="button" class="btn-danger btn-delete"
                                    onclick="confirmDelete('<?= htmlspecialchars($g['slug'], ENT_QUOTES) ?>', '<?= htmlspecialchars($g['title'], ENT_QUOTES) ?>')">
                                Supprimer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Formulaire de création par tag -->
        <details class="create-details" <?= empty($tag_galleries) ? 'open' : '' ?>>
            <summary class="create-summary">+ Nouvelle galerie privée par tags</summary>
            <form method="POST" style="margin-top:1rem;">
                <input type="hidden" name="action" value="create_private_gallery">
                <input type="hidden" name="gallery_type" value="tag">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem;">
                    <div class="field" style="margin-bottom:0;">
                        <label>Slug (nom du fichier)</label>
                        <input type="text" name="gallery_slug"
                               placeholder="ex: perso-fav" maxlength="20"
                               pattern="[a-z0-9\-]{1,20}" required>
                        <span class="hint">a-z, 0-9, tirets · 20 car. max</span>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Titre de la galerie</label>
                        <input type="text" name="gallery_title"
                               placeholder="ex: Mes persos préférés" required>
                    </div>
                </div>

                <div class="char-row-header">
                    <span></span><span>Label affiché</span><span>Tag Pixiv</span><span></span>
                </div>
                <div class="char-list" id="newPrivateCharList">
                    <div class="char-row">
                        <span class="drag-handle" draggable="true">⠿</span>
                        <input type="text" name="char_label[]" placeholder="Nom affiché" required>
                        <input type="text" name="char_tag[]"   placeholder="Tag Pixiv" required>
                        <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                    </div>
                </div>
                <button type="button" class="btn-add" onclick="addRow('newPrivateCharList')">+ Ajouter un tag</button>
                <button type="submit" class="btn-primary">Créer la galerie</button>
            </form>
        </details>
    </section>

    <!-- Modale création galerie spéciale -->
    <div id="specialModal" class="cmodal-backdrop" style="display:none">
        <div class="cmodal-box">
            <p class="cmodal-msg" id="specialModalTitle">Nouvelle galerie spéciale</p>
            <form method="POST" id="specialCreateForm">
                <input type="hidden" name="action" value="create_private_gallery">
                <input type="hidden" name="gallery_type" id="specialType">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:1.2rem;">
                    <div class="field" style="margin-bottom:0;">
                        <label>Slug</label>
                        <input type="text" name="gallery_slug" id="specialSlug"
                               placeholder="ex: bookmarks" maxlength="20"
                               pattern="[a-z0-9\-]{1,20}" required>
                        <span class="hint">a-z, 0-9, tirets · 20 car.</span>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>Titre affiché</label>
                        <input type="text" name="gallery_title" id="specialTitle"
                               placeholder="ex: Mes bookmarks" required>
                    </div>
                </div>
                <div class="cmodal-actions">
                    <button type="button" class="cmodal-btn cmodal-btn-cancel" onclick="closeSpecialModal()">Annuler</button>
                    <button type="submit" class="cmodal-btn" style="background:rgba(200,169,126,.1);border-color:var(--accent);color:var(--accent);">Créer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Formulaire de suppression (invisible) -->
    <form method="POST" id="deleteForm" style="display:none">
        <input type="hidden" name="action" value="delete_private_gallery">
        <input type="hidden" name="gallery_slug" id="deleteSlug">
    </form>

</div><!-- /.admin-wrap -->

<script>
// ── Modale custom (identique à admin.php) ──
(function () {
    window._modal = function (msg, { confirm = false } = {}) {
        return new Promise(resolve => {
            const backdrop = document.getElementById('customModal');
            if (!backdrop) { resolve(confirm ? window.confirm(msg) : true); return; }
            const msgEl   = document.getElementById('cmodalMsg');
            const actions = document.getElementById('cmodalActions');
            msgEl.innerHTML = msg.replace(/\n/g, '<br>');
            actions.innerHTML = '';
            if (confirm) {
                const btnOk = document.createElement('button');
                btnOk.className = 'cmodal-btn cmodal-btn-danger';
                btnOk.textContent = 'Supprimer';
                btnOk.onclick = () => { close(); resolve(true); };
                const btnCancel = document.createElement('button');
                btnCancel.className = 'cmodal-btn cmodal-btn-cancel';
                btnCancel.textContent = 'Annuler';
                btnCancel.onclick = () => { close(); resolve(false); };
                actions.append(btnCancel, btnOk);
            } else {
                const btnOk = document.createElement('button');
                btnOk.className = 'cmodal-btn cmodal-btn-cancel';
                btnOk.textContent = 'OK';
                btnOk.onclick = () => { close(); resolve(true); };
                actions.append(btnOk);
            }
            backdrop.style.display = 'flex';
            requestAnimationFrame(() => backdrop.classList.add('visible'));
            backdrop.onclick = e => { if (e.target === backdrop) { close(); resolve(false); } };
            function close() {
                backdrop.classList.remove('visible');
                setTimeout(() => { backdrop.style.display = 'none'; }, 220);
            }
        });
    };

    // Injection DOM modale custom
    const tpl = document.createElement('div');
    tpl.innerHTML = `
    <div id="customModal" class="cmodal-backdrop" style="display:none" aria-modal="true" role="dialog">
        <div class="cmodal-box">
            <p class="cmodal-msg" id="cmodalMsg"></p>
            <div class="cmodal-actions" id="cmodalActions"></div>
        </div>
    </div>`;
    document.body.appendChild(tpl.firstElementChild);
})();

// ── Dépliage des galeries ──
function toggleGallery(id) {
    const body = document.getElementById('body-' + id);
    const chev = document.getElementById('chev-' + id);
    if (!body) return;
    const open = body.style.display === 'block';
    body.style.display = open ? 'none' : 'block';
    if (chev) chev.textContent = open ? '▾' : '▴';
}

// ── Modale création galerie spéciale ──
function openSpecialCreate(type, defaultTitle) {
    document.getElementById('specialType').value  = type;
    document.getElementById('specialTitle').value = defaultTitle;
    document.getElementById('specialSlug').value  = '';
    document.getElementById('specialModalTitle').textContent = 'Nouvelle galerie · ' + defaultTitle;
    const m = document.getElementById('specialModal');
    m.style.display = 'flex';
    requestAnimationFrame(() => m.classList.add('visible'));
    document.getElementById('specialSlug').focus();
}
function closeSpecialModal() {
    const m = document.getElementById('specialModal');
    m.classList.remove('visible');
    setTimeout(() => { m.style.display = 'none'; }, 220);
}
document.getElementById('specialModal').addEventListener('click', e => {
    if (e.target === document.getElementById('specialModal')) closeSpecialModal();
});

// ── Gestion des lignes de personnages ──
function addRow(listId) {
    const list = document.getElementById(listId);
    const row  = document.createElement('div');
    row.className = 'char-row';
    row.innerHTML = `
        <span class="drag-handle" draggable="true">⠿</span>
        <input type="text" name="char_label[]" placeholder="Nom affiché" required>
        <input type="text" name="char_tag[]"   placeholder="Tag Pixiv" required>
        <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
    `;
    list.appendChild(row);
    initDragHandle(row.querySelector('.drag-handle'));
    row.querySelector('input').focus();
}
function removeRow(btn) {
    const list = btn.closest('.char-list');
    if (list && list.querySelectorAll('.char-row').length <= 1) {
        alert('Conservez au moins un tag.');
        return;
    }
    btn.closest('.char-row').remove();
}

// ── Drag & Drop tags ──
let dragSrcRow = null;
function initDragHandle(handle) {
    const row = handle.closest('.char-row');
    handle.addEventListener('dragstart', e => {
        dragSrcRow = row;
        row.classList.add('drag-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
    });
    handle.addEventListener('dragend', () => {
        document.querySelectorAll('.char-row').forEach(r => r.classList.remove('drag-dragging','drag-over'));
        dragSrcRow = null;
    });
}
document.addEventListener('dragover', e => {
    const t = e.target.closest('.char-row');
    if (!t || !dragSrcRow || t === dragSrcRow) return;
    e.preventDefault();
    dragSrcRow.closest('.char-list')?.querySelectorAll('.char-row').forEach(r => r.classList.remove('drag-over'));
    t.classList.add('drag-over');
});
document.addEventListener('dragleave', e => {
    const t = e.target.closest('.char-row');
    if (t) t.classList.remove('drag-over');
});
document.addEventListener('drop', e => {
    const t = e.target.closest('.char-row');
    if (!t || !dragSrcRow || t === dragSrcRow) return;
    if (t.closest('.char-list') !== dragSrcRow.closest('.char-list')) return;
    e.preventDefault();
    const list = t.closest('.char-list');
    const rows = [...list.querySelectorAll('.char-row')];
    list.insertBefore(dragSrcRow, rows.indexOf(dragSrcRow) < rows.indexOf(t) ? t.nextSibling : t);
    t.classList.remove('drag-over');
});
document.querySelectorAll('.char-row .drag-handle').forEach(initDragHandle);

// ── Confirmation suppression ──
async function confirmDelete(slug, title) {
    const ok = await _modal(
        `Supprimer la galerie « <em>${title}</em> » (${slug}) ?<br><br>Cette action est irréversible.`,
        { confirm: true }
    );
    if (!ok) return;
    document.getElementById('deleteSlug').value = slug;
    document.getElementById('deleteForm').submit();
}
</script>

</body>
</html>
