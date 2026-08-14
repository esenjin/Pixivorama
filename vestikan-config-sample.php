<?php
// ============================================================
//  vestikan-config.php.example — Modèle de configuration Vestikan
//
//  COPIER ce fichier en « vestikan-config.php » puis renseigner
//  les valeurs obtenues dans l'admin Vestikan
//  (Sites satellites → enregistrer Pixivorama).
//
//  vestikan-config.php N'EST PAS versionné (voir .gitignore) et
//  n'est PAS servi par le web (voir .htaccess) : il contient le
//  client_secret, qui ne doit jamais fuiter.
//
//  Tant que vestikan-config.php est absent, le bouton
//  « Se connecter avec Vestikan » ne s'affiche pas et le login
//  par mot de passe reste seul actif : l'intégration est donc
//  totalement optionnelle et sans effet tant qu'elle n'est pas
//  configurée.
// ============================================================

return [
    // URL de base de l'IdP Vestikan (sans slash final).
    'base_url'      => 'https://concepts.esenjin.xyz/vestikan',

    // Identifiant public du client (affiché une fois à l'enregistrement).
    'client_id'     => 'vk_client_xxxxxxxxxxxxxxxx',

    // Secret du client (64 hex) — affiché UNE SEULE FOIS à l'enregistrement.
    // À copier immédiatement ; en cas de perte, révoquer et recréer le client.
    'client_secret' => 'xxxxxxxx...(64 caractères hex)...',

    // URL EXACTE de la page de callback, telle qu'enregistrée dans
    // l'admin Vestikan (comparée caractère pour caractère).
    // Adapter le domaine / sous-dossier à votre installation.
    'redirect_uri'  => 'https://concepts.esenjin.xyz/pixivorama/vestikan-callback.php',
];
