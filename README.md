# Documentation technique — SecureScan

## 0. Installation et lancement

### Prérequis

- **Docker** et **Docker Compose** installés
- Un compte **GitHub** avec une [OAuth App](https://github.com/settings/developers) configurée :
  - Homepage URL : `http://localhost:3000`
  - Authorization callback URL : `http://localhost:3000/api/auth/github/callback`
- *(Optionnel)* Une clé API **Groq** pour la génération de correctifs IA

### Installation

```bash
# 1. Cloner le projet
git clone https://github.com/Saar45/SecureScan.git
cd SecureScan

# 2. Configurer les variables d'environnement
cp .env.example .env
```

Éditer le fichier `.env` et renseigner les valeurs suivantes :

#### MySQL

| Variable | Description |
|----------|-------------|
| `MYSQL_ROOT_PASSWORD` | Mot de passe root — choisir librement (ex. `rootpass`) |
| `MYSQL_DATABASE` | Nom de la base — laisser `security_scanner` par défaut |
| `MYSQL_USER` | Nom d'utilisateur — choisir librement (ex. `scanner`) |
| `MYSQL_PASSWORD` | Mot de passe utilisateur — choisir librement |

> Ces variables configurent le conteneur Docker MySQL local. Vous pouvez choisir les valeurs que vous voulez.

#### Symfony

| Variable | Description |
|----------|-------------|
| `APP_ENV` | Laisser `dev` pour le développement local |
| `APP_SECRET` | Chaîne aléatoire — générer avec : `openssl rand -hex 16` |
| `CORS_ALLOW_ORIGIN` | Laisser `http://localhost:3000` pour le développement local |

#### GitHub OAuth (obligatoire)

Ces identifiants permettent la connexion via GitHub. Pour les obtenir :

1. Aller sur **GitHub** → **Settings** → **Developer settings** → **OAuth Apps** → **New OAuth App**
2. Remplir le formulaire :
   - **Application name** : `SecureScan` (ou autre)
   - **Homepage URL** : `http://localhost:3000`
   - **Authorization callback URL** : `http://localhost:3000/api/auth/github/callback`
3. Cliquer sur **Register application**
4. Copier le **Client ID** → `GITHUB_CLIENT_ID`
5. Cliquer sur **Generate a new client secret** → copier la valeur → `GITHUB_CLIENT_SECRET`

| Variable | Description |
|----------|-------------|
| `GITHUB_CLIENT_ID` | Client ID de l'OAuth App créée ci-dessus |
| `GITHUB_CLIENT_SECRET` | Client Secret généré ci-dessus |

#### Git Token (optionnel)

Token de secours pour les commandes CLI (quand aucune session OAuth n'est active).

1. Aller sur **GitHub** → **Settings** → **Developer settings** → **Personal access tokens** → **Tokens (classic)**
2. Cliquer sur **Generate new token (classic)**
3. Cocher le scope **repo** (accès complet aux dépôts)
4. Copier le token → `GIT_TOKEN`

#### Groq AI (optionnel)

Clé API pour la génération de correctifs par IA (Llama 3.3 70B via Groq).

1. Créer un compte sur **https://console.groq.com**
2. Aller dans **API Keys** → **Create API Key**
3. Copier la clé → `GROQ_API_KEY`

#### Frontend

| Variable | Description |
|----------|-------------|
| `VITE_API_URL` | URL de l'API backend — laisser `http://localhost:8080` pour le développement local |

### Lancement

```bash
# 3. Démarrer tous les services
docker compose up --build

# Ou en arrière-plan
docker compose up -d --build
```

Les migrations de base de données s'exécutent automatiquement au démarrage du conteneur backend.

### Accès

| Service | URL |
|---------|-----|
| Frontend | http://localhost:3000 |
| Backend (API) | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

### Commandes utiles

```bash
# Lancer un scan depuis le terminal
docker compose exec backend php bin/console app:full-pipeline <repo-url> [project-name]

# Tester un scan sans intégration Git
docker compose exec backend php bin/console app:test-scan <repo-url> [name]

# Exécuter les tests
docker compose exec backend composer test

# Arrêter les services
docker compose down
```

---

## 1. Vue d'ensemble

**SecureScan** est une plateforme de scan de sécurité automatisé pour dépôts Git. L'application clone un dépôt, exécute plusieurs outils d'analyse (Semgrep, TruffleHog, audits npm/composer), normalise les résultats, calcule un score global, puis peut créer une branche de correction et générer un rapport PDF.

### 1.1 Architecture globale

- **Backend** : API REST Symfony 6.4 (PHP 8.1+), exposée sous `/api`.
- **Frontend** : SPA React 19 (TypeScript, Vite 7) consommant l'API.
- **Base de données** : MySQL 8.0 (Doctrine ORM).
- **Conteneurisation** : Docker Compose (backend, frontend, MySQL, phpMyAdmin).

### 1.2 Stack technique

| Composant   | Technologie                                      |
|------------|---------------------------------------------------|
| Backend    | Symfony 6.4, PHP 8.1+, Apache                    |
| Frontend   | React 19, TypeScript 5.9, Vite 7                 |
| Base de données | MySQL 8.0                                    |
| Auth       | GitHub OAuth 2.0 (session)                       |
| API        | REST JSON, CORS (Nelmio)                         |
| PDF        | DomPDF + Twig                                    |

---

## 2. Structure du projet

```
SecureScan/
├── backend/                 # API Symfony
│   ├── config/              # Configuration (bundles, routes, packages)
│   ├── migrations/          # Migrations Doctrine
│   ├── public/              # Point d'entrée (index.php)
│   ├── src/
│   │   ├── Controller/      # Contrôleurs REST
│   │   ├── Entity/          # Entités Doctrine
│   │   ├── Repository/      # Repositories
│   │   ├── Security/        # Authentification GitHub
│   │   └── Service/         # Logique métier
│   ├── tests/               # Tests PHPUnit (unit + functional)
│   ├── Dockerfile
│   └── composer.json
├── frontend/                # SPA React
│   ├── src/
│   │   ├── api/             # Client Axios + modules API
│   │   ├── components/      # Layout, ProtectedRoute
│   │   ├── context/         # AuthContext
│   │   ├── pages/           # Pages (Login, Dashboard, Scan, etc.)
│   │   └── main.tsx, App.tsx
│   ├── Dockerfile
│   └── package.json
├── docs/                    # Documentation (features, technique)
├── docker-compose.yml
├── .env.example
└── README.md
```

---

## 3. Backend (Symfony)

### 3.1 Point d'entrée et noyau

- **Entry** : `backend/public/index.php`
- **Kernel** : `backend/src/Kernel.php`
- **Routes** : attributs PHP 8 sur les contrôleurs (`config/routes.yaml` → `../src/Controller/`).

### 3.2 Bundles principaux

- `FrameworkBundle`, `SecurityBundle`, `TwigBundle`
- `DoctrineBundle`, `DoctrineMigrationsBundle`
- `NelmioCorsBundle` (CORS)
- `MonologBundle`, `MakerBundle` (dev)

### 3.3 Modèle de données (Doctrine)

#### Entités

| Entité       | Table           | Rôle |
|-------------|-----------------|------|
| **User**    | `users`         | Utilisateur GitHub OAuth (github_id, username, avatar_url, github_token chiffré). |
| **Project** | `projects`      | Projet à scanner (name, repository_url, main_branch, owner → User). |
| **Scan**    | `scans`         | Exécution d’un scan (project, executed_at, global_score, status, workdir). |
| **Finding** | `findings`      | Une vulnérabilité (tool_source, severity, owasp_category, file_path, line_number, description, raw_code ; Remediation optionnelle). |
| **Remediation** | `remediations` | Correction proposée (proposed_fix, status, git_branch_name, pr_url). |

#### Référentiel OWASP

- Table `owasp_categories` (code, name, description), pré-remplie A01–A10 (migrations).
- Pas d’entité Doctrine dédiée ; `findings.owasp_category` est une clé étrangère vers ce code.

#### Relations

```
User (1) ──→ (N) Project   (owner)
Project (1) ──→ (N) Scan
Scan (1) ──→ (N) Finding
Finding (1) ──→ (0..1) Remediation
```

- Clés primaires : UUID (CHAR(36)) pour User, Project, Scan, Finding ; Remediation a pour PK `finding_id`.

### 3.4 API REST

Toutes les routes sont préfixées par `/api`. Sauf `/api/auth/*`, l’accès nécessite un utilisateur authentifié (`ROLE_USER`).

#### Authentification — `AuthController` (`/api/auth`)

| Méthode | Route | Description |
|--------|--------|-------------|
| GET | `/github` | Redirection vers GitHub OAuth. |
| GET | `/github/callback` | Callback OAuth (création/mise à jour User, session). |
| GET | `/me` | Utilisateur courant (JSON). |
| GET | `/github/repos` | Liste des dépôts GitHub de l’utilisateur. |
| POST | `/logout` | Déconnexion. |

#### Projets — `ProjectController` (`/api/projects`)

| Méthode | Route | Description |
|--------|--------|-------------|
| GET | `` | Liste des projets de l’utilisateur connecté. |
| POST | `` | Création d’un projet (name, repositoryUrl, etc.). |
| GET | `/{id}` | Détail d’un projet (avec scans). |

#### Scans — `ScanController` (`/api/scans`)

| Méthode | Route | Description |
|--------|--------|-------------|
| POST | `` | Créer un scan : body `repositoryUrl` ou `projectId`. |
| POST | `/from-archive` | Créer un scan à partir d’une archive ZIP (multipart). |
| GET | `/recent` | Derniers scans (ex. 10). |
| GET | `/{id}` | Détail d’un scan (avec findings). |
| GET | `/{id}/findings` | Liste des findings (query : severity, tool, owasp). |
| POST | `/{id}/apply-fixes` | Appliquer les corrections (branche Git + PR). |
| GET | `/{id}/report` | Téléchargement du rapport PDF. |

### 3.5 Sécurité

- **Provider** : `App\Entity\User` identifié par `githubId`.
- **Firewall** `main` : authentification via `App\Security\GitHubAuthenticator`, session (non stateless).
- **CORS** : origine autorisée via `CORS_ALLOW_ORIGIN` (ex. `http://localhost:3000`).
- **Contrôle d’accès** : `/api/auth` en accès public ; tout le reste sous `/api` exige `ROLE_USER`.
- **Projets** : rattachés à un `owner` (User) ; vérification d’ownership pour éviter les accès non autorisés (IDOR).

### 3.6 Services métier

| Service | Rôle |
|--------|------|
| **ScanManager** | Clone du dépôt, exécution des outils (Semgrep, TruffleHog, npm/composer audit), normalisation en Finding/Remediation, calcul du score, persistance du Scan. |
| **GitIntegrationService** | Application des correctifs, création de branche, push, ouverture de PR (token GitHub utilisateur). |
| **ReportGenerator** | Génération du rapport PDF à partir d’un Scan (template Twig → DomPDF). |
| **AiFixService** | Proposition de correctifs (ex. Groq) pour les findings. |
| **TokenEncryptor** | Chiffrement/déchiffrement du token GitHub pour le stockage. |

### 3.7 Commandes CLI

| Commande | Usage |
|----------|--------|
| `app:test-scan <url> [name]` | Lancer un scan depuis l’URL (et optionnellement un nom). |
| `app:git-integration <scan-id>` | Appliquer les remédiations et générer le rapport. |
| `app:full-pipeline <repo-url> [project-name]` | Pipeline complet (scan + intégration Git). |

---

## 4. Frontend (React)

### 4.1 Stack

- **React** 19, **TypeScript** 5.9, **Vite** 7
- **React Router** 7, **TanStack React Query** 5, **Axios**
- **Recharts** (graphiques), **Tailwind CSS** 4

### 4.2 Entrée et routage

- **Entry** : `frontend/src/main.tsx` — `BrowserRouter`, `QueryClientProvider`, `AuthProvider`, puis `App`.
- **Routes** (`App.tsx`) :
  - `/login` → `LoginPage`
  - `/dashboard` → `ProtectedRoute` + `Layout` + `DashboardPage`
  - `/scan/new` → `NewScanPage`
  - `/scan/:id` → `ScanResultsPage`
  - `*` → redirection vers `/dashboard`

### 4.3 Pages

| Page | Fichier | Rôle |
|------|---------|------|
| Login | `pages/LoginPage.tsx` | Connexion « Sign in with GitHub » (redirection backend OAuth). |
| Dashboard | `pages/DashboardPage.tsx` | Liste des projets et scans récents. |
| Nouveau scan | `pages/NewScanPage.tsx` | Création de scan (URL dépôt ou upload ZIP). |
| Résultats scan | `pages/ScanResultsPage.tsx` | Détail d’un scan : findings, application des correctifs, rapport PDF. |

### 4.4 Composants

- **Layout** : Enveloppe de l’application (navigation, outlet).
- **ProtectedRoute** : Redirige les utilisateurs non authentifiés vers `/login`.

### 4.5 État et API

- **Auth** : `context/AuthContext.tsx` — `user`, `loading`, `logout` ; au chargement, appel à `GET /api/auth/me`.
- **Client HTTP** : `api/client.ts` — instance Axios `baseURL: '/api'`, `withCredentials: true` ; en cas de 401, redirection vers `/login`.
- **Modules API** : `api/auth.ts`, `api/projects.ts`, `api/scans.ts` (createScan, uploadScanArchive, fetchScan, fetchRecentScans, applyFixes, fetchReport).

### 4.6 Build et dev

- **Vite** : `vite.config.ts` — port 3000 ; en dev, proxy `/api` vers le backend (ex. `http://backend:80` en Docker).
- **Variable d’environnement** : `VITE_API_URL` pour l’URL de l’API au build si besoin.

---

## 5. Base de données

- **Moteur** : MySQL 8.0 (Doctrine DBAL).
- **Migrations** : `backend/migrations/` (Version*.php).
  - Création des tables `owasp_categories`, `users`, `projects`, `scans`, `findings`, `remediations` et seed OWASP A01–A10.
  - Ajout de `scans.workdir`, `projects.user_id` (owner).

En développement avec Docker, les migrations sont exécutées au démarrage du conteneur backend (`backend/docker/entrypoint.sh`).

---

## 6. Docker

### 6.1 Services

| Service   | Port(s) | Image/Context      | Rôle |
|----------|---------|--------------------|------|
| backend  | 8080→80 | Build `./backend`  | API Symfony (Apache). |
| frontend | 3000    | Build `./frontend` | SPA React (Vite). |
| mysql    | 3306    | mysql:8.0          | Base MySQL. |
| phpmyadmin | 8081  | phpmyadmin:5.2     | Interface d’admin BDD. |

### 6.2 Volumes

- `mysql_data` : données MySQL.
- `repos_data` : dépôts clonés (backend).
- Montages de code : `./backend` et `./frontend` pour le dev.

### 6.3 Variables d’environnement

Transmises via le fichier `.env` (copie de `.env.example`) :

- **MySQL** : `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`.
- **Symfony** : `APP_ENV`, `APP_SECRET`, `DATABASE_URL` (construite dans compose).
- **CORS** : `CORS_ALLOW_ORIGIN`.
- **GitHub OAuth** : `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, optionnel `GITHUB_CALLBACK_URL`.
- **Optionnel** : `GIT_TOKEN`, `GROQ_API_KEY`, `VITE_API_URL`.

---

## 7. Tests

- **Emplacement** : `backend/tests/`.
- **Exécution** : `composer test` ou `php vendor/bin/phpunit` (SQLite en mémoire possible) ; dans Docker : `docker compose exec backend composer test`.
- **Unitaires** : ex. `Unit/Service/ReportGeneratorTest.php` (génération PDF).
- **Fonctionnels** : ex. `Functional/Controller/ScanControllerTest.php` (création scan, from-archive), `KernelTest.php` (réponse `/api/scans/recent`).

---

## 8. Documentation complémentaire

### Documents

| Document | Contenu |
|----------|---------|
| [Documentation Utilisateur](docs/Documentation%20Utilisateur.pdf) | Guide utilisateur complet (PDF). |
| [Rapport de sécurité (exemple)](docs/Rapport_de_sécurité_généré_exemple.pdf) | Exemple de rapport PDF généré par SecureScan. |
| [feature-scan-pipeline.md](docs/feature-scan-pipeline.md) | Pipeline de scan (Semgrep, TruffleHog, audits). |
| [feature-git-integration.md](docs/feature-git-integration.md) | Intégration Git et rapport PDF. |
| [feature-oauth.md](docs/feature-oauth.md) | Authentification GitHub OAuth. |
| [DOCUMENTATION_TECHNIQUE.md](docs/DOCUMENTATION_TECHNIQUE.md) | Documentation technique détaillée. |
| [MCD.png](docs/MCD.png) | Modèle conceptuel des données. |

### Diagrammes

| Diagramme | Description |
|-----------|-------------|
| [Cas d'utilisation](docs/diagrammes/Diagramme%20de%20Cas%20d'Utilisation%20(Use%20Case).png) | Diagramme Use Case — acteurs et fonctionnalités. |
| [Classes](docs/diagrammes/Diagramme%20de%20Classes%20(Structure).png) | Diagramme de classes — entités et relations. |
| [Activité](docs/diagrammes/Diagramme%20d'Activité%20(Workflow).png) | Diagramme d'activité — workflow du scan. |
| [Séquence](docs/diagrammes/Diagramme%20de%20séquence.png) | Diagramme de séquence — flux OAuth et scan. |

---



## 9 Résumé des flux

1. **Authentification** : Utilisateur → « Sign in with GitHub » → redirect OAuth → callback backend → session → `GET /api/auth/me` pour le frontend.
2. **Création de scan** : Frontend envoie `POST /api/scans` (repositoryUrl ou projectId) ou `POST /api/scans/from-archive` → ScanManager clone/exécute les outils → findings + score → réponse avec `id` du scan.
3. **Consultation** : `GET /api/scans/{id}` et `GET /api/scans/{id}/findings` pour afficher les résultats.
4. **Correctifs** : `POST /api/scans/{id}/apply-fixes` → GitIntegrationService (branche + PR).
5. **Rapport** : `GET /api/scans/{id}/report` → ReportGenerator → PDF téléchargé.

---

*Document généré pour le projet SecureScan — documentation technique v1.*
