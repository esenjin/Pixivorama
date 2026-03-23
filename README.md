# Pixivorama

Galerie légère pour parcourir des illustrations Pixiv par personnage, sans compte requis.

![capture](https://concepts.esenjin.xyz/cyla/fichiers/69c18d372fb4f_1774292279.jpg)

## Fonctionnement

Un proxy PHP interroge l'API interne de Pixiv via un cookie de session (PHPSESSID). Les vignettes sont servies par [i.pixiv.re](https://i.pixiv.re) pour contourner la restriction de référent. Aucune image n'est hébergée localement.

Chaque illustration redirige vers sa page Pixiv originale.

## Prérequis

- PHP 7.4+ avec l'extension `curl`
- Un compte Pixiv (pour récupérer le PHPSESSID)
- Un serveur web avec `.htaccess` (Apache / LiteSpeed)

## Installation

1. Déposer les fichiers sur le serveur.
2. Se connecter sur [pixiv.net](https://www.pixiv.net), puis récupérer le cookie `PHPSESSID` via les outils développeur (F12 → Application → Cookies).
3. Accéder à `/admin.php` (mot de passe par défaut : `admin`) et renseigner le PHPSESSID.
4. Ajouter les personnages souhaités (label affiché + tag Pixiv).

## Structure
```
index.php          Redirige vers galerie.php
galerie.php        Page principale de la galerie
pixiv-proxy.php    Proxy serveur → API Pixiv (le cookie ne quitte jamais le serveur)
admin.php          Interface d'administration
config.php         Chargement des réglages (settings.json)
settings.json      Réglages dynamiques — ignoré par git, protégé par .htaccess
```

## Filtres appliqués

- Tri par popularité ou par date
- Contenu safe par défaut (toggle 18+ disponible)
- Illustrations IA exclues (`ai_type=1`)

## Notes

Ce projet est une vitrine personnelle. Il ne stocke aucune image et génère du trafic vers Pixiv. Respectez les conditions d'utilisation de la plateforme.

## Crédits

Créé avec l'aide de [Claude.ai](https://claude.ai).