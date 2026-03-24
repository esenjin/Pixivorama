<?php
// ============================================================
//  admin.php — Interface d'administration (multi-galeries)
// ============================================================
require_once __DIR__ . '/config.php';

session_start();

$error   = '';
$success = '';

// ── Déconnexion ──
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ── Authentification ──
if (!isset($_SESSION['admin_ok'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        global $SETTINGS;
        if (password_verify($_POST['password'], $SETTINGS['admin_hash'])) {
            $_SESSION['admin_ok'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $error = 'Mot de passe incorrect.';
        }
    }
    loginPage($error);
    exit;
}

global $SETTINGS;

// ── Onglet actif ──
$tab = $_GET['tab'] ?? 'session';
if (!in_array($tab, ['session', 'galleries', 'options'])) $tab = 'session';

// ── Traitement POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Session Pixiv ---
    if ($action === 'update_sessid') {
        $sessid = trim($_POST['phpsessid'] ?? '');
        if ($sessid === '') {
            $error = 'Le PHPSESSID ne peut pas être vide.';
        } else {
            $SETTINGS['phpsessid'] = $sessid;
            save_settings($SETTINGS);
            $success = 'PHPSESSID mis à jour.';
        }
        $tab = 'session';
    }

    // --- Créer une galerie ---
    elseif ($action === 'create_gallery') {
        $slug  = trim($_POST['gallery_slug']  ?? '');
        $title = trim($_POST['gallery_title'] ?? '');
        $tab   = 'galleries';

        if (!is_valid_gallery_slug($slug)) {
            $error = 'Slug invalide : uniquement a-z, 0-9 et tirets, 12 caractères max.';
        } elseif ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } elseif (file_exists(gallery_file($slug))) {
            $error = "Une galerie avec le slug « {$slug} » existe déjà.";
        } else {
            $labels = $_POST['char_label'] ?? [];
            $tags   = $_POST['char_tag']   ?? [];
            $characters = buildCharacters($labels, $tags);
            if (empty($characters)) {
                $error = 'Ajoutez au moins un personnage/tag.';
            } else {
                save_gallery($slug, ['title' => $title, 'characters' => $characters]);
                create_gallery_php($slug);
                $success = "Galerie « {$title} » créée.";
            }
        }
    }

    // --- Mettre à jour une galerie ---
    elseif ($action === 'update_gallery') {
        $slug  = trim($_POST['gallery_slug']  ?? '');
        $title = trim($_POST['gallery_title'] ?? '');
        $tab   = 'galleries';

        if (!is_valid_gallery_slug($slug)) {
            $error = 'Slug invalide.';
        } elseif ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } else {
            $labels = $_POST['char_label'] ?? [];
            $tags   = $_POST['char_tag']   ?? [];
            $characters = buildCharacters($labels, $tags);
            if (empty($characters)) {
                $error = 'Conservez au moins un personnage/tag.';
            } else {
                save_gallery($slug, ['title' => $title, 'characters' => $characters]);
                $success = "Galerie « {$title} » mise à jour.";
            }
        }
    }

    // --- Supprimer une galerie ---
    elseif ($action === 'delete_gallery') {
        $slug = trim($_POST['gallery_slug'] ?? '');
        $tab  = 'galleries';
        if (is_valid_gallery_slug($slug) && delete_gallery($slug)) {
            $success = "Galerie « {$slug} » supprimée.";
        } else {
            $error = "Impossible de supprimer la galerie.";
        }
    }

    // --- Changer le mot de passe ---
    elseif ($action === 'change_password') {
        $tab     = 'options';
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $SETTINGS['admin_hash'])) {
            $error = 'Mot de passe actuel incorrect.';
        } elseif (strlen($new) < 6) {
            $error = 'Le nouveau mot de passe doit faire au moins 6 caractères.';
        } elseif ($new !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $SETTINGS['admin_hash'] = password_hash($new, PASSWORD_DEFAULT);
            save_settings($SETTINGS);
            $success = 'Mot de passe mis à jour.';
        }
    }

    // --- Mettre à jour la page d'accueil ---
    elseif ($action === 'update_home') {
        $tab   = 'options';
        $title = trim($_POST['home_title']       ?? '');
        $desc  = trim($_POST['home_description'] ?? '');
        if ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } else {
            $SETTINGS['home_title']       = $title;
            $SETTINGS['home_description'] = $desc;
            save_settings($SETTINGS);
            $success = 'Page d\'accueil mise à jour.';
        }
    }

    // Redirect PRG
    $qs = '?tab=' . $tab;
    if ($success) $qs .= '&msg=' . urlencode($success) . '&mt=success';
    if ($error)   $qs .= '&msg=' . urlencode($error)   . '&mt=error';
    header('Location: admin.php' . $qs);
    exit;
}

// Récupération des messages après redirect
if (!$success && !$error && isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
    if (($_GET['mt'] ?? '') === 'success') $success = $msg;
    else $error = $msg;
}

// ── Helpers ──
function buildCharacters(array $labels, array $tags): array {
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

// ── Affichage ──
$galleries = list_galleries();
adminPage($SETTINGS, $galleries, $tab, $error, $success);

// ════════════════════════════════════════════════════════════
//  TEMPLATES
// ════════════════════════════════════════════════════════════

function loginPage(string $error): void { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — Connexion</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <p class="site-label">Administration</p>
        <h2>Connexion</h2>
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div style="display:flex;flex-direction:column;gap:1.2rem;">
                <div class="field">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" autofocus required>
                </div>
                <button type="submit" class="btn-primary">Accéder</button>
            </div>
        </form>
        <p style="text-align:center;font-size:.62rem;color:var(--text-muted);letter-spacing:.1em;">
            <a href="index.php" style="color:var(--text-muted);text-decoration:none;">← Retour à l'accueil</a>
        </p>
    </div>
</div>
</body>
</html>
<?php }

function adminPage(array $settings, array $galleries, string $tab, string $error, string $success): void { ?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>

<div class="admin-wrap">

    <!-- Header -->
    <div class="admin-header">
        <div>
            <p class="site-label" style="text-align:left;margin-bottom:.5rem;">Administration</p>
            <h1>Pixivorama</h1>
        </div>
        <div style="display:flex;gap:1.5rem;align-items:center;">
            <a href="index.php">← Accueil</a>
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

    <!-- Onglets -->
    <nav class="admin-tabs">
        <a href="?tab=session"   class="admin-tab <?= $tab === 'session'   ? 'active' : '' ?>">Session Pixiv</a>
        <a href="?tab=galleries" class="admin-tab <?= $tab === 'galleries' ? 'active' : '' ?>">
            Galeries
            <?php if (count($galleries)): ?>
                <span class="tab-badge"><?= count($galleries) ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=options"  class="admin-tab <?= $tab === 'options'  ? 'active' : '' ?>">Options</a>
    </nav>

    <!-- ══ Onglet Session ══ -->
    <?php if ($tab === 'session'): ?>
    <section class="admin-section">
        <p class="section-title">Cookie de session Pixiv</p>

        <div class="cookie-status" id="cookieStatus">
            <span class="cookie-status-dot" id="cookieStatusDot"></span>
            <span class="cookie-status-text" id="cookieStatusText">Vérification en cours…</span>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_sessid">
            <div class="field">
                <label for="phpsessid">PHPSESSID</label>
                <input type="text" id="phpsessid" name="phpsessid"
                       value="<?= htmlspecialchars($settings['phpsessid']) ?>"
                       placeholder="Votre PHPSESSID Pixiv" required>
                <span class="hint">Connectez-vous sur pixiv.net → F12 → Application → Cookies → pixiv.net → PHPSESSID</span>
            </div>
            <button type="submit" class="btn-primary">Mettre à jour</button>
        </form>
    </section>
    <?php endif; ?>

    <!-- ══ Onglet Galeries ══ -->
    <?php if ($tab === 'galleries'): ?>
    <section class="admin-section">
        <p class="section-title">Galeries existantes</p>

        <?php if (empty($galleries)): ?>
            <p style="color:var(--text-muted);font-size:.75rem;text-align:center;padding:2rem 0;">
                Aucune galerie pour l'instant. Créez-en une ci-dessous.
            </p>
        <?php else: ?>
            <div class="gallery-list" id="galleryList">
                <?php foreach ($galleries as $g): ?>
                <div class="gallery-item" id="gi-<?= htmlspecialchars($g['slug']) ?>">
                    <div class="gallery-item-header" onclick="toggleGallery('<?= htmlspecialchars($g['slug']) ?>')">
                        <div class="gallery-item-info">
                            <span class="gallery-item-title"><?= htmlspecialchars($g['title']) ?></span>
                            <span class="gallery-item-meta">
                                <code><?= htmlspecialchars($g['slug']) ?>.json</code>
                                · <?= count($g['characters']) ?> tag<?= count($g['characters']) > 1 ? 's' : '' ?>
                            </span>
                        </div>
                        <div class="gallery-item-actions">
                            <a href="galleries/<?= htmlspecialchars($g['slug']) ?>.php" target="_blank" class="btn-small">Voir</a>
                            <span class="gallery-chevron" id="chev-<?= htmlspecialchars($g['slug']) ?>">▾</span>
                        </div>
                    </div>

                    <!-- Formulaire d'édition (replié par défaut) -->
                    <div class="gallery-item-body" id="body-<?= htmlspecialchars($g['slug']) ?>" style="display:none">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_gallery">
                            <input type="hidden" name="gallery_slug" value="<?= htmlspecialchars($g['slug']) ?>">

                            <div class="field" style="margin-bottom:.8rem;">
                                <label>Titre de la galerie</label>
                                <input type="text" name="gallery_title"
                                       value="<?= htmlspecialchars($g['title']) ?>" required>
                            </div>

                            <div class="char-row-header">
                                <span>Label affiché</span>
                                <span>Tag Pixiv</span>
                                <span></span>
                            </div>
                            <div class="char-list" id="cl-<?= htmlspecialchars($g['slug']) ?>">
                                <?php foreach ($g['characters'] as $char): ?>
                                <div class="char-row">
                                    <input type="text" name="char_label[]"
                                           value="<?= htmlspecialchars($char['label']) ?>"
                                           placeholder="Nom affiché" required>
                                    <input type="text" name="char_tag[]"
                                           value="<?= htmlspecialchars($char['tag']) ?>"
                                           placeholder="Tag Pixiv" required>
                                    <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn-add"
                                    onclick="addRow('cl-<?= htmlspecialchars($g['slug']) ?>')">+ Ajouter un tag</button>

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
    </section>

    <!-- Formulaire de création -->
    <section class="admin-section">
        <p class="section-title">Créer une nouvelle galerie</p>

        <form method="POST" id="createForm">
            <input type="hidden" name="action" value="create_gallery">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem;">
                <div class="field" style="margin-bottom:0;">
                    <label for="new_slug">Slug (nom du fichier)</label>
                    <input type="text" id="new_slug" name="gallery_slug"
                           placeholder="ex: monogatari" maxlength="12"
                           pattern="[a-z0-9\-]{1,12}"
                           title="a-z, 0-9 et tirets uniquement, 12 caractères max"
                           required>
                    <span class="hint">a-z, 0-9, tirets · 12 car. max · sera galleries/<em id="slugPreview">slug</em>.php</span>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label for="new_title">Titre de la galerie</label>
                    <input type="text" id="new_title" name="gallery_title"
                           placeholder="ex: Monogatari Series" required>
                </div>
            </div>

            <div class="char-row-header">
                <span>Label affiché</span>
                <span>Tag Pixiv</span>
                <span></span>
            </div>
            <div class="char-list" id="newCharList">
                <div class="char-row">
                    <input type="text" name="char_label[]" placeholder="Nom affiché" required>
                    <input type="text" name="char_tag[]"   placeholder="Tag Pixiv" required>
                    <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                </div>
            </div>
            <button type="button" class="btn-add" onclick="addRow('newCharList')">+ Ajouter un tag</button>
            <button type="submit" class="btn-primary">Créer la galerie</button>
        </form>
    </section>

    <!-- Formulaire de suppression (invisible, soumis par JS) -->
    <form method="POST" id="deleteForm" style="display:none">
        <input type="hidden" name="action" value="delete_gallery">
        <input type="hidden" name="gallery_slug" id="deleteSlug">
    </form>
    <?php endif; ?>

    <!-- ══ Onglet Options ══ -->
    <?php if ($tab === 'options'): ?>

    <!-- Page d'accueil -->
    <section class="admin-section">
        <p class="section-title">Page d'accueil</p>
        <form method="POST">
            <input type="hidden" name="action" value="update_home">
            <div class="field">
                <label for="home_title">Titre</label>
                <input type="text" id="home_title" name="home_title"
                       value="<?= htmlspecialchars($settings['home_title'] ?? 'Galeries') ?>"
                       placeholder="Galeries" required>
                <span class="hint">Affiché dans le &lt;h1&gt; et l'onglet du navigateur.</span>
            </div>
            <div class="field">
                <label for="home_description">Sous-titre / description</label>
                <input type="text" id="home_description" name="home_description"
                       value="<?= htmlspecialchars($settings['home_description'] ?? 'Illustrations Pixiv par personnage') ?>"
                       placeholder="Illustrations Pixiv par personnage">
                <span class="hint">Ligne de texte sous le titre. Laisser vide pour masquer.</span>
            </div>
            <button type="submit" class="btn-primary">Mettre à jour</button>
        </form>
    </section>

    <!-- Mot de passe -->
    <section class="admin-section">
        <p class="section-title">Mot de passe administrateur</p>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="new_password">Nouveau mot de passe</label>
                <input type="password" id="new_password" name="new_password" minlength="6" required>
                <span class="hint">6 caractères minimum.</span>
            </div>
            <div class="field">
                <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn-primary">Changer le mot de passe</button>
        </form>
    </section>
    <?php endif; ?>

</div><!-- /.admin-wrap -->

<script>
// ── Vérification cookie (onglet session uniquement) ──
<?php if ($tab === 'session'): ?>
(async function checkCookie() {
    const dot  = document.getElementById('cookieStatusDot');
    const text = document.getElementById('cookieStatusText');
    try {
        const res  = await fetch('pixiv-check.php');
        const data = await res.json();
        if (data.valid) {
            dot.className    = 'cookie-status-dot valid';
            text.textContent = data.username
                ? `Cookie valide — connecté en tant que ${data.username}`
                : 'Cookie valide — session active';
        } else {
            dot.className    = 'cookie-status-dot invalid';
            text.textContent = `Cookie invalide — ${data.reason}`;
        }
    } catch {
        dot.className    = 'cookie-status-dot invalid';
        text.textContent = 'Impossible de joindre Pixiv pour vérifier le cookie.';
    }
})();
<?php endif; ?>

// ── Dépliage des galeries ──
function toggleGallery(slug) {
    const body  = document.getElementById('body-' + slug);
    const chev  = document.getElementById('chev-' + slug);
    const open  = body.style.display === 'block';
    body.style.display = open ? 'none' : 'block';
    chev.textContent   = open ? '▾' : '▴';
}

// ── Aperçu slug ──
const slugInput = document.getElementById('new_slug');
if (slugInput) {
    slugInput.addEventListener('input', function () {
        const preview = document.getElementById('slugPreview');
        if (preview) preview.textContent = this.value || 'slug';
    });
    // Sanitize à la volée
    slugInput.addEventListener('keypress', function (e) {
        const allowed = /[a-z0-9\-]/;
        if (!allowed.test(e.key)) e.preventDefault();
    });
}

// ── Gestion des lignes de personnages ──
function addRow(listId) {
    const list = document.getElementById(listId);
    const row  = document.createElement('div');
    row.className = 'char-row';
    row.innerHTML = `
        <input type="text" name="char_label[]" placeholder="Nom affiché" required>
        <input type="text" name="char_tag[]"   placeholder="Tag Pixiv" required>
        <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
    `;
    list.appendChild(row);
    row.querySelector('input').focus();
}

function removeRow(btn) {
    const list = btn.closest('.char-list');
    const rows = list ? list.querySelectorAll('.char-row') : [];
    if (rows.length <= 1) {
        alert('Conservez au moins un tag.');
        return;
    }
    btn.closest('.char-row').remove();
}

// ── Confirmation de suppression ──
function confirmDelete(slug, title) {
    if (!confirm(`Supprimer la galerie « ${title} » (${slug}) ?\n\nCette action est irréversible.`)) return;
    document.getElementById('deleteSlug').value = slug;
    document.getElementById('deleteForm').submit();
}
</script>

</body>
</html>
<?php }
