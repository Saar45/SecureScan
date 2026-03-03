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

class ScanManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function startScan(Project $project): Scan
    {
        $scan = new Scan();
        $scan->setProject($project)
            ->setStatus('running');

        $this->entityManager->persist($scan);
        $this->entityManager->flush();

        $workdir = $this->prepareWorkdir();

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
            // Log technique directement dans le terminal (stderr)
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

    private function normalizeSeverity(string $severity): string
    {
        $normalized = strtoupper($severity);
        return match ($normalized) {
            'CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO' => $normalized,
            default => 'MEDIUM',
        };
    }

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

    private function maybeCreateRemediation(Finding $finding): void
    {
        $owasp = $finding->getOwaspCategory();
        if ($owasp === null) {
            return;
        }

        if ($owasp === 'A05') {
            $remediationText = 'Protégez-vous contre les injections (par ex. SQL) en utilisant des requêtes paramétrées, une validation stricte des entrées et en évitant la concaténation de chaînes dans les requêtes.';
        } elseif ($owasp === 'A04') {
            $remediationText = 'Ne stockez jamais de secrets dans le code ou le dépôt. Utilisez des variables d\'environnement, un gestionnaire de secrets (Vault, AWS Secrets Manager, etc.) et limitez la portée des clés.';
        } else {
            return;
        }

        $remediation = new Remediation();
        $remediation
            ->setFinding($finding)
            ->setProposedFix($remediationText);

        $this->entityManager->persist($remediation);
    }

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

