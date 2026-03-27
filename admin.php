<?php
// ============================================================
//  admin.php — Interface d'administration (multi-galeries)
// ============================================================
require_once __DIR__ . '/config.php';

session_start();
if (!isset($_SESSION['admin_ok']) && remember_check()) {
    $_SESSION['admin_ok'] = true;
}

$error   = '';
$success = '';

// ── Déconnexion ──
if (isset($_GET['logout'])) {
    remember_clear();
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
            remember_set();
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
if (!in_array($tab, ['session', 'galleries', 'options', 'maintenance'])) $tab = 'session';

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
            $error = 'Slug invalide : uniquement a-z, 0-9 et tirets, 20 caractères max.';
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
                $footer_label = trim($_POST['footer_link_label'] ?? '');
                $footer_url   = trim($_POST['footer_link_url']   ?? '');
                $gdata = ['title' => $title, 'characters' => $characters];
                if ($footer_label !== '' && $footer_url !== '') {
                    $gdata['footer_link_label'] = $footer_label;
                    $gdata['footer_link_url']   = $footer_url;
                }
                save_gallery($slug, $gdata);
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
                $footer_label = trim($_POST['footer_link_label'] ?? '');
                $footer_url   = trim($_POST['footer_link_url']   ?? '');
                $gdata = ['title' => $title, 'characters' => $characters];
                if ($footer_label !== '' && $footer_url !== '') {
                    $gdata['footer_link_label'] = $footer_label;
                    $gdata['footer_link_url']   = $footer_url;
                }
                save_gallery($slug, $gdata);
                $success = "Galerie « {$title} » mise à jour.";
            }
        }
    }

    // --- Réorganiser les galeries ---
    elseif ($action === 'reorder_galleries') {
        $tab   = 'galleries';
        $order = json_decode($_POST['gallery_order'] ?? '[]', true);
        if (is_array($order)) {
            // Rewrite each gallery JSON with a new 'order' key — we store order as a separate index file
            $validSlugs = array_filter($order, 'is_valid_gallery_slug');
            // Save order to settings
            $SETTINGS['gallery_order'] = array_values($validSlugs);
            save_settings($SETTINGS);
            $success = 'Ordre des galeries mis à jour.';
        } else {
            $error = 'Données de réorganisation invalides.';
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
            $SETTINGS['home_title']            = $title;
            $SETTINGS['home_description']      = $desc;
            $SETTINGS['home_footer_link_label'] = trim($_POST['home_footer_link_label'] ?? '');
            $SETTINGS['home_footer_link_url']   = trim($_POST['home_footer_link_url']   ?? '');
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

// Appliquer l'ordre personnalisé si défini
if (!empty($SETTINGS['gallery_order']) && is_array($SETTINGS['gallery_order'])) {
    $orderMap = array_flip($SETTINGS['gallery_order']);
    usort($galleries, function($a, $b) use ($orderMap) {
        $ia = $orderMap[$a['slug']] ?? PHP_INT_MAX;
        $ib = $orderMap[$b['slug']] ?? PHP_INT_MAX;
        return $ia <=> $ib;
    });
}
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
            <a href="perso.php">Espace perso</a>
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
        <a href="?tab=maintenance" class="admin-tab <?= $tab === 'maintenance' ? 'active' : '' ?>">Maintenance</a>
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
                <div class="gallery-item" id="gi-<?= htmlspecialchars($g['slug']) ?>" data-slug="<?= htmlspecialchars($g['slug']) ?>">
                    <div class="gallery-item-header" onclick="toggleGallery('<?= htmlspecialchars($g['slug']) ?>')">
                        <span class="gallery-drag-handle" draggable="true" title="Glisser pour réordonner" onclick="event.stopPropagation()">⠿</span>
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
                                <span></span>
                                <span>Label affiché</span>
                                <span>Tag Pixiv</span>
                                <span></span>
                            </div>
                            <div class="char-list" id="cl-<?= htmlspecialchars($g['slug']) ?>">
                                <?php foreach ($g['characters'] as $char): ?>
                                <div class="char-row">
                                    <span class="drag-handle" draggable="true" title="Glisser pour réordonner">⠿</span>
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
                            <span class="hint">Consultez <a href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama/wiki/Bien-choisir-ses-tags-Pixiv" target="_blank" rel="noopener" style="color:var(--text-muted);">le guide</a> sur l'utilisation des tags Pixiv.</span>
                            <button type="button" class="btn-add"
                                    onclick="addRow('cl-<?= htmlspecialchars($g['slug']) ?>')">+ Ajouter un tag</button>

                            <!-- Lien personnalisé footer -->
                            <div class="footer-link-fields">
                                <p class="footer-link-label-section">Lien personnalisé dans le pied de page</p>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
                                    <div class="field" style="margin-bottom:0;">
                                        <label>Label du lien</label>
                                        <input type="text" name="footer_link_label"
                                               value="<?= htmlspecialchars($g['footer_link_label'] ?? '') ?>"
                                               placeholder="ex : Lire l'article">
                                    </div>
                                    <div class="field" style="margin-bottom:0;">
                                        <label>URL</label>
                                        <input type="url" name="footer_link_url"
                                               value="<?= htmlspecialchars($g['footer_link_url'] ?? '') ?>"
                                               placeholder="https://…">
                                    </div>
                                </div>
                                <span class="hint">Laissez les deux champs vides pour masquer le lien.</span>
                            </div>

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
                           placeholder="ex: monogatari" maxlength="20"
                           pattern="[a-z0-9\-]{1,20}"
                           title="a-z, 0-9 et tirets uniquement, 20 caractères max"
                           required>
                    <span class="hint">a-z, 0-9, tirets · 20 car. max · sera galleries/<em id="slugPreview">slug</em>.php</span>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label for="new_title">Titre de la galerie</label>
                    <input type="text" id="new_title" name="gallery_title"
                           placeholder="ex: Monogatari Series" required>
                </div>
            </div>

            <div class="char-row-header">
                <span></span>
                <span>Label affiché</span>
                <span>Tag Pixiv</span>
                <span></span>
            </div>
            <div class="char-list" id="newCharList">
                <div class="char-row">
                    <span class="drag-handle" draggable="true" title="Glisser pour réordonner">⠿</span>
                    <input type="text" name="char_label[]" placeholder="Nom affiché" required>
                    <input type="text" name="char_tag[]"   placeholder="Tag Pixiv" required>
                    <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                </div>
            </div>
            <span class="hint">Consultez <a href="https://git.crystalyx.net/Esenjin_Asakha/Pixivorama/wiki/Bien-choisir-ses-tags-Pixiv" target="_blank" rel="noopener" style="color:var(--text-muted);">le guide</a> sur l'utilisation des tags Pixiv.</span>
            <button type="button" class="btn-add" onclick="addRow('newCharList')">+ Ajouter un tag</button>

            <!-- Lien personnalisé footer -->
            <div class="footer-link-fields">
                <p class="footer-link-label-section">Lien personnalisé dans le pied de page</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
                    <div class="field" style="margin-bottom:0;">
                        <label>Label du lien</label>
                        <input type="text" name="footer_link_label" placeholder="ex : Lire l'article">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>URL</label>
                        <input type="url" name="footer_link_url" placeholder="https://…">
                    </div>
                </div>
                <span class="hint">Laissez les deux champs vides pour masquer le lien.</span>
            </div>

            <button type="submit" class="btn-primary">Créer la galerie</button>
        </form>
    </section>

    <!-- Formulaire de suppression (invisible, soumis par JS) -->
    <form method="POST" id="deleteForm" style="display:none">
        <input type="hidden" name="action" value="delete_gallery">
        <input type="hidden" name="gallery_slug" id="deleteSlug">
    </form>

    <!-- Formulaire de réorganisation (invisible, soumis par JS) -->
    <form method="POST" id="reorderForm" style="display:none">
        <input type="hidden" name="action" value="reorder_galleries">
        <input type="hidden" name="gallery_order" id="galleryOrder">
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

            <!-- Lien footer accueil -->
            <div class="footer-link-fields">
                <p class="footer-link-label-section">Lien personnalisé dans le pied de page</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;">
                    <div class="field" style="margin-bottom:0;">
                        <label for="home_footer_link_label">Label du lien</label>
                        <input type="text" id="home_footer_link_label" name="home_footer_link_label"
                               value="<?= htmlspecialchars($settings['home_footer_link_label'] ?? '') ?>"
                               placeholder="ex : Mon blog">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label for="home_footer_link_url">URL</label>
                        <input type="url" id="home_footer_link_url" name="home_footer_link_url"
                               value="<?= htmlspecialchars($settings['home_footer_link_url'] ?? '') ?>"
                               placeholder="https://…">
                    </div>
                </div>
                <span class="hint">Laissez les deux champs vides pour masquer le lien.</span>
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

    <!-- ══ Onglet Maintenance ══ -->
    <?php if ($tab === 'maintenance'): ?>

    <section class="admin-section">
        <p class="section-title">Régénération des galeries</p>
        <p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;margin-bottom:1.4rem;line-height:1.6;">
            Recrée les fichiers <code style="font-size:.65rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .4rem;border-radius:2px;">.php</code>
            de toutes les galeries à partir de leurs templates respectifs
            (<code style="font-size:.65rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .4rem;border-radius:2px;">_template.php</code> /
            <code style="font-size:.65rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .4rem;border-radius:2px;">_special.php</code>).
            Utile après une mise à jour qui modifie le comportement des galeries.
        </p>

        <!-- Zone de preview -->
        <div id="regenPreview" style="margin-bottom:1.2rem;display:none;">
            <div class="char-row-header" style="grid-template-columns:1fr auto auto;margin-bottom:.4rem;">
                <span>Galerie</span>
                <span>Type</span>
                <span>Template</span>
            </div>
            <div id="regenList" style="display:flex;flex-direction:column;gap:.3rem;"></div>
        </div>

        <!-- Barre de progression -->
        <div id="regenProgressWrap" style="display:none;margin-bottom:1.2rem;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.4rem;">
                <span style="font-size:.65rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text-muted);" id="regenStatusLabel">En cours…</span>
                <span style="font-size:.65rem;color:var(--accent);" id="regenPercent">0 %</span>
            </div>
            <div style="background:var(--border);border-radius:2px;height:3px;overflow:hidden;">
                <div id="regenBar" style="height:100%;background:var(--accent);width:0;transition:width .3s ease;"></div>
            </div>
        </div>

        <!-- Résultats ligne par ligne -->
        <div id="regenResults" style="display:none;max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:1.2rem;">
            <div id="regenResultList" style="padding:.6rem 0;"></div>
        </div>

        <!-- Résumé final -->
        <div id="regenSummary" style="display:none;" class="alert alert-success"></div>

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
            <button class="btn-primary" id="btnLoadGalleries" style="margin-top:0;" onclick="loadGalleriesPreview()">
                Analyser les galeries
            </button>
            <button class="btn-primary" id="btnRegen" style="margin-top:0;display:none;" onclick="startRegen()">
                Régénérer tout
            </button>
            <button class="btn-add" id="btnRegenCancel" style="display:none;width:auto;padding:.65rem 1.4rem;" onclick="resetRegen()">
                Réinitialiser
            </button>
        </div>
    </section>

    <script>
    // ── Maintenance : régénération des galeries ──
    let regenGalleries = [];
    let regenRunning   = false;

    async function loadGalleriesPreview() {
        const btn = document.getElementById('btnLoadGalleries');
        btn.disabled    = true;
        btn.textContent = 'Analyse…';

        try {
            const res  = await fetch('regen.php?dry=1');
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            regenGalleries = data.galleries;

            const typeLabels = {
                'public'            : '🌐 Publique',
                'private:tag'       : '🔒 Privée tags',
                'private:illust'    : '🔒 Illustrations',
                'private:bookmark'  : '🔒 Bookmarks',
                'private:following' : '🔒 Suivis',
            };

            document.getElementById('regenList').innerHTML = regenGalleries.map(g => {
                const tplOk = g.template_ok
                    ? '<span style="color:#5db87a;font-size:.6rem;">✓ OK</span>'
                    : '<span style="color:#c0776a;font-size:.6rem;">✗ Manquant</span>';
                return `<div style="display:grid;grid-template-columns:1fr auto auto;gap:.6rem;align-items:center;
                                    padding:.45rem .6rem;border-radius:var(--radius);background:var(--bg);">
                    <span style="font-size:.75rem;color:var(--text);">
                        <span style="font-family:'Cormorant Garamond',serif;font-style:italic;font-size:.95rem;">${escH(g.title)}</span>
                        <code style="font-size:.58rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .35rem;border-radius:2px;margin-left:.4rem;">${escH(g.slug)}</code>
                    </span>
                    <span style="font-size:.6rem;color:var(--text-muted);white-space:nowrap;">${typeLabels[g.type] || g.type}</span>
                    ${tplOk}
                </div>`;
            }).join('');

            document.getElementById('regenPreview').style.display    = '';
            document.getElementById('btnRegen').style.display        = '';
            document.getElementById('btnRegenCancel').style.display  = '';
            btn.style.display = 'none';

        } catch (err) {
            btn.disabled    = false;
            btn.textContent = 'Analyser les galeries';
            _modal('Erreur : ' + err.message);
        }
    }

    async function startRegen() {
        if (regenRunning) return;
        regenRunning = true;

        document.getElementById('btnRegen').disabled               = true;
        document.getElementById('regenPreview').style.display      = 'none';
        document.getElementById('regenProgressWrap').style.display = '';
        document.getElementById('regenResults').style.display      = '';
        document.getElementById('regenSummary').style.display      = 'none';
        document.getElementById('regenResultList').innerHTML       = '';

        const bar         = document.getElementById('regenBar');
        const pct         = document.getElementById('regenPercent');
        const statusLabel = document.getElementById('regenStatusLabel');

        const res    = await fetch('regen.php', { method: 'POST', body: new URLSearchParams({ action: 'regen' }) });
        const reader  = res.body.getReader();
        const decoder = new TextDecoder();
        let   buffer  = '';

        function parseEvents(chunk) {
            buffer += chunk;
            const events = buffer.split('\n\n');
            buffer = events.pop();
            for (const block of events) {
                let eventName = 'message', dataStr = '';
                for (const line of block.split('\n')) {
                    if (line.startsWith('event: ')) eventName = line.slice(7);
                    if (line.startsWith('data: '))  dataStr   = line.slice(6);
                }
                if (!dataStr) continue;
                try { handleEvent(eventName, JSON.parse(dataStr)); } catch {}
            }
        }

        function handleEvent(name, data) {
            if (name === 'start') {
                statusLabel.textContent = `Régénération de ${data.total} galerie${data.total > 1 ? 's' : ''}…`;
            } else if (name === 'progress') {
                bar.style.width         = data.percent + '%';
                pct.textContent         = data.percent + ' %';
                statusLabel.textContent = `${data.index} / ${data.total} — ${data.title}`;
                addResultRow(data);
            } else if (name === 'done') {
                bar.style.width         = '100%';
                pct.textContent         = '100 %';
                statusLabel.textContent = 'Terminé';
                const summary     = document.getElementById('regenSummary');
                summary.style.display = '';
                summary.className = data.errors > 0 ? 'alert alert-error' : 'alert alert-success';
                summary.textContent = `${data.success} galerie${data.success > 1 ? 's' : ''} régénérée${data.success > 1 ? 's' : ''} avec succès`
                    + (data.errors > 0 ? `, ${data.errors} erreur${data.errors > 1 ? 's' : ''}` : '') + '.';
                regenRunning = false;
                document.getElementById('btnRegen').disabled = false;
            }
        }

        function addResultRow(data) {
            const list = document.getElementById('regenResultList');
            const ok   = data.status === 'ok';
            const row  = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:.6rem;padding:.35rem .8rem;border-bottom:1px solid var(--border);';
            row.innerHTML = `
                <span style="font-size:.8rem;flex-shrink:0;color:${ok ? '#5db87a' : '#c0776a'};">${ok ? '✓' : '✗'}</span>
                <span style="flex:1;font-size:.72rem;color:var(--text);">${escH(data.title)}
                    <code style="font-size:.58rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .3rem;border-radius:2px;margin-left:.3rem;">${escH(data.slug)}</code>
                </span>
                <span style="font-size:.62rem;color:${ok ? '#5db87a' : '#c0776a'};white-space:nowrap;">${escH(data.message)}</span>
            `;
            list.appendChild(row);
            list.parentElement.scrollTop = list.parentElement.scrollHeight;
        }

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            parseEvents(decoder.decode(value, { stream: true }));
        }
    }

    function resetRegen() {
        regenGalleries = [];
        regenRunning   = false;
        document.getElementById('regenPreview').style.display      = 'none';
        document.getElementById('regenProgressWrap').style.display = 'none';
        document.getElementById('regenResults').style.display      = 'none';
        document.getElementById('regenSummary').style.display      = 'none';
        document.getElementById('regenResultList').innerHTML       = '';
        document.getElementById('regenBar').style.width            = '0';
        document.getElementById('btnRegen').style.display          = 'none';
        document.getElementById('btnRegenCancel').style.display    = 'none';
        document.getElementById('btnLoadGalleries').style.display  = '';
        document.getElementById('btnLoadGalleries').disabled       = false;
        document.getElementById('btnLoadGalleries').textContent    = 'Analyser les galeries';
    }

    function escH(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    </script>

    <?php endif; ?>

</div><!-- /.admin-wrap -->

<script>
// ════════════════════════════════════════════════════════════
//  MODALE CUSTOM (remplace alert / confirm natifs)
// ════════════════════════════════════════════════════════════
(function () {
    // Injection du DOM de la modale
    const tpl = document.createElement('div');
    tpl.innerHTML = `
    <div id="customModal" class="cmodal-backdrop" style="display:none" aria-modal="true" role="dialog">
        <div class="cmodal-box">
            <p class="cmodal-msg" id="cmodalMsg"></p>
            <div class="cmodal-actions" id="cmodalActions"></div>
        </div>
    </div>`;
    document.body.appendChild(tpl.firstElementChild);

    window._modal = function (msg, { confirm = false } = {}) {
        return new Promise(resolve => {
            const backdrop = document.getElementById('customModal');
            const msgEl    = document.getElementById('cmodalMsg');
            const actions  = document.getElementById('cmodalActions');

            msgEl.innerHTML = msg.replace(/\n/g, '<br>');
            actions.innerHTML = '';

            if (confirm) {
                const btnOk = document.createElement('button');
                btnOk.className   = 'cmodal-btn cmodal-btn-danger';
                btnOk.textContent = 'Supprimer';
                btnOk.onclick = () => { close(); resolve(true); };

                const btnCancel = document.createElement('button');
                btnCancel.className   = 'cmodal-btn cmodal-btn-cancel';
                btnCancel.textContent = 'Annuler';
                btnCancel.onclick = () => { close(); resolve(false); };

                actions.append(btnCancel, btnOk);
            } else {
                const btnOk = document.createElement('button');
                btnOk.className   = 'cmodal-btn cmodal-btn-cancel';
                btnOk.textContent = 'OK';
                btnOk.onclick = () => { close(); resolve(true); };
                actions.append(btnOk);
            }

            backdrop.style.display = 'flex';
            requestAnimationFrame(() => backdrop.classList.add('visible'));

            // Fermeture sur backdrop
            backdrop.onclick = e => { if (e.target === backdrop) { close(); resolve(false); } };

            function close () {
                backdrop.classList.remove('visible');
                setTimeout(() => { backdrop.style.display = 'none'; }, 220);
            }
        });
    };
})();

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
    slugInput.addEventListener('keypress', function (e) {
        const allowed = /[a-z0-9\-]/;
        if (!allowed.test(e.key)) e.preventDefault();
    });
}

// ── Gestion des lignes de personnages ──
function addRow(listId) {
    const list = document.getElementById(listId);
    const row  = document.createElement('div');
    row.className  = 'char-row';
    row.innerHTML  = `
        <span class="drag-handle" draggable="true" title="Glisser pour réordonner">⠿</span>
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
    const rows = list ? list.querySelectorAll('.char-row') : [];
    if (rows.length <= 1) {
        _modal('Conservez au moins un tag.');
        return;
    }
    btn.closest('.char-row').remove();
}

// ════════════════════════════════════════════════════════════
//  DRAG & DROP — Tags (handle seul)
// ════════════════════════════════════════════════════════════
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
        document.querySelectorAll('.char-row').forEach(r =>
            r.classList.remove('drag-dragging', 'drag-over')
        );
        dragSrcRow = null;
    });
}

// Les events dragover/drop restent sur les lignes (zones de dépôt)
document.addEventListener('dragover', e => {
    const target = e.target.closest('.char-row');
    if (!target || !dragSrcRow || target === dragSrcRow) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    // Supprimer drag-over des autres lignes du même container
    dragSrcRow.closest('.char-list')
        ?.querySelectorAll('.char-row')
        .forEach(r => r.classList.remove('drag-over'));
    target.classList.add('drag-over');
});

document.addEventListener('dragleave', e => {
    const target = e.target.closest('.char-row');
    if (target) target.classList.remove('drag-over');
});

document.addEventListener('drop', e => {
    const target = e.target.closest('.char-row');
    if (!target || !dragSrcRow || target === dragSrcRow) return;
    if (target.closest('.char-list') !== dragSrcRow.closest('.char-list')) return;
    e.preventDefault();
    e.stopPropagation();
    const list   = target.closest('.char-list');
    const rows   = [...list.querySelectorAll('.char-row')];
    const srcIdx = rows.indexOf(dragSrcRow);
    const tgtIdx = rows.indexOf(target);
    list.insertBefore(dragSrcRow, srcIdx < tgtIdx ? target.nextSibling : target);
    target.classList.remove('drag-over');
});

// Initialiser les handles existants
document.querySelectorAll('.char-row .drag-handle').forEach(initDragHandle);

// ════════════════════════════════════════════════════════════
//  DRAG & DROP — Galeries (réorganisation)
// ════════════════════════════════════════════════════════════
let dragSrcGallery = null;

function initGalleryDragHandle(handle) {
    const item = handle.closest('.gallery-item');

    handle.addEventListener('dragstart', e => {
        dragSrcGallery = item;
        item.classList.add('drag-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
        e.stopPropagation();
    });

    handle.addEventListener('dragend', () => {
        document.querySelectorAll('.gallery-item').forEach(i =>
            i.classList.remove('drag-dragging', 'drag-over')
        );
        // Sauvegarder le nouvel ordre automatiquement
        saveGalleryOrder();
        dragSrcGallery = null;
    });
}

document.addEventListener('dragover', e => {
    const target = e.target.closest('.gallery-item');
    if (!target || !dragSrcGallery || target === dragSrcGallery) return;
    if (target.closest('#galleryList') !== dragSrcGallery.closest('#galleryList')) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    document.querySelectorAll('.gallery-item').forEach(i => i.classList.remove('drag-over'));
    target.classList.add('drag-over');
}, true);

document.addEventListener('dragleave', e => {
    const target = e.target.closest('.gallery-item');
    if (target) target.classList.remove('drag-over');
}, true);

document.addEventListener('drop', e => {
    const target = e.target.closest('.gallery-item');
    if (!target || !dragSrcGallery || target === dragSrcGallery) return;
    if (target.closest('#galleryList') !== dragSrcGallery.closest('#galleryList')) return;
    e.preventDefault();
    e.stopPropagation();
    const list   = target.closest('#galleryList');
    const items  = [...list.querySelectorAll(':scope > .gallery-item')];
    const srcIdx = items.indexOf(dragSrcGallery);
    const tgtIdx = items.indexOf(target);
    list.insertBefore(dragSrcGallery, srcIdx < tgtIdx ? target.nextSibling : target);
    target.classList.remove('drag-over');
}, true);

function saveGalleryOrder() {
    const list = document.getElementById('galleryList');
    if (!list) return;
    const slugs = [...list.querySelectorAll(':scope > .gallery-item[data-slug]')]
        .map(i => i.dataset.slug);
    const orderInput = document.getElementById('galleryOrder');
    const form       = document.getElementById('reorderForm');
    if (!orderInput || !form) return;
    orderInput.value = JSON.stringify(slugs);
    form.submit();
}

// Initialiser les handles de galeries
document.querySelectorAll('.gallery-drag-handle').forEach(initGalleryDragHandle);

// ── Confirmation de suppression ──
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
<?php }
