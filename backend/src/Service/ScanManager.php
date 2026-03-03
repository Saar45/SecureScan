<?php

namespace App\Service;

use App\Entity\Finding;
use App\Entity\Project;
use App\Entity\Remediation;
use App\Entity\Scan;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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

            $semgrepOutput = $this->runSemgrep($workdir);
            $trufflehogOutput = $this->runTrufflehog($workdir);
            $dependencyOutput = $this->runDependencyAudit($workdir, $dependencyTool);

            $this->processSemgrepResults($semgrepOutput, $scan);
            $this->processTrufflehogResults($trufflehogOutput, $scan);
            $this->processDependencyResults($dependencyOutput, $scan, $dependencyTool);

            $score = $this->computeScore($scan);
            $scan->setGlobalScore(number_format($score, 2, '.', ''))
                ->setStatus('completed');
        } catch (\Throwable $e) {
            // Log technique directement dans le terminal (stderr) pour faciliter le debug
            // lors d'une exécution en CLI / dans un container, indépendamment du logger Symfony.
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
        ]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
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
            'p/default',
            '--json',
        ], $workdir);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
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
            $workdir,
            '--json',
        ]);
        $process->setTimeout(600);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
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
            $process = new Process(['/usr/bin/npm', 'audit', '--json'], $workdir);
        } else {
            $process = new Process(['composer', 'audit', '--format=json'], $workdir);
        }

        $process->setTimeout(600);
        $process->run();

        if ($tool === 'npm') {
            // npm audit utilise le code de sortie 1 pour signaler des vulnérabilités trouvées,
            // ce n'est pas une erreur d'exécution, donc on l'accepte.
            if (!$process->isSuccessful() && $process->getExitCode() !== 1) {
                throw new ProcessFailedException($process);
            }
        } else {
            if (!$process->isSuccessful()) {
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
    private function processSemgrepResults(?array $data, Scan $scan): void
    {
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return;
        }

        foreach ($data['results'] as $result) {
            $finding = new Finding();
            $finding
                ->setScan($scan)
                ->setToolSource('semgrep')
                ->setSeverity($this->normalizeSeverity($result['extra']['severity'] ?? 'medium'))
                ->setFilePath($result['path'] ?? '')
                ->setLineNumber($result['start']['line'] ?? null)
                ->setDescription($result['extra']['message'] ?? 'Semgrep finding')
                ->setRawCode($result['extra']['lines'] ?? null);

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
     * Tous ces findings sont considérés comme haute sévérité et mappés sur OWASP A04,
     * ce qui permet de générer des recommandations ciblées.
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

        if (str_contains($message, 'sql injection') || str_contains($ruleId, 'sql_injection')) {
            return 'A05';
        }

        if (str_contains($message, 'xss') || str_contains($ruleId, 'xss')) {
            return 'A03';
        }

        if (str_contains($message, 'path traversal') || str_contains($ruleId, 'path_traversal')) {
            return 'A05';
        }

        if (str_contains($message, 'authentication') || str_contains($ruleId, 'auth')) {
            return 'A01';
        }

        if (str_contains($message, 'authorization') || str_contains($ruleId, 'access_control')) {
            return 'A02';
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

        if (str_contains($title, 'injection') || str_contains($description, 'injection')) {
            return 'A05';
        }

        if (str_contains($title, 'xss') || str_contains($description, 'cross-site scripting')) {
            return 'A03';
        }

        if (str_contains($title, 'authentication') || str_contains($description, 'authentication')) {
            return 'A01';
        }

        if (str_contains($title, 'authorization') || str_contains($description, 'authorization')) {
            return 'A02';
        }

        return null;
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
            'A01' => 'Renforcez les contrôles d\'accès : vérifiez l\'authentification et l\'autorisation côté serveur pour chaque requête. Appliquez le principe du moindre privilège.',
            'A02' => 'Corrigez les failles cryptographiques : utilisez des algorithmes modernes (bcrypt, Argon2 pour les mots de passe), activez TLS partout et ne stockez pas de données sensibles inutilement.',
            'A03' => 'Protégez-vous contre les injections (XSS, SQL, etc.) : échappez systématiquement les sorties, utilisez des requêtes paramétrées et validez toutes les entrées côté serveur.',
            'A04' => 'Ne stockez jamais de secrets dans le code ou le dépôt. Utilisez des variables d\'environnement, un gestionnaire de secrets (Vault, AWS Secrets Manager, etc.) et limitez la portée des clés.',
            'A05' => 'Protégez-vous contre les injections en utilisant des requêtes paramétrées, une validation stricte des entrées et en évitant la concaténation de chaînes dans les requêtes.',
            'A06' => 'Mettez à jour les composants vulnérables et obsolètes. Automatisez la veille des dépendances (Dependabot, Renovate) et supprimez les bibliothèques inutilisées.',
            'A07' => 'Corrigez les failles d\'authentification : implémentez la limitation de tentatives, utilisez l\'authentification multi-facteurs et ne divulguez pas d\'informations sur les comptes existants.',
            'A08' => 'Vérifiez l\'intégrité des logiciels et des données : signez les artefacts, validez les mises à jour et sécurisez les pipelines CI/CD.',
            'A09' => 'Améliorez la journalisation et la surveillance : enregistrez les événements de sécurité, centralisez les logs et mettez en place des alertes en temps réel.',
            'A10' => 'Protégez-vous contre les falsifications de requêtes côté serveur (SSRF) : validez et filtrez les URL, bloquez les plages d\'adresses internes et utilisez des listes d\'autorisation.',
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

