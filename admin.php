<?php
// ============================================================
//  admin.php — Interface d'administration
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
    // Afficher le formulaire de login
    loginPage($error);
    exit;
}

// ── Traitement des actions (utilisateur connecté) ──
global $SETTINGS;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Changer le mot de passe admin ---
    if ($action === 'change_password') {
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

    // --- Mettre à jour le titre de la galerie ---
    elseif ($action === 'update_title') {
        $title = trim($_POST['gallery_title'] ?? '');
        if ($title === '') {
            $error = 'Le titre ne peut pas être vide.';
        } else {
            $SETTINGS['gallery_title'] = $title;
            save_settings($SETTINGS);
            $success = 'Titre mis à jour.';
        }
    }

    // --- Mettre à jour le PHPSESSID ---
    elseif ($action === 'update_sessid') {
        $sessid = trim($_POST['phpsessid'] ?? '');
        if ($sessid === '') {
            $error = 'Le PHPSESSID ne peut pas être vide.';
        } else {
            $SETTINGS['phpsessid'] = $sessid;
            save_settings($SETTINGS);
            $success = 'PHPSESSID mis à jour.';
        }
    }

    // --- Sauvegarder les personnages ---
    elseif ($action === 'save_characters') {
        $labels = $_POST['char_label'] ?? [];
        $tags   = $_POST['char_tag']   ?? [];
        $characters = [];
        for ($i = 0; $i < count($labels); $i++) {
            $label = trim($labels[$i]);
            $tag   = trim($tags[$i]);
            if ($label !== '' && $tag !== '') {
                $characters[] = ['label' => $label, 'tag' => $tag];
            }
        }
        if (empty($characters)) {
            $error = 'Vous devez conserver au moins un personnage.';
        } else {
            $SETTINGS['characters'] = $characters;
            save_settings($SETTINGS);
            $success = 'Personnages mis à jour.';
        }
    }
}

// ════════════════════════════════════════════════════════════
//  AFFICHAGE
// ════════════════════════════════════════════════════════════
adminPage($SETTINGS, $error, $success);

// ── Rendu de la page login ──
function loginPage(string $error): void {
?>
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
            <a href="galerie.php" style="color:var(--text-muted);text-decoration:none;">← Retour à la galerie</a>
        </p>
    </div>
</div>
</body>
</html>
<?php
}

// ── Rendu de la page admin principale ──
function adminPage(array $settings, string $error, string $success): void {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — Galerie Pixiv</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png">
</head>
<body>

<div class="admin-wrap">
    <div class="admin-header">
        <div>
            <p class="site-label" style="text-align:left;margin-bottom:.5rem;">Administration</p>
            <h1>Galerie Pixiv</h1>
        </div>
        <div style="display:flex;gap:1.5rem;align-items:center;">
            <a href="galerie.php">← Galerie</a>
            <a href="admin.php?logout=1">Déconnexion</a>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── Section : Pixiv Session ── -->
    <section class="admin-section">
        <p class="section-title">Cookie de session Pixiv</p>
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

    <!-- ── Section : Titre de la galerie ── -->
    <section class="admin-section">
        <p class="section-title">Titre de la galerie</p>
        <form method="POST">
            <input type="hidden" name="action" value="update_title">
            <div class="field">
                <label for="gallery_title">Titre affiché</label>
                <input type="text" id="gallery_title" name="gallery_title"
                    value="<?= htmlspecialchars($settings['gallery_title'] ?? 'Illustrations') ?>"
                    placeholder="Illustrations" required>
                <span class="hint">Affiché dans le header de la galerie et dans l'onglet du navigateur.</span>
            </div>
            <button type="submit" class="btn-primary">Mettre à jour</button>
        </form>
    </section>

    <!-- ── Section : Personnages / Tags ── -->
    <section class="admin-section">
        <p class="section-title">Personnages &amp; Tags</p>
        <form method="POST" id="charForm">
            <input type="hidden" name="action" value="save_characters">

            <div class="char-row-header">
                <span>Label affiché</span>
                <span>Tag Pixiv</span>
                <span></span>
            </div>

            <div id="charList">
                <?php foreach ($settings['characters'] as $char): ?>
                <div class="char-row">
                    <input type="text" name="char_label[]"
                           value="<?= htmlspecialchars($char['label']) ?>"
                           placeholder="Nom affiché" required>
                    <input type="text" name="char_tag[]"
                           value="<?= htmlspecialchars($char['tag']) ?>"
                           placeholder="Tag japonais ou latin" required>
                    <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn-add" onclick="addRow()">+ Ajouter un personnage</button>

            <button type="submit" class="btn-primary">Enregistrer les personnages</button>
        </form>
    </section>

    <!-- ── Section : Mot de passe ── -->
    <section class="admin-section">
        <p class="section-title">Mot de passe administrateur</p>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>
            <div class="field">
                <label for="new_password">Nouveau mot de passe</label>
                <input type="password" id="new_password" name="new_password"
                       minlength="6" required>
                <span class="hint">6 caractères minimum.</span>
            </div>
            <div class="field">
                <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn-primary">Changer le mot de passe</button>
        </form>
    </section>
</div>

<script>
function addRow() {
    const row = document.createElement('div');
    row.className = 'char-row';
    row.innerHTML = `
        <input type="text" name="char_label[]" placeholder="Nom affiché" required>
        <input type="text" name="char_tag[]"   placeholder="Tag japonais ou latin" required>
        <button type="button" class="btn-danger" onclick="removeRow(this)">✕</button>
    `;
    document.getElementById('charList').appendChild(row);
    row.querySelector('input').focus();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#charList .char-row');
    if (rows.length <= 1) {
        alert('Vous devez conserver au moins un personnage.');
        return;
    }
    btn.closest('.char-row').remove();
}
</script>

</body>
</html>
<?php
}
