<?php

namespace App\Service;

use App\Entity\Finding;
use App\Entity\Project;
use App\Entity\Remediation;
use App\Entity\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrateur central du pipeline de scan de sécurité.
 *
 * Cette classe s'occupe de :
 * - cloner le dépôt Git à analyser,
 * - exécuter les outils externes (Semgrep, TruffleHog, audits de dépendances npm/composer),
 * - transformer leurs sorties brutes en entités métier (`Scan`, `Finding`, `Remediation`),
 * - calculer un score global et le rattacher au scan.
 */
class ScanManager
{
    /**
     * Environment variables passed to every subprocess.
     * Ensures tools (semgrep, npm, composer, trufflehog, git) can find their
     * config/cache directories when running as www-data under Apache.
     */
    private const PROCESS_ENV = [
        'HOME' => '/var/www',
        'COMPOSER_HOME' => '/var/www/.composer',
        'npm_config_cache' => '/var/www/.npm',
        'SEMGREP_SETTINGS_FILE' => '/var/www/.semgrep/settings.yml',
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lance un scan complet pour le projet donné et persiste toutes les entités liées.
     *
     * Cette méthode coordonne les différentes étapes (clone, détection de l'outil de dépendances,
     * exécution des scanners, calcul du score). En cas d'erreur technique, le statut du scan passe
     * à `failed` mais l'entité reste persistée pour permettre un diagnostic a posteriori.
     */
    public function startScan(Project $project): Scan
    {
        $scan = new Scan();
        $scan->setProject($project)
            ->setStatus('running');

        $this->entityManager->persist($scan);
        $this->entityManager->flush();

        $workdir = $this->prepareWorkdir();
        $scan->setWorkdir($workdir);

        try {
            $this->cloneRepository($project, $workdir);

            $dependencyTool = $this->detectDependencyTool($workdir);

            // Run each tool independently — one failure should not abort the others.
            $semgrepOutput = $this->runToolSafely('semgrep', fn () => $this->runSemgrep($workdir));
            $trufflehogOutput = $this->runToolSafely('trufflehog', fn () => $this->runTrufflehog($workdir));
            $dependencyOutput = $this->runToolSafely('dependency-audit', fn () => $this->runDependencyAudit($workdir, $dependencyTool));

            $this->processSemgrepResults($semgrepOutput, $scan, $workdir);
            $this->processTrufflehogResults($trufflehogOutput, $scan);
            $this->processDependencyResults($dependencyOutput, $scan, $dependencyTool);

            $score = $this->computeScore($scan);
            $scan->setGlobalScore(number_format($score, 2, '.', ''))
                ->setStatus('completed');
        } catch (\Throwable $e) {
            $this->logger->error('Scan failed: ' . $e->getMessage(), [
                'exception' => $e,
                'scanId' => $scan->getId(),
            ]);

            if (\defined('STDERR')) {
                fwrite(STDERR, "\n!!! ERREUR DÉTECTÉE : " . $e->getMessage() . "\n");

                if (method_exists($e, 'getProcess')) {
                    /** @var mixed $e */
                    $process = $e->getProcess();
                    if ($process instanceof Process) {
                        fwrite(STDERR, "SORTIE TECHNIQUE : " . $process->getErrorOutput() . "\n");
                    }
                }
            }

            $scan->setStatus('failed');
        }

        $this->entityManager->flush();

        return $scan;
    }

    /**
     * Lance un scan à partir d’un répertoire déjà présent (ex. contenu d’une archive ZIP).
     * Même pipeline que startScan mais sans clonage Git.
     */
    public function startScanFromWorkdir(Project $project, string $workdir): Scan
    {
        $scan = new Scan();
        $scan->setProject($project)
            ->setStatus('running')
            ->setWorkdir($workdir);

        $this->entityManager->persist($scan);
        $this->entityManager->flush();

        $this->logger->info('startScanFromWorkdir: workdir=' . $workdir . ', exists=' . (is_dir($workdir) ? 'yes' : 'no'));

        try {
            $dependencyTool = $this->detectDependencyTool($workdir);
            $this->logger->info('startScanFromWorkdir: dependencyTool=' . ($dependencyTool ?? 'none'));

            $semgrepOutput = $this->runToolSafely('semgrep', fn () => $this->runSemgrep($workdir));
            $trufflehogOutput = $this->runToolSafely('trufflehog', fn () => $this->runTrufflehog($workdir));
            $dependencyOutput = $this->runToolSafely('dependency-audit', fn () => $this->runDependencyAudit($workdir, $dependencyTool));

            $this->processSemgrepResults($semgrepOutput, $scan, $workdir);
            $this->processTrufflehogResults($trufflehogOutput, $scan);
            $this->processDependencyResults($dependencyOutput, $scan, $dependencyTool);

            $score = $this->computeScore($scan);
            $scan->setGlobalScore(number_format($score, 2, '.', ''))
                ->setStatus('completed');
        } catch (\Throwable $e) {
            $this->logger->error('Scan from workdir failed: ' . $e->getMessage(), [
                'exception' => $e,
                'scanId' => $scan->getId(),
            ]);

            if (\defined('STDERR')) {
                fwrite(STDERR, "\n!!! ERREUR DÉTECTÉE : " . $e->getMessage() . "\n");
            }

            $scan->setStatus('failed');
        }

        $this->entityManager->flush();

        return $scan;
    }

    /**
     * Prépare un répertoire de travail isolé (dans /tmp/scans) pour ce scan.
     *
     * L'utilisation d'un dossier unique par scan (UUID) évite les collisions entre exécutions
     * parallèles et limite les effets de bord entre différents dépôts analysés.
     */
    private function prepareWorkdir(): string
    {
        $baseDir = '/tmp/scans';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0777, true);
        }

        $scanDir = $baseDir . '/' . Uuid::v4()->toRfc4122();
        @mkdir($scanDir, 0777, true);

        return $scanDir;
    }

    /**
     * Wraps a tool execution so that if it fails, the error is logged
     * and null is returned instead of aborting the entire scan.
     */
    private function runToolSafely(string $toolName, callable $fn): ?array
    {
        try {
            $result = $fn();
            $count = 0;
            if (\is_array($result)) {
                $count = isset($result['results']) ? \count($result['results']) : \count($result);
            }
            $this->logger->info(sprintf('Tool "%s" completed: %s items', $toolName, $count));
            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                'Tool "%s" failed but scan continues: %s',
                $toolName,
                $e->getMessage()
            ));
            if (method_exists($e, 'getProcess') && $e->getProcess() instanceof Process) {
                $this->logger->debug('Tool process stderr: ' . $e->getProcess()->getErrorOutput());
            }
            return null;
        }
    }

    /**
     * Clone le dépôt Git du projet dans le répertoire de travail dédié.
     *
     * On laisse Git choisir la branche par défaut du dépôt pour rester générique et réduire
     * le temps de clone avec `--depth 1`.
     */
    private function cloneRepository(Project $project, string $targetDir): void
    {
        // On enlève --branch pour laisser Git choisir la branche par défaut du dépôt
        $process = new Process([
            '/usr/bin/git',
            'clone',
            '--depth',
            '1',
            $project->getRepositoryUrl(),
            $targetDir,
        ], null, self::PROCESS_ENV);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        // Mark directory as safe to avoid "dubious ownership" errors
        $safeDir = new Process([
            '/usr/bin/git',
            'config',
            '--global',
            'safe.directory',
            $targetDir,
        ], null, self::PROCESS_ENV);
        $safeDir->setTimeout(10);
        $safeDir->run();
    }

    /**
     * Détecte le gestionnaire de dépendances principal du projet
     * en se basant sur la présence de fichiers connus (package.json, composer.json).
     */
    private function detectDependencyTool(string $workdir): ?string
    {
        if (file_exists($workdir . '/package.json')) {
            return 'npm';
        }

        if (file_exists($workdir . '/composer.json')) {
            return 'composer';
        }

        return null;
    }

    /**
     * Exécute Semgrep avec la configuration par défaut et retourne la sortie JSON décodée.
     *
     * Le travail de mise en forme (mappage vers `Finding`, sévérité, catégorie OWASP)
     * est délégué à `processSemgrepResults`.
     */
    private function runSemgrep(string $workdir): ?array
    {
        $process = new Process([
            'python3',
            '/usr/local/bin/semgrep',
            '--config',
            'auto',
            '--json',
        ], $workdir, self::PROCESS_ENV);
        $process->setTimeout(600);
        $process->run();

        // Exit code 0 = no findings, 2 = findings found or some files skipped.
        // Both are valid — semgrep still produces usable JSON output.
        if (!$process->isSuccessful() && $process->getExitCode() !== 2) {
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();

        return json_decode($output, true) ?? null;
    }

    /**
     * Exécute TruffleHog en mode "filesystem" et agrège chaque ligne JSON en tableau PHP.
     *
     * TruffleHog écrit un objet JSON par ligne ; on recompose donc une liste exploitable
     * pour la suite du pipeline.
     */
    private function runTrufflehog(string $workdir): ?array
    {
        $process = new Process([
            '/usr/local/bin/trufflehog',
            'filesystem',
            realpath($workdir) ?: $workdir,
            '--json',
            '--no-update',
        ], null, self::PROCESS_ENV);
        $process->setTimeout(600);
        $process->run();

        // TruffleHog may exit with non-zero code when secrets are found
        // but still produces valid JSON output — only fail on serious errors.
        if (!$process->isSuccessful()) {
            $output = $process->getOutput();
            // If there's JSON output, trufflehog ran but found things — that's fine
            if (empty(trim($output))) {
                throw new ProcessFailedException($process);
            }
        }

        $lines = array_filter(explode("\n", $process->getOutput()));
        $results = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $results[] = $decoded;
            }
        }

        return $results;
    }

    /**
     * Lance un audit de dépendances en fonction de l'écosystème détecté (npm ou composer).
     *
     * Pour npm, le code de retour 1 signifie "vulnérabilités trouvées" et n'est pas considéré
     * comme une erreur technique ; pour composer en revanche, tout échec déclenche une exception.
     */
    private function runDependencyAudit(string $workdir, ?string $tool): ?array
    {
        if ($tool === null) {
            return null;
        }

        if ($tool === 'npm') {
            $process = new Process(['/usr/bin/npm', 'audit', '--json'], $workdir, self::PROCESS_ENV);
        } else {
            $process = new Process(['/usr/bin/composer', 'audit', '--format=json', '--no-interaction'], $workdir, self::PROCESS_ENV);
        }

        $process->setTimeout(600);
        $process->run();

        if ($tool === 'npm') {
            // npm audit uses exit code 1 to signal "vulnerabilities found" — not a crash.
            if (!$process->isSuccessful() && $process->getExitCode() !== 1) {
                throw new ProcessFailedException($process);
            }
        } else {
            // composer audit uses exit code 1 when vulnerabilities are found — not a crash.
            if (!$process->isSuccessful() && $process->getExitCode() !== 1) {
                throw new ProcessFailedException($process);
            }
        }

        $output = $process->getOutput();

        return json_decode($output, true) ?? null;
    }

    /**
     * Transforme les résultats Semgrep en entités `Finding` rattachées au `Scan`.
     *
     * Cette méthode centralise également la normalisation de la sévérité et
     * le mapping vers une catégorie OWASP, afin d'avoir une vue homogène
     * quel que soit le rule-set utilisé.
     */
    private function processSemgrepResults(?array $data, Scan $scan, string $workdir = ''): void
    {
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return;
        }

        foreach ($data['results'] as $result) {
            $path = $result['path'] ?? '';
            // When Semgrep is given an absolute path, result path may be absolute; normalize to relative for storage
            if ($workdir && $path && str_starts_with($path, $workdir)) {
                $path = ltrim(substr($path, \strlen($workdir)), '/\\');
            }

            // Semgrep may redact code snippets behind "requires login".
            // Fall back to reading the source file directly when that happens.
            $rawCode = $result['extra']['lines'] ?? null;
            if ($rawCode === 'requires login' || $rawCode === null) {
                $absPath = ($workdir && $path && !str_starts_with($path, '/'))
                    ? rtrim($workdir, '/') . '/' . str_replace('\\', '/', $path)
                    : str_replace('\\', '/', $path);
                $rawCode = $this->readSourceLines(
                    $absPath,
                    $result['start']['line'] ?? null,
                    $result['end']['line'] ?? null
                );
            }

            $finding = new Finding();
            $finding
                ->setScan($scan)
                ->setToolSource('semgrep')
                ->setSeverity($this->normalizeSeverity($result['extra']['severity'] ?? 'medium'))
                ->setFilePath($path)
                ->setLineNumber($result['start']['line'] ?? null)
                ->setDescription($result['extra']['message'] ?? 'Semgrep finding')
                ->setRawCode($rawCode);

            $owasp = $this->mapToOwaspCategoryFromSemgrep($result);
            $finding->setOwaspCategory($owasp);

            $this->maybeCreateRemediation($finding);

            $scan->addFinding($finding);
            $this->entityManager->persist($finding);
        }
    }

    /**
     * Transforme les résultats TruffleHog (détection de secrets) en `Finding`.
     *
     * Tous ces findings sont considérés comme haute sévérité et mappés sur OWASP A04
     * (Cryptographic Failures — secrets exposés), ce qui permet de générer des recommandations ciblées.
     */
    private function processTrufflehogResults(?array $results, Scan $scan): void
    {
        if (!is_array($results)) {
            return;
        }

        foreach ($results as $item) {
            $finding = new Finding();
            $finding
                ->setScan($scan)
                ->setToolSource('trufflehog')
                ->setSeverity('HIGH')
                ->setFilePath($item['SourceMetadata']['Data']['Filesystem']['file'] ?? '')
                ->setLineNumber(null)
                ->setDescription($item['DetectorName'] ?? 'Potential secret detected')
                ->setRawCode($item['Raw'] ?? null);

            $finding->setOwaspCategory('A04');
            $this->maybeCreateRemediation($finding);

            $scan->addFinding($finding);
            $this->entityManager->persist($finding);
        }
    }

    /**
     * Interprète les audits de dépendances npm/composer et crée des `Finding` correspondants.
     *
     * Le code supporte à la fois :
     * - le nouveau format de `npm audit` (clé `vulnerabilities`),
     * - l'ancien format (clé `advisories`),
     * - le format JSON de `composer audit`.
     */
    private function processDependencyResults(?array $data, Scan $scan, ?string $tool): void
    {
        if (!is_array($data) || $tool === null) {
            return;
        }

        if ($tool === 'npm') {
            // Nouveau format npm audit (npm v8+): résultats dans "vulnerabilities"
            if (isset($data['vulnerabilities']) && is_array($data['vulnerabilities'])) {
                foreach ($data['vulnerabilities'] as $packageName => $details) {
                    if (!is_array($details)) {
                        continue;
                    }

                    $severity = $this->normalizeSeverity($details['severity'] ?? 'medium');
                    $range = $details['range'] ?? 'N/A';

                    // Construction d'un résumé à partir de "via" si présent
                    $viaDescription = '';
                    if (isset($details['via'])) {
                        if (is_array($details['via'])) {
                            $viaParts = [];
                            foreach ($details['via'] as $via) {
                                if (is_string($via)) {
                                    $viaParts[] = $via;
                                } elseif (is_array($via) && isset($via['title'])) {
                                    $viaParts[] = $via['title'];
                                }
                            }
                            if ($viaParts !== []) {
                                $viaDescription = implode(', ', $viaParts);
                            }
                        } elseif (is_string($details['via'])) {
                            $viaDescription = $details['via'];
                        }
                    }

                    $description = sprintf(
                        'NPM dependency vulnerability in "%s" (range: %s)%s',
                        $packageName,
                        $range,
                        $viaDescription !== '' ? ' | via: ' . $viaDescription : ''
                    );

                    $finding = new Finding();
                    $finding
                        ->setScan($scan)
                        ->setToolSource('npm-audit')
                        ->setSeverity($severity)
                        ->setFilePath('package.json')
                        ->setDescription($description)
                        ->setRawCode(json_encode($details));

                    $finding->setOwaspCategory($this->mapToOwaspCategoryFromDependency($details));
                    $this->maybeCreateRemediation($finding);

                    $scan->addFinding($finding);
                    $this->entityManager->persist($finding);
                }
            }

            // Compatibilité avec l'ancien format npm audit (clé "advisories")
            if (isset($data['advisories']) && is_array($data['advisories'])) {
                foreach ($data['advisories'] as $advisory) {
                    $finding = new Finding();
                    $finding
                        ->setScan($scan)
                        ->setToolSource('npm-audit')
                        ->setSeverity($this->normalizeSeverity($advisory['severity'] ?? 'medium'))
                        ->setFilePath('package.json')
                        ->setDescription($advisory['title'] ?? 'Dependency vulnerability')
                        ->setRawCode(json_encode($advisory));

                    $finding->setOwaspCategory($this->mapToOwaspCategoryFromDependency($advisory));
                    $this->maybeCreateRemediation($finding);

                    $scan->addFinding($finding);
                    $this->entityManager->persist($finding);
                }
            }
        }

        if ($tool === 'composer' && isset($data['advisories']) && is_array($data['advisories'])) {
            foreach ($data['advisories'] as $packageName => $packageAdvisories) {
                foreach ($packageAdvisories as $advisory) {
                    $finding = new Finding();
                    $finding
                        ->setScan($scan)
                        ->setToolSource('composer-audit')
                        ->setSeverity($this->normalizeSeverity($advisory['cve_severity'] ?? 'medium'))
                        ->setFilePath('composer.json')
                        ->setDescription($advisory['title'] ?? 'Dependency vulnerability')
                        ->setRawCode(json_encode($advisory));

                    $finding->setOwaspCategory($this->mapToOwaspCategoryFromDependency($advisory));
                    $this->maybeCreateRemediation($finding);

                    $scan->addFinding($finding);
                    $this->entityManager->persist($finding);
                }
            }
        }
    }

    /**
     * Reads source code lines from a file on disk.
     * Used as fallback when semgrep redacts the code snippet ("requires login").
     */
    private function readSourceLines(string $filePath, ?int $startLine, ?int $endLine): ?string
    {
        if (!$filePath || !$startLine || !is_file($filePath)) {
            return null;
        }

        $lines = @file($filePath);
        if ($lines === false) {
            return null;
        }

        $end = $endLine ?? $startLine;
        // Add 2 lines of context before and after
        $from = max(0, $startLine - 3);
        $to = min(count($lines) - 1, $end + 1);

        $snippet = array_slice($lines, $from, $to - $from + 1);

        return rtrim(implode('', $snippet));
    }

    /**
     * Normalise la sévérité en un niveau unique (CRITICAL/HIGH/MEDIUM/LOW/INFO).
     *
     * Permet de consolider des sources hétérogènes (Semgrep, npm, composer, TruffleHog)
     * avant de calculer le score global.
     */
    private function normalizeSeverity(string $severity): string
    {
        $normalized = strtoupper($severity);
        return match ($normalized) {
            'CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO' => $normalized,
            default => 'MEDIUM',
        };
    }

    /**
     * Essaie de déduire une catégorie OWASP à partir d'un résultat Semgrep.
     *
     * Le mapping est volontairement simple (basé sur le message et l'identifiant de règle)
     * mais permet déjà de regrouper les findings par grandes familles de vulnérabilités.
     */
    private function mapToOwaspCategoryFromSemgrep(array $result): ?string
    {
        $message = strtolower((string) ($result['extra']['message'] ?? ''));
        $ruleId = strtolower((string) ($result['check_id'] ?? ''));

        // A05 — Injection (SQL, XSS, command injection, path traversal, eval, LDAP, etc.)
        if (str_contains($message, 'sql injection') || str_contains($ruleId, 'sql_injection')
            || str_contains($message, 'xss') || str_contains($ruleId, 'xss')
            || str_contains($message, 'cross-site scripting')
            || str_contains($message, 'command injection') || str_contains($ruleId, 'command_injection')
            || str_contains($message, 'path traversal') || str_contains($ruleId, 'path_traversal')
            || str_contains($message, 'eval(') || str_contains($ruleId, 'eval')
            || str_contains($message, 'ldap injection') || str_contains($ruleId, 'ldap')) {
            return 'A05';
        }

        // A01 — Broken Access Control
        if (str_contains($message, 'authorization') || str_contains($ruleId, 'access_control')
            || str_contains($message, 'access control') || str_contains($ruleId, 'idor')
            || str_contains($message, 'privilege') || str_contains($ruleId, 'privilege')) {
            return 'A01';
        }

        // A02 — Security Misconfiguration
        if (str_contains($message, 'misconfiguration') || str_contains($ruleId, 'misconfig')
            || str_contains($message, 'cors') || str_contains($ruleId, 'cors')
            || str_contains($message, 'debug') || str_contains($ruleId, 'debug')
            || str_contains($message, 'default password') || str_contains($ruleId, 'default_password')
            || str_contains($message, 'hardcoded') || str_contains($ruleId, 'hardcoded')) {
            return 'A02';
        }

        // A04 — Cryptographic Failures
        if (str_contains($message, 'crypto') || str_contains($ruleId, 'crypto')
            || str_contains($message, 'weak hash') || str_contains($ruleId, 'weak_hash')
            || str_contains($message, 'md5') || str_contains($message, 'sha1')
            || str_contains($message, 'insecure random') || str_contains($ruleId, 'random')
            || str_contains($message, 'tls') || str_contains($message, 'ssl')
            || str_contains($message, 'cleartext') || str_contains($ruleId, 'cleartext')) {
            return 'A04';
        }

        // A07 — Authentication Failures
        if (str_contains($message, 'authentication') || str_contains($ruleId, 'auth')
            || str_contains($message, 'session') || str_contains($ruleId, 'session')
            || str_contains($message, 'password') || str_contains($ruleId, 'password')
            || str_contains($message, 'brute force') || str_contains($ruleId, 'brute_force')) {
            return 'A07';
        }

        // A06 — Insecure Design
        if (str_contains($message, 'insecure design') || str_contains($ruleId, 'insecure_design')
            || str_contains($message, 'race condition') || str_contains($ruleId, 'race_condition')) {
            return 'A06';
        }

        // A08 — Software and Data Integrity Failures
        if (str_contains($message, 'deserialization') || str_contains($ruleId, 'deserialization')
            || str_contains($message, 'integrity') || str_contains($ruleId, 'integrity')) {
            return 'A08';
        }

        return null;
    }

    /**
     * Déduit une catégorie OWASP à partir d'un advisory de dépendance (npm ou composer).
     *
     * On utilise ici un heuristique basé sur le titre / la description, ce qui permet
     * de rester compatible avec plusieurs formats d'output.
     */
    private function mapToOwaspCategoryFromDependency(array $advisory): ?string
    {
        $title = strtolower((string) ($advisory['title'] ?? ''));
        $description = strtolower((string) ($advisory['description'] ?? ''));

        // A05 — Injection (SQL, XSS, command injection in dependencies)
        if (str_contains($title, 'injection') || str_contains($description, 'injection')
            || str_contains($title, 'xss') || str_contains($description, 'cross-site scripting')) {
            return 'A05';
        }

        // A07 — Authentication Failures
        if (str_contains($title, 'authentication') || str_contains($description, 'authentication')) {
            return 'A07';
        }

        // A01 — Broken Access Control
        if (str_contains($title, 'authorization') || str_contains($description, 'authorization')
            || str_contains($title, 'access control') || str_contains($description, 'access control')) {
            return 'A01';
        }

        // A04 — Cryptographic Failures
        if (str_contains($title, 'crypto') || str_contains($description, 'crypto')
            || str_contains($title, 'ssl') || str_contains($description, 'tls')) {
            return 'A04';
        }

        // Default for dependency vulnerabilities → A03 (Software Supply Chain Failures)
        return 'A03';
    }

    /**
     * Crée automatiquement une entité `Remediation` pour chaque finding.
     *
     * Un texte de remédiation est généré selon la catégorie OWASP du finding.
     * Les corrections de code effectives sont déléguées à AiFixService lors de l'intégration Git.
     */
    private function maybeCreateRemediation(Finding $finding): void
    {
        $remediationText = $this->getRemediationTextForFinding($finding);

        $remediation = new Remediation();
        $remediation
            ->setFinding($finding)
            ->setProposedFix($remediationText);

        $finding->setRemediation($remediation);
        $this->entityManager->persist($remediation);
    }

    private function getRemediationTextForFinding(Finding $finding): string
    {
        $owasp = $finding->getOwaspCategory();

        return match ($owasp) {
            'A01' => 'Renforcez les contrôles d\'accès : vérifiez l\'autorisation côté serveur pour chaque requête. Appliquez le principe du moindre privilège et refusez par défaut.',
            'A02' => 'Corrigez les erreurs de configuration : désactivez les fonctionnalités inutiles, changez les mots de passe par défaut, restreignez les en-têtes CORS et appliquez un durcissement systématique.',
            'A03' => 'Sécurisez la chaîne d\'approvisionnement logicielle : mettez à jour les dépendances vulnérables, automatisez la veille (Dependabot, Renovate), vérifiez l\'intégrité des paquets et supprimez les bibliothèques inutilisées.',
            'A04' => 'Corrigez les failles cryptographiques : utilisez des algorithmes modernes (bcrypt, Argon2), activez TLS partout, ne stockez jamais de secrets dans le code et utilisez un gestionnaire de secrets (Vault, AWS Secrets Manager).',
            'A05' => 'Protégez-vous contre les injections (SQL, XSS, commandes OS, etc.) : utilisez des requêtes paramétrées, échappez systématiquement les sorties, validez toutes les entrées côté serveur et évitez eval().',
            'A06' => 'Améliorez la conception sécurisée : modélisez les menaces dès la conception, appliquez les design patterns sécurisés et séparez les couches métier des couches de présentation.',
            'A07' => 'Corrigez les failles d\'authentification : implémentez la limitation de tentatives, utilisez l\'authentification multi-facteurs, sécurisez les sessions et ne divulguez pas d\'informations sur les comptes existants.',
            'A08' => 'Vérifiez l\'intégrité des logiciels et des données : signez les artefacts, validez les mises à jour, sécurisez les pipelines CI/CD et protégez-vous contre la désérialisation non sécurisée.',
            'A09' => 'Améliorez la journalisation et les alertes : enregistrez les événements de sécurité, centralisez les logs, mettez en place des alertes en temps réel et testez régulièrement votre capacité de détection.',
            'A10' => 'Gérez correctement les conditions exceptionnelles : ne divulguez jamais de stack traces en production, validez toutes les entrées aux limites, gérez explicitement les erreurs et testez les cas limites.',
            default => sprintf(
                'Vulnérabilité détectée (%s, sévérité %s). Examinez le code concerné dans %s et appliquez les bonnes pratiques de sécurité OWASP.',
                $finding->getToolSource(),
                $finding->getSeverity(),
                $finding->getFilePath()
            ),
        };
    }

    /**
     * Calcule un score global sur 100 en appliquant une pénalité par finding.
     *
     * On part d'un score parfait (100) et on applique une pénalité pondérée selon
     * la sévérité, puis on borne le résultat entre 0 et 100 pour rester lisible.
     */
    private function computeScore(Scan $scan): float
    {
        $baseScore = 100.0;

        foreach ($scan->getFindings() as $finding) {
            $baseScore += $this->penaltyForSeverity($finding->getSeverity());
        }

        if ($baseScore < 0) {
            $baseScore = 0.0;
        }
        if ($baseScore > 100) {
            $baseScore = 100.0;
        }

        return $baseScore;
    }

    /**
     * Retourne la pénalité à appliquer au score en fonction de la sévérité.
     *
     * Cette fonction est séparée pour permettre d'ajuster facilement la pondération
     * sans toucher au reste de l'algorithme de scoring.
     */
    private function penaltyForSeverity(string $severity): float
    {
        return match (strtoupper($severity)) {
            'CRITICAL' => -20.0,
            'HIGH' => -10.0,
            'MEDIUM' => -5.0,
            'LOW' => -2.0,
            default => -1.0,
        };
    }
}

