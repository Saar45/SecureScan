## SecureScan

Plateforme de scan de sécurité automatisé pour dépôts Git.
SecureScan clone un dépôt, exécute plusieurs outils d'analyse (Semgrep, TruffleHog, audit npm/composer), normalise les résultats, calcule un score global, puis peut créer une branche de correction et générer un rapport PDF.

---

### Stack Technique

| Service    | URL                   | Stack                            |
|------------|-----------------------|----------------------------------|
| Backend    | http://localhost:8080 | Symfony 6.4, PHP 8.4, Apache    |
| Frontend   | http://localhost:3000 | React 19, TypeScript, Vite 7    |
| Database   | localhost:3306        | MySQL 8.0                        |
| phpMyAdmin | http://localhost:8081 | Administration BDD               |

---

### Prérequis

- **Docker** et **Docker Compose**
- Accès réseau au dépôt Git cible
- Ports disponibles : `8080`, `3000`, `3306`, `8081`

---

### Installation et lancement

```bash
git clone https://github.com/Saar45/SecureScan.git
cd SecureScan
cp .env.example .env
docker compose up --build
```

C'est tout. Pas de `composer install`, pas de migration, pas de seed manuel.

- Le schéma SQL et les données OWASP sont chargés automatiquement au premier démarrage de MySQL (`mysql/init/`).
- Les dépendances PHP (dont DomPDF) sont installées automatiquement par le script d'entrypoint du backend.
- Le frontend installe ses dépendances npm au build de l'image.

```bash
docker compose down        # Arrêter les services
docker compose down -v     # Arrêter + supprimer les volumes (reset BDD)
```

---

### Variables d'environnement

Fichier `.env` à la racine :

| Variable               | Description                                        | Défaut                  |
|------------------------|----------------------------------------------------|-------------------------|
| `MYSQL_ROOT_PASSWORD`  | Mot de passe root MySQL                            | `root_password`         |
| `MYSQL_DATABASE`       | Base applicative                                   | `security_scanner`      |
| `MYSQL_USER`           | Utilisateur MySQL                                  | `scanner_user`          |
| `MYSQL_PASSWORD`       | Mot de passe MySQL                                 | `scanner_password`      |
| `APP_ENV`              | Environnement Symfony                              | `dev`                   |
| `APP_SECRET`           | Secret Symfony                                     | *(généré)*              |
| `CORS_ALLOW_ORIGIN`    | Origine CORS autorisée                             | `http://localhost:3000` |
| `GIT_TOKEN`            | GitHub PAT pour push authentifié (scope `repo`)    | *(vide)*                |
| `VITE_API_URL`         | URL de l'API pour le frontend                      | `http://localhost:8080` |

---

### Modèle de données

```
Project (1) ──→ (N) Scan (1) ──→ (N) Finding (1) ──→ (1) Remediation
```

Tous les identifiants sont des UUID. La table `owasp_categories` est pré-remplie (A01–A10).

---

### Commandes CLI

```bash
# Lancer un scan
docker compose exec backend php bin/console app:test-scan <url> [name]

# Appliquer les remédiations Git + générer le rapport PDF
docker compose exec backend php bin/console app:git-integration <scan-id>

# Pipeline complet en une commande
docker compose exec backend php bin/console app:full-pipeline <repo-url> [project-name]
```

---

### Documentation par feature

| Feature | Documentation |
|---------|---------------|
| Pipeline de scan (Semgrep, TruffleHog, audits) | [docs/feature-scan-pipeline.md](docs/feature-scan-pipeline.md) |
| Intégration Git automatisée + rapport PDF | [docs/feature-git-integration.md](docs/feature-git-integration.md) |

---

### Développement

```bash
docker compose up --build backend       # Rebuild un seul service
docker compose exec backend php bin/console <commande>
docker compose exec backend composer require <package>
docker compose exec frontend npm install <package>
```
