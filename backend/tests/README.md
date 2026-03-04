# Tests backend (PHPUnit)

## Installation

```bash
composer install
```

## Exécution

En local (sans Docker), l’environnement de test utilise **SQLite en mémoire** : aucun MySQL n’est requis.

```bash
composer test
# ou
php vendor/bin/phpunit
```

Avec Docker (à la racine du projet) :

```bash
docker compose exec backend composer test
```

## Structure

- **Unit/** : tests unitaires (services).
  - `Service/ReportGeneratorTest.php` : génération du rapport PDF.
- **Functional/** : tests fonctionnels (kernel Symfony, requêtes HTTP).
  - `Controller/ScanControllerTest.php` : validation des requêtes create / from-archive.
  - `KernelTest.php` : route `/api/scans/recent` répond.
