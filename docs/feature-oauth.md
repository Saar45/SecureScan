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

**`GET /api/auth/github`** — Redirige vers la page d'autorisation GitHub (302).

**`GET /api/auth/github/callback`** — Géré par l'authenticator. Redirige vers `/dashboard` en cas de succès.

**`GET /api/auth/me`** — Retourne l'utilisateur connecté ou 401.

```json
{
  "id": "3e9346c8-...",
  "username": "Saar45",
  "avatarUrl": "https://avatars.githubusercontent.com/u/...",
  "githubId": 81822359
}
```

**`POST /api/auth/logout`** — Invalidation de session (géré par Symfony security).

---

### `ProjectController` (`/api/projects`)

**`GET /api/projects`** — Liste tous les projets.

```json
[
  {
    "id": "56fed627-...",
    "name": "NodeGoat",
    "repositoryUrl": "https://github.com/OWASP/NodeGoat",
    "mainBranch": "main",
    "createdAt": "2026-03-03T20:50:24+00:00",
    "scanCount": 3
  }
]
```

**`POST /api/projects`** — Crée un projet.

Requête :
```json
{
  "name": "Mon projet",
  "repositoryUrl": "https://github.com/user/repo",
  "mainBranch": "main"  // optionnel
}
```

Réponse (201) :
```json
{
  "id": "...",
  "name": "Mon projet",
  "repositoryUrl": "https://github.com/user/repo",
  "mainBranch": "main",
  "createdAt": "2026-03-03T21:00:00+00:00"
}
```

**`GET /api/projects/{id}`** — Détail d'un projet avec ses scans.

```json
{
  "id": "...",
  "name": "NodeGoat",
  "repositoryUrl": "https://github.com/OWASP/NodeGoat",
  "mainBranch": "main",
  "createdAt": "2026-03-03T20:50:24+00:00",
  "scans": [
    {
      "id": "...",
      "executedAt": "2026-03-03T21:28:01+00:00",
      "globalScore": "95.00",
      "status": "completed",
      "findingsCount": 1
    }
  ]
}
```

---

### `ScanController` (`/api/scans`)

**`POST /api/scans`** — Lance un scan. Accepte `repositoryUrl` (crée le projet automatiquement) ou `projectId`.

Requête :
```json
{ "repositoryUrl": "https://github.com/OWASP/NodeGoat" }
// ou
{ "projectId": "56fed627-..." }
```

Réponse (201) :
```json
{
  "id": "778005c1-...",
  "projectId": "e7c80ad3-...",
  "status": "completed",
  "globalScore": "95.00",
  "executedAt": "2026-03-03T21:28:01+00:00",
  "findingsCount": 1
}
```

**`GET /api/scans/recent`** — Les 10 derniers scans.

```json
[
  {
    "id": "...",
    "project": {
      "id": "...",
      "name": "NodeGoat",
      "repositoryUrl": "https://github.com/OWASP/NodeGoat"
    },
    "executedAt": "2026-03-03T21:28:01+00:00",
    "globalScore": "95.00",
    "status": "completed",
    "findingsCount": 1
  }
]
```

**`GET /api/scans/{id}`** — Détail d'un scan avec tous les findings et remediations.

```json
{
  "id": "...",
  "project": {
    "id": "...",
    "name": "NodeGoat",
    "repositoryUrl": "https://github.com/OWASP/NodeGoat"
  },
  "executedAt": "2026-03-03T21:28:01+00:00",
  "globalScore": "95.00",
  "status": "completed",
  "findings": [
    {
      "id": "...",
      "toolSource": "semgrep",
      "severity": "MEDIUM",
      "owaspCategory": "A03",
      "filePath": "server.js",
      "lineNumber": 42,
      "description": "Detected user input flowing into eval...",
      "rawCode": "eval(req.query.cmd)",
      "remediation": {
        "proposedFix": "Protégez-vous contre les injections...",
        "status": "pending",
        "gitBranchName": null,
        "prUrl": null
      }
    }
  ]
}
```

**`GET /api/scans/{id}/findings`** — Findings avec filtres optionnels.

Query params : `?severity=HIGH&tool=semgrep&owasp=A03` (tous optionnels).

Réponse : même format que le tableau `findings` ci-dessus.

**`POST /api/scans/{id}/apply-fixes`** — Applique les fixes AI, push et crée une PR.

```json
{
  "branch": "fix/securescan-2026-03-03-778005c1",
  "prUrl": "https://github.com/OWASP/NodeGoat/pull/123"
}
```

**`GET /api/scans/{id}/report`** — Télécharge le rapport PDF (Content-Type: `application/pdf`).

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
