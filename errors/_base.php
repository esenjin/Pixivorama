<?php
// ============================================================
//  errors/_base.php — Template commun pour les pages d'erreur
//
//  Variables attendues avant l'include :
//    string $code     — Code HTTP (ex: '404')
//    string $title    — Titre court
//    string $desc     — Description (HTML limité : <br> autorisé)
//    array  $actions  — [['label'=>'', 'href'=>'', 'primary'=>bool], ...]
//                       Utiliser '{base}' dans href pour la racine du projet.
//    string $detail   — Détail technique optionnel (sera échappé)
//
//  Pas de $depth à définir : la racine est détectée automatiquement.
//  Fonctionne à la racine du domaine ET dans N sous-dossiers.
// ============================================================

// ── Détection universelle de l'URL de base du projet ─────────
// errors/_base.php est inclus depuis errors/{code}.php
// La racine du projet est le dossier parent de errors/
$_err_script   = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME']));
$_project_fs   = dirname($_err_script); // racine projet côté filesystem
$_doc_root     = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/'));
$base_url      = substr($_project_fs, strlen($_doc_root));
if ($base_url === '' || $base_url === false) $base_url = '';
// S'assurer d'un slash final
if (substr($base_url, -1) !== '/') $base_url .= '/';

$assets = $base_url . 'assets/';

// Substitution de {base} dans les hrefs des actions
foreach ($actions as &$_a) {
    $_a['href'] = str_replace('{base}', $base_url, $_a['href']);
}
unset($_a);

$detail = $detail ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($code) ?> — <?= htmlspecialchars($title) ?> — Pixivorama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;1,300&family=Josefin+Sans:wght@200;300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($assets) ?>styles.css">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($assets) ?>logo.png">
</head>
<body>

<div class="error-page">

    <p class="site-label">Pixivorama</p>
    <span class="error-code"><?= htmlspecialchars($code) ?></span>
    <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
    <span class="error-sep"></span>
    <p class="error-desc"><?= $desc /* HTML contrôlé, jamais d'entrée utilisateur brute */ ?></p>

    <div class="error-actions">
        <?php foreach ($actions as $action): ?>
        <a href="<?= htmlspecialchars($action['href']) ?>"
           class="error-btn <?= !empty($action['primary']) ? 'error-btn-primary' : 'error-btn-secondary' ?>">
            <?= htmlspecialchars($action['label']) ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($detail !== ''): ?>
    <div class="error-detail">
        <button class="error-detail-toggle" id="detailToggle">Détails techniques</button>
        <div class="error-detail-box" id="errDetail"><?= htmlspecialchars($detail) ?></div>
    </div>
    <script>
        document.getElementById('detailToggle').addEventListener('click', function () {
            var box = document.getElementById('errDetail');
            box.classList.toggle('visible');
            this.textContent = box.classList.contains('visible')
                ? 'Masquer les détails'
                : 'Détails techniques';
        });
    </script>
    <?php endif; ?>

</div>

</body>
</html>
