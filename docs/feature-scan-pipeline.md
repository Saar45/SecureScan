# Feature : Pipeline de scan de sécurité

Plateforme de scan de sécurité automatisé pour dépôts Git.
SecureScan clone un dépôt, exécute plusieurs outils d'analyse (Semgrep, TruffleHog, audit de dépendances npm/composer), normalise les résultats (sévérité, catégorie OWASP) et calcule un score global, le tout stocké en base via Doctrine.

## Vue d'ensemble fonctionnelle

- **Objectif principal**: donner une vision rapide de la santé sécurité d'un dépôt applicatif.
- **Entrée**: une URL de dépôt Git (HTTP(S) ou SSH) passée à la commande Symfony.
- **Pipeline**:
  - clonage isolé du dépôt dans `/tmp/scans/<uuid>`,
  - détection de l'écosystème (npm / composer),
  - exécution des scanners:
    - **Semgrep** pour l'analyse de code,
    - **TruffleHog** pour la détection de secrets,
    - **npm audit** ou **composer audit** pour les dépendances,
  - création d'entités `Scan`, `Finding`, `Remediation`,
  - calcul d'un **score global sur 100**.
- **Sortie**: un `Scan` persistant (en base) avec ses findings, accessible ensuite par l'API / backend.

## Architecture globale

- **Backend**: Symfony 6.4 (PHP 8.4, Doctrine ORM, Console, Process, UID).
  - Localisé dans `backend/`.
  - Expose une commande `app:test-scan` pour lancer un scan complet depuis le terminal.
  - Persiste `Project`, `Scan`, `Finding`, `Remediation` dans une base MySQL.
- **Frontend**: application JavaScript (Vite) consommant l'API du backend.
  - Localisée dans `frontend/`.
  - Utilise la variable d'environnement `VITE_API_URL` pour joindre le backend.
- **Base de données**:
  - **MySQL** (via `docker-compose.yml`) pour l'application.
  - Un **PostgreSQL** de support est aussi défini côté Symfony (`backend/compose.yaml`) pour les besoins Doctrine standard, mais la stack principale est centrée sur MySQL avec Docker racine.
- **Outils de sécurité intégrés dans l'image backend** (voir `backend/Dockerfile`):
  - **Semgrep** installé via `pip3` et disponible en `/usr/local/bin/semgrep`.
  - **TruffleHog** installé via script officiel dans `/usr/local/bin/trufflehog`.
  - **npm** (pour `npm audit`).

---

## Prérequis

- **Docker** et **Docker Compose** installés et fonctionnels.
- Accès réseau au dépôt Git que vous voulez scanner (GitHub, GitLab, etc.).
- Ports disponibles:
  - `8080` (backend Symfony derrière Apache),
  - `3000` (frontend),
  - `3306` (MySQL),
  - `8081` (phpMyAdmin).

---

## Configuration des variables d'environnement

Les fichiers `.env` racine et backend contiennent la configuration principale.

### Racine du projet (`.env`)

- **MySQL**
  - `MYSQL_ROOT_PASSWORD`: mot de passe root MySQL.
  - `MYSQL_DATABASE`: nom de la base applicative (ex: `security_scanner`).
  - `MYSQL_USER` / `MYSQL_PASSWORD`: utilisateur applicatif et mot de passe.
- **Symfony**
  - `APP_ENV`: environnement Symfony (`dev` par défaut).
  - `APP_SECRET`: secret d'application (doit être remplacé en prod).
  - `CORS_ALLOW_ORIGIN`: origine autorisée pour le frontend (ex: `http://localhost:3000`).
  - `DATABASE_URL`: URL de connexion MySQL (utilisée dans le container backend).
- **Frontend**
  - `VITE_API_URL`: URL publique de l'API backend (ex: `http://localhost:8080`).

### Backend (`backend/.env`)

Ce fichier recopie l'essentiel de la configuration (MySQL + Symfony + frontend) mais du point de vue du backend. Assurez‑vous d'avoir des valeurs cohérentes entre le `.env` racine et celui du `backend` si vous les modifiez.

---

## Lancement rapide avec Docker

Depuis la racine du projet (`SecureScan/` contenant `docker-compose.yml`) :

```bash
docker-compose up --build
```

- **backend**:
  - construit à partir de `backend/Dockerfile`,
  - exposé sur `http://localhost:8080`.
- **frontend**:
  - construit à partir de `frontend/Dockerfile`,
  - exposé sur `http://localhost:3000`.
- **mysql**:
  - exposé sur `localhost:3306`,
  - données persistées dans le volume `mysql_data`.
- **phpmyadmin**:
  - disponible sur `http://localhost:8081` (pratique pour inspecter la base).

Arrêt de la stack:

```bash
docker-compose down
```

---

## Commande de test de scan (`TestScanCommand`)

Fichier: `backend/src/Command/TestScanCommand.php`

Cette commande Symfony encapsule tout le pipeline de scan pour un usage direct depuis le terminal.

- **Nom de la commande**: `app:test-scan`
- **Arguments**:
  - `url` (requis): URL du dépôt Git à scanner.
  - `name` (optionnel): nom du projet dans la base (sinon, un nom par défaut est généré).
- **Comportement**:
  1. Recherche un `Project` existant avec `repositoryUrl = url`.
  2. S'il n'existe pas, crée un nouveau `Project` (nom + URL) et le persiste.
  3. Affiche dans la console si le projet est **créé** ou **réutilisé**.
  4. Appelle `ScanManager::startScan($project)` pour lancer tout le pipeline.
  5. Affiche un résumé du scan:
     - ID du scan,
     - statut (`completed` ou `failed`),
     - score global,
     - nombre de findings.
  6. Gère les exceptions et renvoie un code de sortie Symfony (`SUCCESS` ou `FAILURE`).

### Exemple d'utilisation (dans le container backend)

Depuis la racine du projet:

```bash
docker-compose exec backend php bin/console app:test-scan https://github.com/mon-org/mon-repo.git "Mon projet sécurisé"
```

---

## Le cœur du pipeline: `ScanManager`

Fichier: `backend/src/Service/ScanManager.php`

`ScanManager` est le **cerveau** technique du scan. Il:

1. **Crée un `Scan`** rattaché au `Project` fourni, avec le statut initial `running`.
2. **Prépare un répertoire de travail isolé** dans `/tmp/scans/<uuid>`:
   - chaque scan a son propre dossier (via `Uuid::v4()`),
   - cela évite les collisions entre exécutions parallèles.
3. **Clone le dépôt Git** dans ce répertoire:
   - via la classe Symfony `Process`,
   - utilise `git clone --depth 1` pour un clone rapide,
   - laisse Git choisir la branche par défaut (pas de `--branch` forcé).
4. **Détecte l'outil de dépendances**:
   - `package.json` → `npm`,
   - `composer.json` → `composer`,
   - aucun des deux → pas d'audit de dépendances.
5. **Exécute les scanners** (chaque outil est isolé via `runToolSafely()` — si un outil plante, les autres continuent) :
   - `runSemgrep()`:
     - lance `python3 /usr/local/bin/semgrep --config auto --json`,
     - accepte les codes de sortie 0 (aucun finding) et 2 (findings trouvés / fichiers ignorés),
     - récupère la sortie JSON.
   - `runTrufflehog()`:
     - lance `/usr/local/bin/trufflehog filesystem <workdir> --json --no-update`,
     - `--no-update` empêche la mise à jour automatique (permission denied sous www-data),
     - tolère un code de sortie non-zero si du JSON a été produit.
   - `runDependencyAudit()`:
     - pour **npm**: `/usr/bin/npm audit --json`
       - gère le cas particulier où le code de sortie `1` signifie "vulnérabilités trouvées" (et non une erreur technique),
     - pour **composer**: `/usr/bin/composer audit --format=json --no-interaction`
       - même logique : code de sortie `1` = vulnérabilités trouvées, pas une erreur.
   - Toutes les commandes reçoivent un environnement explicite (`HOME=/var/www`, `COMPOSER_HOME`, `npm_config_cache`, etc.) pour fonctionner sous Apache (www-data).
6. **Transforme les résultats en entités métier**:
   - `processSemgrepResults()`:
     - crée un `Finding` par résultat Semgrep,
     - normalise la sévérité (`normalizeSeverity()`),
     - renseigne le chemin de fichier, la ligne, le message, le code brut,
     - tente de déduire une **catégorie OWASP** via `mapToOwaspCategoryFromSemgrep()`.
   - `processTrufflehogResults()`:
     - crée un `Finding` par secret potentiel,
     - sévérité forcée à `HIGH`,
     - catégorie OWASP fixée à `A04` (Cryptographic Failures / secrets exposés).
   - `processDependencyResults()`:
     - supporte:
       - le **nouveau format** `npm audit` (`vulnerabilities`),
       - l'**ancien format** (`advisories`),
       - le format JSON de `composer audit`,
     - crée un `Finding` associé au fichier `package.json` ou `composer.json`,
     - mappe chaque advisory vers une catégorie OWASP via `mapToOwaspCategoryFromDependency()`.
7. **Génère des recommandations automatiques**:
   - `maybeCreateRemediation()`:
     - crée une entité `Remediation` pour chaque finding, avec un texte adapté à sa catégorie OWASP 2025,
     - associe un texte de correction générique mais actionnable (ex: requêtes paramétrées pour A05, gestion des secrets pour A04, mise à jour des dépendances pour A03).
8. **Calcule le score global**:
   - `computeScore()`:
     - part d'un score de base `100`,
     - applique une pénalité par finding via `penaltyForSeverity()`:
       - CRITICAL: -20,
       - HIGH: -10,
       - MEDIUM: -5,
       - LOW: -2,
       - défaut: -1,
     - borne le résultat entre 0 et 100,
     - enregistre ce score au format `xx.xx`.
9. **Gestion des erreurs**:
   - Chaque outil est exécuté dans `runToolSafely()` : si un outil plante, l'erreur est loguée et le scan continue avec les résultats des autres outils.
   - Si le clone Git échoue, le scan entier est marqué `failed`.
   - Les erreurs sont loguées via le `LoggerInterface` Symfony (fichier `var/log/dev.log`) et aussi sur `STDERR` pour le mode CLI.
   - Le `Scan` reste en base dans tous les cas pour faciliter le diagnostic.
   - Semgrep peut retourner "requires login" au lieu du code source réel (restriction du registre). Le service lit alors directement le fichier source sur disque via `readSourceLines()` pour récupérer le snippet de code.

---

## Développement backend hors Docker (optionnel)

Si vous disposez d'un environnement PHP local (8.1+), vous pouvez:

1. Installer les dépendances:

```bash
cd backend
composer install
```

2. Lancer le serveur Symfony:

```bash
php -S localhost:8000 -t public
```

3. Lancer la commande de scan:

```bash
php bin/console app:test-scan <url-du-depot>
```

Assurez‑vous dans ce cas que:

- les outils externes (`git`, `semgrep`, `trufflehog`, `npm`, `composer`) sont installés sur votre machine,
- la base de données configurée dans `backend/.env` est accessible.
