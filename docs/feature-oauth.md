# Feature : GitHub OAuth et interface web

Authentification des utilisateurs via GitHub OAuth. Chaque utilisateur se connecte avec son compte GitHub, et son token OAuth est utilisé pour les opérations Git (push, PR). Pas besoin de compte bot ni de token personnel configurable.

---

## Vue d'ensemble

- L'utilisateur clique sur "Sign in with GitHub" sur la page de login.
- Le backend redirige vers GitHub pour l'autorisation (scope `repo user:email`).
- GitHub rappelle le backend avec un `code` d'autorisation.
- Le backend l'échange contre un token OAuth, récupère le profil GitHub, crée ou met à jour l'utilisateur en base.
- L'utilisateur est redirigé vers le dashboard avec une session active.

---

## Fichiers concernés

```
.env.example                                    # GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET
docker-compose.yml                              # Passe les vars OAuth au backend
mysql/init/01-schema.sql                        # Table users
backend/
  composer.json                                 # symfony/security-bundle
  config/
    packages/security.yaml                      # Firewall, provider, access_control
    routes/security.yaml                        # Route de logout
  src/
    Entity/User.php                             # Entité utilisateur (GitHub-only)
    Repository/UserRepository.php               # findByGithubId()
    Security/GitHubAuthenticator.php            # Custom authenticator OAuth
    Controller/
      AuthController.php                        # /api/auth/*
      ProjectController.php                     # /api/projects/*
      ScanController.php                        # /api/scans/*
```

---

## Prérequis

### 1. Créer une GitHub OAuth App

1. Aller sur https://github.com/settings/developers
2. "New OAuth App"
3. Configuration :
   - **Application name** : SecureScan (ou autre)
   - **Homepage URL** : `http://localhost:3000`
   - **Authorization callback URL** : `http://localhost:3000/api/auth/github/callback`
4. Noter le **Client ID** et générer un **Client Secret**.

### 2. Configurer les variables d'environnement

Dans `.env` à la racine du projet :

```
GITHUB_CLIENT_ID=Ov23li...
GITHUB_CLIENT_SECRET=abc123...
```

### 3. Relancer les conteneurs

```bash
docker compose down -v && docker compose up -d --build
```

Le `-v` est nécessaire la première fois pour recréer la base avec la table `users`.

---

## Entité `User`

**Fichier :** `backend/src/Entity/User.php`

Implémente `UserInterface` de Symfony (pas de mot de passe, GitHub-only).

| Champ | Type | Description |
|-------|------|-------------|
| `id` | CHAR(36) UUID | Identifiant unique |
| `githubId` | INT UNIQUE | ID GitHub de l'utilisateur |
| `username` | VARCHAR(255) | Login GitHub |
| `avatarUrl` | VARCHAR(500) | URL de l'avatar |
| `githubToken` | VARCHAR(500) | Token OAuth (mis à jour à chaque login) |
| `createdAt` | DATETIME | Date de création |

- `getRoles()` retourne `['ROLE_USER']`.
- `getUserIdentifier()` retourne le `githubId` (string).

---

## Security config

**Fichier :** `backend/config/packages/security.yaml`

```yaml
security:
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: githubId
    firewalls:
        main:
            lazy: true
            stateless: false
            provider: app_user_provider
            custom_authenticators:
                - App\Security\GitHubAuthenticator
            logout:
                path: /api/auth/logout
                target: /login
    access_control:
        - { path: ^/api/auth, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
```

- `/api/auth/*` est public (login, callback, etc.).
- Tout le reste de `/api/*` nécessite `ROLE_USER`.
- Authentification par session (cookie), pas de JWT.

---

## `GitHubAuthenticator`

**Fichier :** `backend/src/Security/GitHubAuthenticator.php`

Hérite de `AbstractAuthenticator`.

### Flux

1. **`supports()`** : intercepte les requêtes sur `/api/auth/github/callback` avec un paramètre `code`.
2. **`authenticate()`** :
   - Échange le `code` contre un token OAuth via `POST https://github.com/login/oauth/access_token`.
   - Récupère le profil utilisateur via `GET https://api.github.com/user`.
   - Cherche l'utilisateur en base par `githubId`. S'il n'existe pas, le crée.
   - Met à jour le token OAuth et le nom d'utilisateur à chaque connexion.
   - Retourne un `SelfValidatingPassport` (pas de vérification de mot de passe).
3. **`onAuthenticationSuccess()`** : redirige vers `http://localhost:3000/dashboard`.
4. **`onAuthenticationFailure()`** : redirige vers `http://localhost:3000/login?error=auth_failed`.

---

## Contrôleurs API

### `AuthController` (`/api/auth`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/auth/github` | GET | Redirige vers la page d'autorisation GitHub |
| `/api/auth/github/callback` | GET | Géré par l'authenticator (fallback si pas de code) |
| `/api/auth/me` | GET | Retourne les infos de l'utilisateur connecté (ou 401) |
| `/api/auth/logout` | POST | Invalidation de session (géré par Symfony security) |

### `ProjectController` (`/api/projects`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/projects` | GET | Liste tous les projets |
| `/api/projects` | POST | Crée un projet (name + repositoryUrl) |
| `/api/projects/{id}` | GET | Détail d'un projet avec ses scans |

### `ScanController` (`/api/scans`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/scans` | POST | Lance un scan (accepte `repositoryUrl` ou `projectId`) |
| `/api/scans/recent` | GET | 10 derniers scans |
| `/api/scans/{id}` | GET | Détail d'un scan avec findings et remediations |
| `/api/scans/{id}/findings` | GET | Findings filtrable (severity, tool, owasp) |
| `/api/scans/{id}/apply-fixes` | POST | Applique les fixes AI + push + PR avec le token OAuth |
| `/api/scans/{id}/report` | GET | Télécharge le rapport PDF |

---

## Frontend

### Architecture

```
frontend/src/
  api/
    client.ts                 # Instance axios, intercepteur 401
    auth.ts                   # fetchMe(), logout()
    projects.ts               # API projets
    scans.ts                  # API scans
  context/
    AuthContext.tsx            # Appelle /api/auth/me au mount, expose user/loading/logout
  components/
    ProtectedRoute.tsx        # Redirige vers /login si non authentifié
    Layout.tsx                # Sidebar navigation + header avec avatar
  pages/
    LoginPage.tsx             # Page de connexion avec bouton GitHub
    DashboardPage.tsx         # Stats, graphiques recharts, scans récents
    NewScanPage.tsx           # Formulaire URL de dépôt → lance un scan
    ScanResultsPage.tsx       # Score, filtres, tableau de findings, actions
```

### Routes

| Path | Page | Protection |
|------|------|------------|
| `/login` | LoginPage | Public |
| `/dashboard` | DashboardPage | Authentifié |
| `/scan/new` | NewScanPage | Authentifié |
| `/scan/:id` | ScanResultsPage | Authentifié |
| `*` | Redirige vers `/dashboard` | — |

### Fonctionnement de l'auth frontend

1. `AuthContext` appelle `GET /api/auth/me` au chargement.
2. Si 401 → l'utilisateur n'est pas connecté → `user = null`.
3. `ProtectedRoute` vérifie le contexte : si pas d'utilisateur, redirige vers `/login`.
4. Le bouton "Sign in with GitHub" est un simple lien `<a href="/api/auth/github">` (le proxy Vite le forward vers le backend).
5. Après l'OAuth, le backend redirige vers `/dashboard` avec un cookie de session.

### Pages

- **LoginPage** : fond sombre, branding SecureScan, bouton "Sign in with GitHub".
- **DashboardPage** : cartes statistiques (total scans, score moyen, findings), graphique camembert des sévérités (recharts), barres OWASP, liste des scans récents.
- **NewScanPage** : champ URL de dépôt, bouton submit, spinner pendant le scan, redirection vers les résultats.
- **ScanResultsPage** : jauge de score SVG, breakdown par sévérité, filtres (sévérité/outil/OWASP/tri), tableau des findings, bouton "Apply Fixes & Create PR" (affiche URL de la PR), bouton "Download PDF".

---

## Exemple de bout en bout

```bash
# 1. Configurer les variables OAuth dans .env
GITHUB_CLIENT_ID=Ov23li...
GITHUB_CLIENT_SECRET=abc123...

# 2. Démarrer les services
docker compose down -v && docker compose up -d --build

# 3. Ouvrir http://localhost:3000
#    → Redirigé vers /login

# 4. Cliquer "Sign in with GitHub"
#    → Autorisation GitHub → Redirigé vers /dashboard

# 5. Cliquer "New Scan" → Entrer une URL (ex: https://github.com/OWASP/NodeGoat)
#    → Le scan s'exécute → Page de résultats

# 6. Cliquer "Apply Fixes & Create PR"
#    → PR créée sur GitHub avec votre token OAuth
#    → Lien vers la PR affiché
```
