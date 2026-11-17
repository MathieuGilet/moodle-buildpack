# moodle-buildpack

Buildpack [Scalingo](https://scalingo.com) pour déployer [Moodle](https://moodle.org) (>= 5.1)
avec PostgreSQL, Redis (optionnel) et les fichiers stockés sur S3
([Scaleway Object Storage](https://www.scaleway.com/fr/object-storage/)).

L'application déployée sur Scalingo est
[moodle-deploy](https://github.com/MathieuGilet/moodle-deploy). Son fichier
`.buildpacks` déclare, dans cet ordre :

```
https://github.com/MathieuGilet/moodle-buildpack#main
https://github.com/Scalingo/php-buildpack
```

1. **ce buildpack** télécharge Moodle (`MOODLE_VERSION`), installe
   `tool_objectfs`, le `config.php`, la configuration Nginx et les scripts ;
2. **le [buildpack PHP Scalingo](https://github.com/Scalingo/php-buildpack)**
   installe PHP, ses extensions, Nginx et les dépendances Composer de Moodle.

L'ordre est important : le buildpack PHP lit le `composer.json` de Moodle
(version de PHP, extensions, dépendances), qui n'existe qu'après ce buildpack.

L'application doit avoir un `Procfile` : avec plusieurs buildpacks, le process
`web` par défaut serait celui du buildpack PHP (`bin/run`, sans cron ni envoi
vers S3).

```
web: bin/moodle-web
postdeploy: bin/moodle-postdeploy
```

Au premier déploiement, `postdeploy` installe la base de données Moodle ; aux
déploiements suivants, il lance `admin/cli/upgrade.php`.

> **Un seul conteneur web.** Les fichiers déposés sont d'abord écrits sur le
> disque du conteneur puis envoyés sur S3 par ce même conteneur : ne scalez pas
> le process `web` au-delà de 1 conteneur.

## Variables d'environnement

### Build

| Variable | Défaut | Description |
|---|---|---|
| `MOODLE_VERSION` | — (obligatoire) | Tag, branche ou SHA Git de Moodle. Les tags et SHA sont mis en cache. |
| `MOODLE_OBJECTFS_VERSION` | `v2026041013` | Version (ref Git) du plugin [`tool_objectfs`](https://github.com/catalyst/moodle-tool_objectfs). |
| `MOODLE_PHP_VERSION` | `~8.4.0` | Contrainte de version PHP (voir [Version de PHP](#version-de-php)). |
| `MOODLE_UPLOAD_MAX_FILESIZE` | `64M` | `upload_max_filesize` / `post_max_size` (Nginx limite à 75 Mo). |

### Base de données et Redis

Seules les variables fournies par les addons Scalingo sont utilisées :

| Variable | Description |
|---|---|
| `SCALINGO_POSTGRESQL_URL` | Obligatoire (addon PostgreSQL). |
| `SCALINGO_REDIS_URL` | Si présente (addon Redis), les sessions sont stockées dans Redis. |
| `MOODLE_DB_PREFIX` | Préfixe des tables (défaut `mdl_`). |

### Stockage S3 (Scaleway)

Le stockage S3 est activé dès que `MOODLE_S3_BUCKET` est définie.

| Variable | Défaut | Description |
|---|---|---|
| `MOODLE_S3_BUCKET` | — | Nom du bucket. |
| `MOODLE_S3_REGION` | `fr-par` | Région Scaleway (`fr-par`, `nl-ams`, `pl-waw`). |
| `MOODLE_S3_ENDPOINT` | `https://s3.<région>.scw.cloud` | Endpoint S3. |
| `MOODLE_S3_ACCESS_KEY` | — | Access key de la clé API Scaleway. |
| `MOODLE_S3_SECRET_KEY` | — | Secret key de la clé API Scaleway. |
| `MOODLE_S3_KEY_PREFIX` | — | Préfixe des objets (ex. `prod/`) pour partager un bucket. |

La clé API doit pouvoir lister le bucket et lire/écrire ses objets
(`ObjectStorageFullAccess` restreint au projet du bucket, par exemple).
Les fichiers ne sont jamais supprimés du bucket par Moodle.

### Application

| Variable | Défaut | Description |
|---|---|---|
| `MOODLE_WWWROOT` | — (obligatoire) | URL publique, ex. `https://mon-moodle.osc-fr1.scalingo.io`. |
| `MOODLE_DEBUG` | `false` | Active le mode `DEBUG_DEVELOPER`. |
| `MOODLE_SMTP_HOST` | — | `hôte:port` SMTP ; avec `MOODLE_SMTP_USER`, `MOODLE_SMTP_PASSWORD`, `MOODLE_SMTP_SECURE` (`tls`/`ssl`), `MOODLE_SMTP_AUTH_TYPE`. |
| `MOODLE_NOREPLY_ADDRESS` | — | Adresse « noreply ». |
| `MOODLE_CRON_IN_WEB` | `true` | Lance le cron Moodle dans le conteneur web. |
| `MOODLE_CRON_INTERVAL` | `60` | Secondes entre deux exécutions du cron. |
| `MOODLE_CRON_START_DELAY` | `30` | Délai (s) avant le premier cron / envoi S3 après le démarrage. |
| `MOODLE_S3_PUSH_INTERVAL` | `60` | Secondes entre deux envois des nouveaux fichiers vers S3. |
| `MOODLE_S3_SHUTDOWN_TIMEOUT` | `20` | Durée max (s) du dernier envoi vers S3 à l'arrêt du conteneur. |

### Installation automatique (`bin/moodle-postdeploy`)

| Variable | Défaut |
|---|---|
| `MOODLE_ADMIN_PASSWORD` | — (obligatoire pour l'installation) |
| `MOODLE_ADMIN_USER` | `admin` |
| `MOODLE_ADMIN_EMAIL` / `MOODLE_SUPPORT_EMAIL` | — |
| `MOODLE_LANG` | `en` |
| `MOODLE_SITE_FULLNAME` / `MOODLE_SITE_SHORTNAME` | `Moodle` / `moodle` |

## Stockage des fichiers et cron

Le disque des conteneurs Scalingo est éphémère : les fichiers Moodle sont
stockés sur S3 avec le plugin [`tool_objectfs`](https://github.com/catalyst/moodle-tool_objectfs),
installé au build. Il écrit chaque nouveau fichier sur le disque local, puis la
tâche `push_objects_to_storage` l'envoie sur S3 ; la copie locale est supprimée
10 minutes plus tard.

`bin/moodle-web` (process `web`) lance, à côté de Nginx et PHP-FPM :

- **le cron Moodle** (`admin/cli/cron.php`), toutes les minutes ;
- **l'envoi vers S3** (`push_objects_to_storage`, puis `recover_error_objects`),
  toutes les minutes, dans une boucle séparée pour qu'une tâche Moodle longue
  (sauvegarde de cours, …) ne retarde pas l'envoi ;
- **à l'arrêt du conteneur** (SIGTERM : redémarrage, déploiement), un dernier
  envoi vers S3 avant de quitter.

`bin/moodle-postdeploy` tourne dans un conteneur éphémère : après
l'installation ou la mise à jour, il envoie lui-même sur S3 les fichiers
qu'il a créés.

Limites :

- **un seul conteneur web** (voir plus haut) ;
- un fichier déposé moins d'une minute avant un arrêt brutal du conteneur
  (crash, OOM, sans SIGTERM) peut être perdu ;
- les packs de langue installés depuis l'interface sont stockés dans
  `moodledata` et sont perdus au redémarrage ;
- la configuration des caches (MUC) est aussi dans `moodledata` : ne
  configurez pas de stores de cache depuis l'interface.

## Fichiers de l'application

Le buildpack traite ainsi les fichiers du dépôt de l'application :

```
.
├── Procfile               # copié à la racine (obligatoire, voir plus haut)
├── config.local.php       # optionnel : réglages $CFG supplémentaires, inclus par config.php
├── patches/               # optionnel : *.patch appliqués (git apply) sur le code de Moodle
└── moodle/                # optionnel : copié par-dessus l'arborescence de Moodle
    └── public/
        ├── mod/monplugin/
        └── theme/montheme/
```

Les autres fichiers (sauf `.git`, `.gitignore`, `.buildpacks` et `README.md`) sont
copiés à la racine de l'application ; un avertissement
est affiché s'ils remplacent un fichier de Moodle.

## Fonctionnement du buildpack

| Fichier | Rôle |
|---|---|
| `bin/compile` | Télécharge Moodle, applique les patchs, installe `tool_objectfs` et le `config.php`, et prépare l'application pour le buildpack PHP (`composer.json`, configuration Nginx, scripts). |
| `conf/config.php` | `config.php` de Moodle, lu depuis l'environnement. |
| `conf/nginx/moodle.conf.erb` | Configuration Nginx (slash arguments, routeur Moodle, fichiers interdits). |
| `frameworks/moodle` | « Framework » du buildpack PHP qui installe la configuration Nginx. Copié avec `moodle.conf.erb` dans `.moodle-buildpack/` (hors du document root) et référencé par `extra.paas.framework`. |
| `scripts/moodle-postdeploy` | Installation ou mise à jour de la base. |
| `scripts/moodle-web` | Process `web` : Nginx + PHP-FPM, cron Moodle et envoi des fichiers vers S3. |

Le buildpack modifie le `composer.json` de Moodle pour fixer la version de PHP
(`MOODLE_PHP_VERSION`, `~8.4.0` par défaut) et ajouter la section `extra.paas` ; Composer affiche donc un avertissement
« lock file is not up to date », sans conséquence. Il supprime aussi le
`package.json` de Moodle (outillage de développement) pour que le buildpack PHP
n'installe pas Node.js.

## Version de PHP

La version de PHP est figée à `~8.4.0` par défaut, ou à la valeur de la
variable d'environnement `MOODLE_PHP_VERSION` (ex. `~8.3.0`). Elle remplace la
contrainte du `composer.json` de Moodle
(`>=8.3.0` en 5.3), qui n'indique qu'un minimum : sans borne haute, un
redéploiement pourrait passer sur une version de PHP que Moodle ne supporte pas
encore.

- Les versions correctives (8.4.x), dont les correctifs de sécurité, sont
  installées automatiquement à chaque déploiement.
- Le passage à une nouvelle branche de PHP (8.5…) est manuel.

> **À chaque montée de version de Moodle** (`MOODLE_VERSION`), vérifiez les
> versions de PHP supportées dans la
> [politique PHP de Moodle](https://moodledev.io/general/development/policies/php)
> et ajustez `MOODLE_PHP_VERSION` si besoin. La valeur par défaut est
> définie dans `bin/compile`. PHP 8.4 reçoit des correctifs de sécurité
> jusqu'à fin 2028.
