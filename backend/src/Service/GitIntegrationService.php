<?php

namespace App\Service;

use App\Entity\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

class GitIntegrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Applique les remediations sous forme de fichiers Markdown dans une branche dédiée,
     * puis pousse vers le dépôt distant si GIT_TOKEN est configuré.
     *
     * @return string|null Le nom de la branche créée, ou null si rien à appliquer
     */
    public function applyAndPush(Scan $scan, string $workdir): ?string
    {
        $remediations = $this->collectPendingRemediations($scan);

        if (\count($remediations) === 0) {
            return null;
        }

        $shortUuid = substr($scan->getId(), 0, 8);
        $branchName = sprintf('fix/securescan-%s-%s', date('Y-m-d'), $shortUuid);

        $this->injectTokenInRemoteUrl($workdir);
        $this->configureGitIdentity($workdir);
        $this->unshallowIfNeeded($workdir);
        $this->createBranch($workdir, $branchName);
        $this->writeRemediationFiles($workdir, $remediations);
        $this->commitChanges($workdir, $scan);
        $this->pushBranch($workdir, $branchName);

        foreach ($remediations as $remediation) {
            $remediation->setStatus('applied');
            $remediation->setGitBranchName($branchName);
            $remediation->setUpdatedAt(new \DateTime());
        }

        $this->entityManager->flush();

        return $branchName;
    }

    private function collectPendingRemediations(Scan $scan): array
    {
        $remediations = [];

        foreach ($scan->getFindings() as $finding) {
            $remediation = $finding->getRemediation();
            if ($remediation === null) {
                continue;
            }

            if ($remediation->getStatus() !== 'pending') {
                continue;
            }

            $proposedFix = $remediation->getProposedFix();
            if ($proposedFix === null || trim($proposedFix) === '') {
                continue;
            }

            $remediations[] = $remediation;
        }

        return $remediations;
    }

    private function injectTokenInRemoteUrl(string $workdir): void
    {
        $token = $_ENV['GIT_TOKEN'] ?? $_SERVER['GIT_TOKEN'] ?? '';
        if ($token === '') {
            return;
        }

        $process = new Process(['git', 'remote', 'get-url', 'origin'], $workdir);
        $process->run();
        $currentUrl = trim($process->getOutput());

        if ($currentUrl === '' || !str_starts_with($currentUrl, 'https://')) {
            return;
        }

        $authenticatedUrl = preg_replace(
            '#^https://#',
            sprintf('https://x-access-token:%s@', $token),
            $currentUrl
        );

        $this->runGit(['git', 'remote', 'set-url', 'origin', $authenticatedUrl], $workdir);
    }

    private function configureGitIdentity(string $workdir): void
    {
        $this->runGit(['git', 'config', 'user.email', 'securescan-bot@securescan.local'], $workdir);
        $this->runGit(['git', 'config', 'user.name', 'SecureScan Bot'], $workdir);
    }

    private function unshallowIfNeeded(string $workdir): void
    {
        if (file_exists($workdir . '/.git/shallow')) {
            $process = new Process(['git', 'fetch', '--unshallow'], $workdir);
            $process->setTimeout(300);
            $process->run();
        }
    }

    private function createBranch(string $workdir, string $branchName): void
    {
        $this->runGit(['git', 'checkout', '-b', $branchName], $workdir);
    }

    private function writeRemediationFiles(string $workdir, array $remediations): void
    {
        $fixDir = $workdir . '/.securescan';
        if (!is_dir($fixDir)) {
            @mkdir($fixDir, 0777, true);
        }

        foreach ($remediations as $remediation) {
            $finding = $remediation->getFinding();
            $safeFilename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', basename($finding->getFilePath()));
            $filename = sprintf('fix-%s-%s.md', $safeFilename, substr($finding->getId(), 0, 8));

            $content = $this->buildRemediationMarkdown($finding, $remediation);
            file_put_contents($fixDir . '/' . $filename, $content);
        }
    }

    private function buildRemediationMarkdown($finding, $remediation): string
    {
        $lines = [];
        $lines[] = '# SecureScan Remediation';
        $lines[] = '';
        $lines[] = sprintf('**Severity:** %s', $finding->getSeverity());
        $lines[] = sprintf('**Tool:** %s', $finding->getToolSource());
        $lines[] = sprintf('**File:** `%s`', $finding->getFilePath());

        if ($finding->getLineNumber() !== null) {
            $lines[] = sprintf('**Line:** %d', $finding->getLineNumber());
        }

        if ($finding->getOwaspCategory() !== null) {
            $lines[] = sprintf('**OWASP Category:** %s', $finding->getOwaspCategory());
        }

        $lines[] = '';
        $lines[] = '## Description';
        $lines[] = '';
        $lines[] = $finding->getDescription();
        $lines[] = '';
        $lines[] = '## Proposed Fix';
        $lines[] = '';
        $lines[] = $remediation->getProposedFix();
        $lines[] = '';

        if ($finding->getRawCode() !== null && $finding->getRawCode() !== '') {
            $lines[] = '## Raw Code';
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $finding->getRawCode();
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function commitChanges(string $workdir, Scan $scan): void
    {
        $this->runGit(['git', 'add', '-A'], $workdir);

        $message = sprintf(
            "[SecureScan] Security remediations for scan %s\n\nAutomated security fix documentation generated by SecureScan.\nScan score: %s/100\nFindings: %d",
            substr($scan->getId(), 0, 8),
            $scan->getGlobalScore() ?? 'N/A',
            $scan->getFindings()->count()
        );

        $this->runGit(['git', 'commit', '-m', $message], $workdir);
    }

    private function pushBranch(string $workdir, string $branchName): void
    {
        $token = $_ENV['GIT_TOKEN'] ?? $_SERVER['GIT_TOKEN'] ?? '';
        if ($token === '') {
            return;
        }

        $process = new Process(['git', 'push', '-u', 'origin', $branchName], $workdir);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                'Git push failed: ' . $process->getErrorOutput()
            );
        }
    }

    private function runGit(array $command, string $workdir): void
    {
        $process = new Process($command, $workdir);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                sprintf('Git command failed [%s]: %s', implode(' ', $command), $process->getErrorOutput())
            );
        }
    }
}
