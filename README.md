# SecureScan

Plateforme de scan de vulnérabilités de sécurité construite avec Symfony, React et MySQL.

## Stack Technique

- **Backend :** Symfony 7 (PHP 8.2, Apache)
- **Frontend :** React 18 + TypeScript (Vite, Tailwind CSS)
- **Base de données :** MySQL 8
- **Administration :** phpMyAdmin

## Prérequis

- [Docker](https://www.docker.com/) et Docker Compose

## Installation

1. Cloner le dépôt :

```bash
git clone https://github.com/Saar45/SecureScan.git
cd SecureScan
```

2. Copier le fichier d'environnement et ajuster les valeurs si nécessaire :

```bash
cp .env.example .env
```

3. Construire et lancer les conteneurs :

```bash
docker compose up --build
```

4. Accéder aux services :

| Service    | URL                    |
| ---------- | ---------------------- |
| Frontend   | http://localhost:3000   |
| Backend    | http://localhost:8080   |
| phpMyAdmin | http://localhost:8081   |

La base de données `security_scanner` est automatiquement créée et alimentée avec les données OWASP au premier lancement.

## Structure du Projet

```
SecureScan/
├── backend/            # API Symfony
│   ├── src/Entity/     # Entités Doctrine (Project, Scan, Finding, Remediation)
│   ├── src/Repository/ # Repositories Doctrine
│   └── Dockerfile
├── frontend/           # Application React + Vite
│   ├── src/
│   └── Dockerfile
├── mysql/
│   └── init/           # Schéma SQL + données de seed
├── docker-compose.yml
└── .env.example
```

## Identifiants Base de Données

Identifiants par défaut (depuis `.env.example`) :

| Variable             | Valeur               |
| -------------------- | -------------------- |
| `MYSQL_ROOT_PASSWORD`| `your_root_password` |
| `MYSQL_DATABASE`     | `security_scanner`   |
| `MYSQL_USER`         | `your_user`          |
| `MYSQL_PASSWORD`     | `your_password`      |
