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
            // Validation : extraire l'userId et interroger Pixiv avant d'enregistrer
            $userId = null;
            if (preg_match('/^(\d+)_/', $sessid, $m)) $userId = $m[1];

            if (!$userId) {
                $error = 'Format de PHPSESSID invalide (userId introuvable avant le « _ »).';
            } else {
                $ch = curl_init('https://www.pixiv.net/ajax/user/' . $userId);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                        'Accept: application/json',
                        'Accept-Language: en-US,en;q=0.9',
                        'Referer: https://www.pixiv.net/',
                        'Cookie: PHPSESSID=' . $sessid,
                    ],
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $resp      = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err  = curl_error($ch);
                curl_close($ch);

                if ($curl_err) {
                    $error = 'Impossible de joindre Pixiv pour valider le cookie : ' . $curl_err;
                } elseif ($http_code !== 200) {
                    $error = 'Pixiv a répondu HTTP ' . $http_code . ' — cookie probablement invalide.';
                } else {
                    $pdata = json_decode($resp, true);
                    if (!$pdata || ($pdata['error'] ?? false)) {
                        $msg   = $pdata['message'] ?? 'Session expirée ou cookie invalide.';
                        $error = 'Cookie Pixiv refusé : ' . $msg;
                    } else {
                        $username = $pdata['body']['name'] ?? null;
                        $SETTINGS['phpsessid'] = $sessid;
                        save_settings($SETTINGS);
                        $success = 'PHPSESSID mis à jour'
                            . ($username ? ' — connecté en tant que ' . htmlspecialchars($username) : '')
                            . '.';
                    }
                }
            }
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

    // --- Préférences d'affichage galeries ---
    elseif ($action === 'update_gallery_defaults') {
        $tab      = 'options';
        $order    = $_POST['def_order']    ?? 'popular_d';
        $period   = $_POST['def_period']   ?? '';
        $per_page = (int)($_POST['def_per_page'] ?? 28);
        $mode     = $_POST['def_mode']     ?? 'safe';

        if (!in_array($order,    ['popular_d', 'date_d'], true))                     $order    = 'popular_d';
        if (!in_array($period,   ['', 'day', 'week', 'month', '6month', 'year'], true)) $period = '';
        if (!in_array($per_page, [28, 56], true))                               $per_page = 28;
        if (!in_array($mode,     ['safe', 'r18', 'all'], true))                      $mode     = 'safe';

        $SETTINGS['gallery_defaults'] = compact('order', 'period', 'per_page', 'mode');
        save_settings($SETTINGS);
        $success = 'Préférences d\'affichage enregistrées.';
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

        <form method="POST" id="sessidForm">
            <input type="hidden" name="action" value="update_sessid">
            <div class="field">
                <label for="phpsessid">PHPSESSID</label>
                <input type="text" id="phpsessid" name="phpsessid"
                       value="<?= htmlspecialchars($settings['phpsessid']) ?>"
                       placeholder="Votre PHPSESSID Pixiv" required autocomplete="off">
                <span class="hint">Connectez-vous sur pixiv.net → F12 → Application → Cookies → pixiv.net → PHPSESSID</span>
            </div>
            <div id="sessidValidation" style="display:none;margin-bottom:1rem;"></div>
            <button type="submit" class="btn-primary" id="btnSaveSessid">Mettre à jour</button>
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
                            <a href="galleries/<?= htmlspecialchars($g['slug']) ?>.php" target="_blank" class="btn-small" onclick="event.stopPropagation()">Voir</a>
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

    <!-- ── Préférences d'affichage (admin) ── -->
    <?php $defs = get_admin_gallery_defaults($settings); ?>
    <section class="admin-section">
        <p class="section-title">Préférences d'affichage</p>
        <p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;margin-bottom:1.6rem;line-height:1.6;">
            Appliquées uniquement quand vous êtes connecté en administration.
            Les visiteurs voient toujours les valeurs publiques par défaut.
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="update_gallery_defaults">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;margin-bottom:1.4rem;">

                <div class="field" style="margin-bottom:0;">
                    <label>Tri par défaut</label>
                    <div class="control-pills" style="margin-top:.4rem;">
                        <!-- Tri -->
                        <button type="button" class="pill <?= $defs['order']==='popular_d'?'active':'' ?>"
                                data-value="popular_d" onclick="pickAdminPref(this,'def_order')">Populaires</button>
                        <button type="button" class="pill <?= $defs['order']==='date_d'?'active':'' ?>"
                                data-value="date_d" onclick="pickAdminPref(this,'def_order')">Récentes</button>
                    </div>
                    <input type="hidden" name="def_order" id="def_order" value="<?= htmlspecialchars($defs['order']) ?>">
                </div>

                <div class="field" style="margin-bottom:0;">
                    <label>Contenu par défaut</label>
                    <div class="control-pills" style="margin-top:.4rem;">
                        <!-- Contenu -->
                        <button type="button" class="pill <?= $defs['mode']==='safe'?'active':'' ?>"
                                data-value="safe" onclick="pickAdminPref(this,'def_mode')">Safe</button>
                        <button type="button" class="pill <?= $defs['mode']==='r18'?'active':'' ?>"
                                data-value="r18" onclick="pickAdminPref(this,'def_mode')">18+</button>
                        <button type="button" class="pill <?= $defs['mode']==='all'?'active':'' ?>"
                                data-value="all" onclick="pickAdminPref(this,'def_mode')">Tout</button>
                    </div>
                    <input type="hidden" name="def_mode" id="def_mode" value="<?= htmlspecialchars($defs['mode']) ?>">
                </div>

                <div class="field" style="margin-bottom:0;">
                    <label>Illustrations par page</label>
                    <div class="control-pills" style="margin-top:.4rem;">
                        <?php foreach ([28, 56] as $pp): ?>
                        <button type="button" class="pill <?= $defs['per_page']===$pp?'active':'' ?>"
                                data-value="<?= $pp ?>" onclick="pickAdminPref(this,'def_per_page')"><?= $pp ?></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="def_per_page" id="def_per_page" value="<?= $defs['per_page'] ?>">
                </div>

                <div class="field" style="margin-bottom:0;" id="defPeriodField">
                    <label>
                        Période par défaut
                        <span style="font-size:.55rem;color:var(--text-muted);letter-spacing:.05em;text-transform:none;">
                            (tri Populaires uniquement)
                        </span>
                    </label>
                    <div class="control-pills" style="margin-top:.4rem;flex-wrap:wrap;gap:4px;">
                        <!-- Période -->
                        <?php foreach (['' => '∞', 'day' => '24h', 'week' => '7 jours', 'month' => '1 mois', '6month' => '6 mois', 'year' => '1 an'] as $val => $label): ?>
                        <button type="button" class="pill <?= $defs['period']===$val?'active':'' ?>"
                                data-value="<?= $val ?>" onclick="pickAdminPref(this,'def_period')"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="def_period" id="def_period" value="<?= htmlspecialchars($defs['period']) ?>">
                </div>

            </div>

            <button type="submit" class="btn-primary" style="margin-top:0;">Enregistrer les préférences</button>
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

    <!-- ══ Import / Export ══ -->
    <section class="admin-section" style="margin-top:1.5rem;">
        <p class="section-title">Sauvegardes & Import</p>
        <p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;margin-bottom:1.4rem;line-height:1.6;">
            Les sauvegardes incluent toutes les galeries publiques et privées (fichiers JSON uniquement).
            Elles sont stockées dans <code style="font-size:.65rem;color:var(--accent-dim);background:rgba(200,169,126,.06);padding:.1rem .4rem;border-radius:2px;">saves/</code>
            et non accessibles publiquement.
        </p>

        <!-- Tabs internes -->
        <div style="display:flex;gap:0;margin-bottom:1.6rem;border-bottom:1px solid var(--border);">
            <button class="backup-tab active" data-panel="export" onclick="switchBackupTab('export')">Exporter</button>
            <button class="backup-tab" data-panel="import" onclick="switchBackupTab('import')">Importer</button>
        </div>

        <!-- ── Panel Export ── -->
        <div id="backupPanelExport">

            <!-- Liste des sauvegardes -->
            <div id="savesList" style="margin-bottom:1.4rem;"></div>

            <!-- Bouton créer -->
            <button class="btn-primary" id="btnExport" style="margin-top:0;" onclick="doExport()">
                Créer une sauvegarde maintenant
            </button>
            <div id="exportResult" style="display:none;margin-top:1rem;"></div>
        </div>

        <!-- ── Panel Import ── -->
        <div id="backupPanelImport" style="display:none;">

            <!-- Source : sauvegarde existante ou upload -->
            <div style="margin-bottom:1.4rem;">
                <div style="display:flex;gap:4px;margin-bottom:1rem;">
                    <button class="pill active" id="importSrcSave" onclick="switchImportSrc('save')">Depuis une sauvegarde</button>
                    <button class="pill" id="importSrcUpload" onclick="switchImportSrc('upload')">Importer un fichier ZIP</button>
                </div>

                <!-- Sélection sauvegarde existante -->
                <div id="importSrcSavePanel">
                    <div id="importSaveList" style="margin-bottom:.8rem;"></div>
                    <p id="importSaveEmpty" style="display:none;font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;">
                        Aucune sauvegarde disponible. Créez-en une depuis l'onglet "Exporter".
                    </p>
                </div>

                <!-- Upload fichier ZIP -->
                <div id="importSrcUploadPanel" style="display:none;">
                    <label style="display:flex;flex-direction:column;gap:.5rem;">
                        <span style="font-size:.65rem;letter-spacing:.18em;text-transform:uppercase;color:var(--text-muted);">Fichier ZIP</span>
                        <input type="file" id="importFileInput" accept=".zip"
                            style="font-family:'Josefin Sans',sans-serif;font-size:.78rem;color:var(--text);background:var(--bg);
                                    border:1px solid var(--border);border-radius:var(--radius);padding:.5rem .7rem;cursor:pointer;">
                    </label>
                    <button class="btn-primary" style="margin-top:.8rem;" onclick="analyzeUpload()">Analyser le fichier</button>
                </div>
            </div>

            <!-- Récap analyse -->
            <div id="importAnalysis" style="display:none;">
                <div id="importMeta" style="margin-bottom:1rem;padding:.6rem .9rem;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);font-size:.65rem;letter-spacing:.08em;color:var(--text-muted);"></div>

                <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:.5rem;">
                    <span style="font-size:.65rem;letter-spacing:.18em;text-transform:uppercase;color:var(--text-muted);">Galeries dans la sauvegarde</span>
                    <div style="display:flex;gap:.5rem;">
                        <button class="btn-add" style="width:auto;padding:.3rem .8rem;font-size:.6rem;" onclick="selectAllImport(true)">Tout cocher</button>
                        <button class="btn-add" style="width:auto;padding:.3rem .8rem;font-size:.6rem;" onclick="selectAllImport(false)">Tout décocher</button>
                    </div>
                </div>

                <div id="importGalleryList" style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:1.2rem;max-height:420px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);padding:.6rem;"></div>

                <button class="btn-primary" id="btnDoImport" style="margin-top:0;" onclick="doImport()">
                    Importer la sélection
                </button>
            </div>

            <div id="importResult" style="display:none;margin-top:1rem;"></div>
        </div>
    </section>

    <script>
    // ════════════════════════════════════════════════════════════
    //  BACKUP — Import / Export
    // ════════════════════════════════════════════════════════════

    let importSource   = 'save';   // 'save' | 'upload'
    let analyzedSource = null;     // nom du fichier analysé (pour l'import)
    let analyzedData   = null;     // résultat de l'analyse

    // ── Navigation ──
    function switchBackupTab(panel) {
        document.querySelectorAll('.backup-tab').forEach(t => t.classList.toggle('active', t.dataset.panel === panel));
        document.getElementById('backupPanelExport').style.display = panel === 'export' ? '' : 'none';
        document.getElementById('backupPanelImport').style.display = panel === 'import' ? '' : 'none';
        if (panel === 'import') refreshImportSaveList();
    }

    function switchImportSrc(src) {
        importSource = src;
        document.getElementById('importSrcSave').classList.toggle('active', src === 'save');
        document.getElementById('importSrcUpload').classList.toggle('active', src === 'upload');
        document.getElementById('importSrcSavePanel').style.display   = src === 'save'   ? '' : 'none';
        document.getElementById('importSrcUploadPanel').style.display = src === 'upload' ? '' : 'none';
        resetImportAnalysis();
    }

    function resetImportAnalysis() {
        analyzedSource = null;
        analyzedData   = null;
        document.getElementById('importAnalysis').style.display = 'none';
        document.getElementById('importResult').style.display   = 'none';
    }

    // ── Formatage ──
    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / 1048576).toFixed(1) + ' Mo';
    }
    function fmtDate(ts) {
        return new Date(ts * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    }

    // ── Liste des sauvegardes (panel export) ──
    async function loadSavesList() {
        const container = document.getElementById('savesList');
        try {
            const res  = await fetch('backup.php?action=list');
            const data = await res.json();
            if (!data.saves || !data.saves.length) {
                container.innerHTML = '<p style="font-size:.68rem;color:var(--text-muted);letter-spacing:.06em;">Aucune sauvegarde pour l\'instant.</p>';
                return;
            }
            container.innerHTML = data.saves.map(s => `
                <div class="save-item">
                    <div class="save-item-info">
                        <div class="save-item-name">${escH(s.file)}</div>
                        <div class="save-item-meta">${fmtDate(s.date)} · ${fmtSize(s.size)}</div>
                    </div>
                    <div style="display:flex;gap:.5rem;flex-shrink:0;">
                        <button class="btn-small" onclick="doRestore('${escH(s.file)}')">Restaurer</button>
                        <button class="btn-small" style="color:#c0776a;border-color:#6a3030;"
                                onclick="deleteSave('${escH(s.file)}', this)">Supprimer</button>
                    </div>
                </div>`).join('');
        } catch {
            container.innerHTML = '<p style="font-size:.68rem;color:#c0776a;">Erreur lors du chargement des sauvegardes.</p>';
        }
    }

    async function deleteSave(file, btn) {
        const ok = await _modal(`Supprimer la sauvegarde <em>${file}</em> ?<br>Cette action est irréversible.`, { confirm: true });
        if (!ok) return;
        btn.disabled = true;
        const fd = new FormData();
        fd.append('action', 'delete'); fd.append('file', file);
        const res  = await fetch('backup.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) { loadSavesList(); refreshImportSaveList(); }
        else _modal('Erreur : ' + data.error);
    }

    // ── Export ──
    async function doExport() {
        const btn = document.getElementById('btnExport');
        const res_el = document.getElementById('exportResult');
        btn.disabled = true; btn.textContent = 'Création…';
        res_el.style.display = 'none';
        try {
            const fd = new FormData(); fd.append('action', 'export');
            const res  = await fetch('backup.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            res_el.className = 'alert alert-success';
            res_el.textContent = `Sauvegarde créée : ${data.file} (${fmtSize(data.size)}, ${data.count} galerie${data.count > 1 ? 's' : ''})`;
            res_el.style.display = '';
            loadSavesList();
            refreshImportSaveList();
        } catch (err) {
            res_el.className = 'alert alert-error';
            res_el.textContent = 'Erreur : ' + err.message;
            res_el.style.display = '';
        } finally {
            btn.disabled = false; btn.textContent = 'Créer une sauvegarde maintenant';
        }
    }

    // ── Liste sauvegardes dans le panel import ──
    async function refreshImportSaveList() {
        const container = document.getElementById('importSaveList');
        const empty     = document.getElementById('importSaveEmpty');
        try {
            const res  = await fetch('backup.php?action=list');
            const data = await res.json();
            if (!data.saves || !data.saves.length) {
                container.innerHTML = '';
                empty.style.display = '';
                return;
            }
            empty.style.display = 'none';
            container.innerHTML = data.saves.map(s => `
                <div class="save-item" style="cursor:pointer;" onclick="analyzeFromSave('${escH(s.file)}', this)">
                    <div class="save-item-info">
                        <div class="save-item-name">${escH(s.file)}</div>
                        <div class="save-item-meta">${fmtDate(s.date)} · ${fmtSize(s.size)}</div>
                    </div>
                    <span class="btn-small">Analyser →</span>
                </div>`).join('');
        } catch {}
    }

    // ── Analyse depuis sauvegarde existante ──
    async function analyzeFromSave(file, rowEl) {
        resetImportAnalysis();
        document.querySelectorAll('#importSaveList .save-item').forEach(el => el.style.borderColor = '');
        if (rowEl) rowEl.style.borderColor = 'var(--accent)';

        const fd = new FormData(); fd.append('action', 'analyze'); fd.append('savefile', file);
        await runAnalysis(fd, file);
    }

    // ── Analyse depuis upload ──
    async function analyzeUpload() {
        const input = document.getElementById('importFileInput');
        if (!input.files || !input.files[0]) { _modal('Veuillez sélectionner un fichier ZIP.'); return; }
        resetImportAnalysis();
        const fd = new FormData(); fd.append('action', 'analyze'); fd.append('zipfile', input.files[0]);
        await runAnalysis(fd, input.files[0].name);
    }

    async function runAnalysis(fd, sourceName) {
        document.getElementById('importAnalysis').style.display = 'none';
        try {
            const res  = await fetch('backup.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            analyzedSource = sourceName;
            analyzedData   = data;
            renderAnalysis(data);
        } catch (err) {
            _modal('Erreur lors de l\'analyse : ' + err.message);
        }
    }

    function renderAnalysis(data) {
        const meta = data.meta || {};
        document.getElementById('importMeta').innerHTML =
            `Source : <strong style="color:var(--text)">${escH(data.source)}</strong>`
            + (meta.created_at ? ` · Créée le ${new Date(meta.created_at).toLocaleString('fr-FR', {dateStyle:'short',timeStyle:'short'})}` : '')
            + (meta.version    ? ` · Version ${escH(meta.version)}` : '')
            + ` · <strong style="color:var(--text)">${data.galleries.length}</strong> galerie${data.galleries.length > 1 ? 's' : ''}`;

        const TYPE_LABELS = {
            'public': '🌐 Publique',
            'private:tag': '🔒 Privée tags',
            'private:illust': '🔒 Illustrations',
            'private:bookmark': '🔒 Bookmarks',
            'private:following': '🔒 Artistes suivis',
        };

        document.getElementById('importGalleryList').innerHTML = data.galleries.map((g, i) => `
            <div class="import-row${g.conflict ? ' conflict' : ''}" id="importRow${i}">
                <input type="checkbox" class="import-row-check" id="importCheck${i}"
                    ${g.conflict ? '' : 'checked'}
                    onchange="updateImportRow(${i})">
                <div class="import-row-info">
                    <div class="import-row-title">${escH(g.title)}</div>
                    <div class="import-row-meta">
                        ${TYPE_LABELS[g.type] || g.type}
                        ${g.chars > 0 ? ` · ${g.chars} tag${g.chars > 1 ? 's' : ''}` : ''}
                    </div>
                </div>
                <div class="import-row-slug">
                    <input type="text" class="import-slug-input" id="importSlug${i}"
                        value="${escH(g.slug)}"
                        maxlength="20" pattern="[a-z0-9\\-]{1,20}"
                        title="a-z, 0-9, tirets, 20 car. max"
                        oninput="validateSlugInput(this)">
                    ${g.conflict
                        ? `<span class="conflict-badge">⚠ Conflit</span>`
                        : `<span class="new-badge">✓ Nouveau</span>`}
                </div>
            </div>`).join('');

        document.getElementById('importAnalysis').style.display = '';
    }

    function updateImportRow(i) {
        const row   = document.getElementById('importRow' + i);
        const check = document.getElementById('importCheck' + i);
        row.classList.toggle('selected', check.checked);
    }

    function selectAllImport(checked) {
        document.querySelectorAll('.import-row-check').forEach((cb, i) => {
            cb.checked = checked;
            updateImportRow(i);
        });
    }

    function validateSlugInput(input) {
        input.value = input.value.toLowerCase().replace(/[^a-z0-9\-]/g, '');
    }

    // ── Import effectif ──
    async function doImport() {
        if (!analyzedData) return;

        // Construire la sélection
        const selections = [];
        analyzedData.galleries.forEach((g, i) => {
            const check = document.getElementById('importCheck' + i);
            const slug  = document.getElementById('importSlug'  + i);
            if (!check || !check.checked) return;
            if (!slug.value.match(/^[a-z0-9\-]{1,20}$/)) {
                slug.style.borderColor = '#c0776a'; return;
            }
            slug.style.borderColor = '';
            selections.push({ original_slug: g.slug, new_slug: slug.value, type: g.type });
        });

        if (!selections.length) { _modal('Sélectionnez au moins une galerie à importer.'); return; }

        // Avertir pour les conflits
        const conflicts = selections.filter(s => {
            const orig = analyzedData.galleries.find(g => g.slug === s.original_slug);
            return orig?.conflict && s.new_slug === s.original_slug;
        });

        if (conflicts.length) {
            const names = conflicts.map(s => {
                const g = analyzedData.galleries.find(x => x.slug === s.original_slug);
                return `<em>${g?.title || s.original_slug}</em>`;
            }).join(', ');
            const ok = await _modal(
                `${conflicts.length} galerie${conflicts.length > 1 ? 's' : ''} va écraser une galerie existante : ${names}.<br><br>Continuer quand même ?`,
                { confirm: true }
            );
            if (!ok) return;
        }

        const btn = document.getElementById('btnDoImport');
        btn.disabled = true; btn.textContent = 'Import en cours…';

        const fd = new FormData();
        fd.append('action', 'import');
        fd.append('selections', JSON.stringify(selections));

        // Source
        const fileInput = document.getElementById('importFileInput');
        if (importSource === 'upload' && fileInput.files && fileInput.files[0]) {
            fd.append('zipfile', fileInput.files[0]);
        } else {
            fd.append('savefile', analyzedSource);
        }

        try {
            const res  = await fetch('backup.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            const resEl = document.getElementById('importResult');
            const hasErrors = data.errors > 0;
            resEl.className = hasErrors ? 'alert alert-error' : 'alert alert-success';

            const lines = data.results.map(r =>
                `<span style="color:${r.status === 'ok' ? '#7ec896' : '#c0776a'}">${r.status === 'ok' ? '✓' : '✗'}</span> `
                + `<em>${escH(r.slug)}</em> — ${escH(r.message)}`
            ).join('<br>');

            resEl.innerHTML = `<strong>${data.success} importée${data.success > 1 ? 's' : ''}${data.errors > 0 ? `, ${data.errors} erreur${data.errors > 1 ? 's' : ''}` : ''}</strong><br><span style="font-size:.65rem;line-height:2;">${lines}</span>`;
            resEl.style.display = '';
        } catch (err) {
            const resEl = document.getElementById('importResult');
            resEl.className = 'alert alert-error';
            resEl.textContent = 'Erreur : ' + err.message;
            resEl.style.display = '';
        } finally {
            btn.disabled = false; btn.textContent = 'Importer la sélection';
        }
    }

    // ── Restauration complète ──────────────────────────────────
    // (accessible depuis la liste des sauvegardes en panel export)
    async function doRestore(file) {
        const ok = await _modal(
            `<strong>Restauration complète</strong><br><br>`
            + `Cela va <span style="color:#c0776a">remplacer toutes vos galeries actuelles</span> par celles de la sauvegarde :<br>`
            + `<em>${escH(file)}</em><br><br>`
            + `Les galeries créées après cette sauvegarde seront <strong>définitivement perdues</strong>.<br><br>`
            + `Êtes-vous sûr de vouloir continuer ?`,
            { confirm: true }
        );
        if (!ok) return;

        const fd = new FormData();
        fd.append('action', 'restore');
        fd.append('savefile', file);

        const res  = await fetch('backup.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) { _modal('Erreur : ' + data.error); return; }

        const resEl = document.getElementById('exportResult');
        resEl.className = 'alert alert-success';
        resEl.textContent = `Restauration terminée : ${data.count} galerie${data.count > 1 ? 's' : ''} restaurée${data.count > 1 ? 's' : ''}.`;
        resEl.style.display = '';
    }

    // ── Init ──
    loadSavesList();

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

// Met à jour le badge de statut du cookie actuellement enregistré
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

// Validation du PHPSESSID saisi AVANT soumission du formulaire
(function () {
    const form    = document.getElementById('sessidForm');
    const input   = document.getElementById('phpsessid');
    const btn     = document.getElementById('btnSaveSessid');
    const validEl = document.getElementById('sessidValidation');
    const dot     = document.getElementById('cookieStatusDot');
    const text    = document.getElementById('cookieStatusText');

    function resetValidation() {
        validEl.style.display   = 'none';
        validEl.innerHTML       = '';
        input.style.borderColor = '';
    }

    function showResult(ok, message) {
        validEl.style.display   = '';
        validEl.className       = ok ? 'alert alert-success' : 'alert alert-error';
        validEl.textContent     = message;
        input.style.borderColor = ok ? '#4a9060' : '#c0776a';
    }

    input.addEventListener('input', resetValidation);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const sessid = input.value.trim();
        if (!sessid) return;

        btn.disabled    = true;
        btn.textContent = 'Vérification…';
        resetValidation();
        dot.className    = 'cookie-status-dot';
        text.textContent = 'Vérification en cours…';

        try {
            const res  = await fetch('pixiv-check.php?sessid=' + encodeURIComponent(sessid));
            const data = await res.json();

            if (data.valid) {
                const who = data.username ? `connecté en tant que ${data.username}` : 'session active';
                dot.className    = 'cookie-status-dot valid';
                text.textContent = `Cookie valide — ${who}`;
                showResult(true, 'Cookie valide' + (data.username ? ' — ' + data.username : '') + '. Enregistrement…');
                form.submit();
            } else {
                dot.className    = 'cookie-status-dot invalid';
                text.textContent = `Cookie invalide — ${data.reason}`;
                showResult(false, 'Cookie refusé par Pixiv : ' + data.reason);
                btn.disabled    = false;
                btn.textContent = 'Mettre à jour';
            }
        } catch {
            dot.className    = 'cookie-status-dot invalid';
            text.textContent = 'Impossible de joindre Pixiv.';
            showResult(false, 'Impossible de joindre Pixiv pour valider le cookie. Réessayez ou vérifiez votre connexion.');
            btn.disabled    = false;
            btn.textContent = 'Mettre à jour';
        }
    });
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

// ── Préférences galerie admin ──
function pickAdminPref(btn, targetId) {
    btn.closest('.control-pills').querySelectorAll('.pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(targetId).value = btn.dataset.value;

    if (targetId === 'def_order') {
        const field = document.getElementById('defPeriodField');
        if (field) {
            const isDate = btn.dataset.value === 'date_d';
            field.style.opacity       = isDate ? '.4' : '1';
            field.style.pointerEvents = isDate ? 'none' : '';
        }
    }
}

// Init : griser la période si le défaut sauvé est "date_d"
(function initDefPeriodState() {
    const orderInput = document.getElementById('def_order');
    const field      = document.getElementById('defPeriodField');
    if (!orderInput || !field) return;
    if (orderInput.value === 'date_d') {
        field.style.opacity       = '.4';
        field.style.pointerEvents = 'none';
    }
})();

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