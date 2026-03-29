# Pixivorama

Galerie légère pour parcourir des illustrations Pixiv par tags, sans compte requis côté visiteur.

![capture](https://concepts.esenjin.xyz/cyla/fichiers/69c18d372fb4f_1774292279.jpg)

## Fonctionnement

Un proxy PHP interroge l'API interne de Pixiv via un cookie de session (PHPSESSID). Les vignettes sont servies par [i.pixiv.re](https://i.pixiv.re) pour contourner la restriction de référent. Aucune image n'est hébergée localement.

Chaque illustration redirige vers sa page Pixiv originale, générant du trafic vers la plateforme et permettant aux visiteurs d'interagir avec l'œuvre.

## Prérequis

- PHP 7.4+ avec l'extension `curl`
- Un compte Pixiv (pour récupérer le PHPSESSID)
- Un serveur web avec `.htaccess` (Apache / LiteSpeed)

## Installation

1. Déposer les fichiers sur le serveur.
2. Se connecter sur [pixiv.net](https://www.pixiv.net), puis récupérer le cookie `PHPSESSID` via les outils développeur (F12 → Application → Cookies).
3. Accéder à `/admin.php` (mot de passe par défaut : `admin`) et renseigner le PHPSESSID.
4. Créer les galeries souhaitées depuis l'interface d'administration.

> Note : Si le projet est installé dans un sous-dossier, ajuster les chemins `ErrorDocument`  dans `.htaccess` en conséquence.

## Structure
```
index.php                   Page d'accueil — mosaïque dynamique de toutes les galeries
admin.php                   Interface d'administration
perso.php                   Espace personnel (galeries privées, admin uniquement)
pixiv-proxy.php             Proxy public → API Pixiv (galeries par tags)
private-proxy.php           Proxy privé → API Pixiv (données personnelles, admin uniquement)
pixiv-check.php             Vérification de la validité du cookie Pixiv
config.php                  Configuration et fonctions utilitaires
settings.json               Réglages dynamiques — ignoré par git, protégé par .htaccess

galleries/
  _template.php             Template des pages de galerie publique
  recherche.php             Recherche libre par tag (publique)
  {slug}.php                Pages de galerie générées (copiées depuis _template.php)
  {slug}.json               Données de chaque galerie (titres, personnages, tags)

private/
  _template.php             Template des galeries privées par tags
  _special.php              Template des galeries spéciales (illust, bookmark, following)
  {slug}.php                Pages de galerie privée générées
  {slug}.json               Données des galeries privées

assets/
  styles.css                Feuille de style principale (importe les partiels CSS)
  scripts.js                Logique JS des galeries publiques
  scripts-special.js        Logique JS des galeries spéciales (espace perso)

errors/
  403.php / 404.php         Pages d'erreur personnalisées
  500.php / 503.php
  _base.php                 Template commun des pages d'erreur
```

## Galeries publiques

Les galeries publiques sont accessibles à tous les visiteurs. Chaque galerie regroupe un ou plusieurs tags Pixiv sous un titre commun.

- Création, modification, suppression et réorganisation par glisser-déposer depuis l'administration
- Lien personnalisé optionnel dans le pied de page de chaque galerie
- Page d'accueil avec mosaïque dynamique et carousel d'aperçus par galerie

## Espace personnel (galeries privées)

Accessible uniquement après connexion à l'administration, l'espace perso propose deux catégories de galeries privées.

### Galeries privées par tags

Identiques aux galeries publiques, mais réservées à l'administrateur.

### Galeries spéciales

Accès direct aux données Pixiv du compte connecté via le cookie de session :

| Type | Description | 
|--------|--------|
| Mes illustrations | Toutes les illustrations publiées sur le compte |
| Mes bookmarks | Illustrations mises en favori |
| Artistes suivis | Dernières publications des abonnements |

La galerie Artistes suivis dispose d'un système de suivi des nouveautés : les illustrations non encore vues sont marquées d'un badge *Nouveau*, avec un bouton pour les marquer toutes comme vues. L'état est conservé en `localStorage` avec une durée de vie de 90 jours.

## Recherche libre

La page `galleries/recherche.php` permet de rechercher n'importe quel tag Pixiv public, sans restriction. Les mêmes filtres sont disponibles.

## Filtres disponibles

- Tri par popularité (avec filtre de période : 24h, 7j, 1 mois, 6 mois, 1 an, tous)
- Nombre d'illustrations par page : 28, 56
- Contenu 18+ (toggle, galeries publiques et recherche libre uniquement)
- Illustrations IA exclues par défaut

## Administration

L'interface (`admin.php`) est organisée en trois onglets :

- **Session Pixiv** — saisie du PHPSESSID avec vérification en temps réel et affichage du pseudo Pixiv associé
- **Galeries** — création, édition, suppression et réorganisation des galeries publiques
- **Options** — personnalisation de la page d'accueil (titre, sous-titre, lien pied de page) et changement du mot de passe administrateur

## Sécurité

- `settings.json` et tous les fichiers `.json` sont protégés par `.htaccess` (accès direct refusé)
- Le cookie PHPSESSID ne quitte jamais le serveur
- Les galeries privées et l'espace perso nécessitent une session administrateur active (durée : 7 jours)
- Les slugs sont validés (`[a-z0-9\-]{1,20}`) avant tout accès au système de fichiers

## Notes

Ce projet est une vitrine personnelle. Il ne stocke aucune image et génère du trafic vers Pixiv. Respectez les conditions d'utilisation de la plateforme.

## Crédits

Créé avec l'aide de [Claude.ai](https://claude.ai).