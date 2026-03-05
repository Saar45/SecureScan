<?php

namespace App\Service;

use App\Entity\Scan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GitIntegrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiFixService $aiFixService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Applique les remediations (fixes AI + documentation) dans une branche dédiée,
     * pousse vers le dépôt distant et ouvre une Pull Request si un token est disponible.
     *
     * @param string|null $userToken OAuth token from the logged-in user (fallback to GIT_TOKEN env)
     * @return array{branch: ?string, prUrl: ?string}
     */
    public function applyAndPush(Scan $scan, string $workdir, ?string $userToken = null): array
    {
        $remediations = $this->collectPendingRemediations($scan);

        if (\count($remediations) === 0) {
            return ['branch' => null, 'prUrl' => null];
        }

        $token = $this->getToken($userToken);
        $shortUuid = substr($scan->getId(), 0, 8);
        $branchName = sprintf('fix/securescan-%s-%s', date('Y-m-d'), $shortUuid);

        $repoUrl = $scan->getProject()->getRepositoryUrl();
        $ownerRepo = $this->parseOwnerRepo($repoUrl);

        // Decide: push directly (own repo / write access) or fork first (someone else's repo)
        $forkFullName = null;
        if ($ownerRepo !== null && $token !== '') {
            [$owner, $repo] = $ownerRepo;

            if ($this->hasWriteAccess($owner, $repo, $token)) {
                // Own repo or collaborator — push directly
                $this->injectTokenInRemoteUrl($workdir, $token);
            } else {
                // No write access — fork, then push to the fork
                $forkFullName = $this->forkRepository($owner, $repo, $token);

                if ($forkFullName !== null) {
                    $forkUrl = sprintf('https://x-access-token:%s@github.com/%s.git', $token, $forkFullName);
                    $this->runGit(['git', 'remote', 'set-url', 'origin', $forkUrl], $workdir);
                } else {
                    $this->injectTokenInRemoteUrl($workdir, $token);
                }
            }
        } else {
            $this->injectTokenInRemoteUrl($workdir, $token);
        }

        $this->configureGitIdentity($workdir);
        $this->unshallowIfNeeded($workdir);
        $this->createBranch($workdir, $branchName);

        $this->applyAiFixes($workdir, $remediations);

        $hasChanges = $this->commitChanges($workdir, $scan);

        $prUrl = null;
        if ($hasChanges) {
            $this->pushBranch($workdir, $branchName, $token);
            $prUrl = $this->createPullRequest($repoUrl, $branchName, $scan, $token, $forkFullName);
        }

        foreach ($remediations as $remediation) {
            $remediation->setStatus('applied');
            $remediation->setGitBranchName($branchName);
            if ($prUrl !== null) {
                $remediation->setPrUrl($prUrl);
            }
            $remediation->setUpdatedAt(new \DateTime());
        }

        $this->entityManager->flush();

        return ['branch' => $branchName, 'prUrl' => $prUrl];
    }

    private function getToken(?string $userToken): string
    {
        if ($userToken !== null && $userToken !== '') {
            return $userToken;
        }

        return $_ENV['GIT_TOKEN'] ?? $_SERVER['GIT_TOKEN'] ?? '';
    }

    /**
     * Checks if the authenticated user has push (write) access to the repository.
     */
    private function hasWriteAccess(string $owner, string $repo, string $token): bool
    {
        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/%s', $owner, $repo), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $token),
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);

            $data = $response->toArray();

            return !empty($data['permissions']['push']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Forks a GitHub repository and waits until the fork is ready.
     */
    public function forkRepository(string $owner, string $repo, string $token): ?string
    {
        try {
            $response = $this->httpClient->request('POST', sprintf('https://api.github.com/repos/%s/%s/forks', $owner, $repo), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $token),
                    'Accept' => 'application/vnd.github+json',
                ],
                'json' => new \stdClass(),
            ]);

            $data = $response->toArray();
            $forkFullName = $data['full_name'] ?? null;

            if ($forkFullName === null) {
                return null;
            }

            // Poll until fork is ready (max 30 seconds)
            for ($i = 0; $i < 15; $i++) {
                sleep(2);
                try {
                    $check = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s', $forkFullName), [
                        'headers' => [
                            'Authorization' => sprintf('Bearer %s', $token),
                            'Accept' => 'application/vnd.github+json',
                        ],
                    ]);
                    $checkData = $check->toArray();
                    if (!empty($checkData['id'])) {
                        return $forkFullName;
                    }
                } catch (\Throwable) {
                    // Fork not ready yet
                }
            }

            return $forkFullName;
        } catch (\Throwable) {
            return null;
        }
    }

    private const MAX_AI_FIXES = 10;

    private const SEVERITY_PRIORITY = [
        'CRITICAL' => 0,
        'HIGH' => 1,
        'MEDIUM' => 2,
        'LOW' => 3,
        'INFO' => 4,
    ];

    /**
     * Applies AI-generated fixes directly to source files in the workdir.
     *
     * Limited to MAX_AI_FIXES calls to avoid API rate limits and keep PRs reviewable.
     * Findings are prioritized by severity (CRITICAL first).
     */
    private function applyAiFixes(string $workdir, array $remediations): void
    {
        // Sort by severity so the most critical findings get AI fixes first
        $candidates = $remediations;
        usort($candidates, function ($a, $b) {
            $sevA = self::SEVERITY_PRIORITY[strtoupper($a->getFinding()->getSeverity())] ?? 5;
            $sevB = self::SEVERITY_PRIORITY[strtoupper($b->getFinding()->getSeverity())] ?? 5;
            return $sevA <=> $sevB;
        });

        $applied = 0;
        $apiCalls = 0;
        $log = function (string $msg): void {
            if (\defined('STDERR')) {
                fwrite(STDERR, "[AI-FIX] $msg\n");
            }
        };

        // Only apply AI fixes to Semgrep findings (actual source code vulnerabilities)
        $candidates = array_filter($candidates, function ($remediation) {
            return $remediation->getFinding()->getToolSource() === 'semgrep';
        });
        $candidates = array_values($candidates);

        $log(sprintf('Candidates (source code only): %d', \count($candidates)));

        foreach ($candidates as $remediation) {
            if ($apiCalls >= self::MAX_AI_FIXES) {
                break;
            }

            $finding = $remediation->getFinding();
            $filePath = $finding->getFilePath();
            $rawCode = $finding->getRawCode();

            if ($filePath === '' || $rawCode === null || trim($rawCode) === '') {
                $log(sprintf('SKIP (no code): %s', $filePath));
                continue;
            }

            $fullPath = $workdir . '/' . $filePath;
            if (!file_exists($fullPath)) {
                $log(sprintf('SKIP (file not found): %s', $fullPath));
                continue;
            }

            $log(sprintf('Calling AI for: %s (line %s, rawCode: %d chars)', $filePath, $finding->getLineNumber() ?? '?', \strlen($rawCode)));
            $apiCalls++;

            $fixedCode = $this->aiFixService->generateFix($finding);
            if ($fixedCode === null || trim($fixedCode) === '') {
                $log('SKIP (AI returned null)');
                continue;
            }

            $log(sprintf('AI returned fix: %d chars', \strlen($fixedCode)));

            $fileContent = file_get_contents($fullPath);
            if ($fileContent === false) {
                $log('SKIP (cannot read file)');
                continue;
            }

            $lineNumber = $finding->getLineNumber();
            if ($lineNumber !== null) {
                // Replace the specific line using the line number
                $lines = explode("\n", $fileContent);
                $lineIndex = $lineNumber - 1;
                if (isset($lines[$lineIndex])) {
                    $lines[$lineIndex] = $fixedCode;
                    $newContent = implode("\n", $lines);
                    file_put_contents($fullPath, $newContent);
                    $applied++;
                    $log(sprintf('APPLIED fix #%d to %s:%d', $applied, $filePath, $lineNumber));
                } else {
                    $log(sprintf('SKIP (line %d out of range, file has %d lines)', $lineNumber, \count($lines)));
                }
            } else {
                // Fallback: try str_replace for findings without line numbers
                $newContent = str_replace(trim($rawCode), $fixedCode, $fileContent);
                if ($newContent !== $fileContent) {
                    file_put_contents($fullPath, $newContent);
                    $applied++;
                    $log(sprintf('APPLIED fix #%d to %s', $applied, $filePath));
                } else {
                    $log(sprintf('SKIP (str_replace no match) rawCode: [%s]', substr(trim($rawCode), 0, 80)));
                }
            }
        }

        $log(sprintf('Done: %d API calls, %d fixes applied', $apiCalls, $applied));
    }

    /**
     * Creates a Pull Request on GitHub via the API.
     * Supports cross-repo PRs when a fork is used.
     */
    private function createPullRequest(string $repoUrl, string $branchName, Scan $scan, string $token = '', ?string $forkFullName = null): ?string
    {
        if ($token === '') {
            return null;
        }

        $ownerRepo = $this->parseOwnerRepo($repoUrl);
        if ($ownerRepo === null) {
            return null;
        }

        [$owner, $repo] = $ownerRepo;

        $title = sprintf('[SecureScan] Security fixes for scan %s', substr($scan->getId(), 0, 8));
        $body = sprintf(
            "Automated security remediations generated by SecureScan.\n\n"
            . "**Scan score:** %s/100\n"
            . "**Findings:** %d\n\n"
            . "This PR contains AI-generated code fixes and remediation documentation.",
            $scan->getGlobalScore() ?? 'N/A',
            $scan->getFindings()->count()
        );

        $baseBranch = $this->detectDefaultBranch($owner, $repo, $token);

        // For cross-repo PR: head = "forkOwner:branchName"
        $head = $branchName;
        if ($forkFullName !== null) {
            $forkOwner = explode('/', $forkFullName)[0] ?? '';
            if ($forkOwner !== '') {
                $head = sprintf('%s:%s', $forkOwner, $branchName);
            }
        }

        try {
            $response = $this->httpClient->request('POST', sprintf('https://api.github.com/repos/%s/%s/pulls', $owner, $repo), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $token),
                    'Accept' => 'application/vnd.github+json',
                ],
                'json' => [
                    'title' => $title,
                    'body' => $body,
                    'head' => $head,
                    'base' => $baseBranch,
                ],
            ]);

            $data = $response->toArray();

            return $data['html_url'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detects the default branch of a GitHub repository.
     */
    private function detectDefaultBranch(string $owner, string $repo, string $token): string
    {
        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/%s', $owner, $repo), [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $token),
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);

            $data = $response->toArray();

            return $data['default_branch'] ?? 'main';
        } catch (\Throwable) {
            return 'main';
        }
    }

    /**
     * Extracts owner and repo name from a GitHub URL.
     *
     * Supports: https://github.com/owner/repo, https://github.com/owner/repo.git
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseOwnerRepo(string $repoUrl): ?array
    {
        if (preg_match('#github\.com[/:]([^/]+)/([^/.]+?)(?:\.git)?$#', $repoUrl, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return null;
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

    private function injectTokenInRemoteUrl(string $workdir, string $token = ''): void
    {
        if ($token === '') {
            return;
        }

        $process = new Process(['git', 'remote', 'get-url', 'origin'], $workdir);
        $process->run();
        $currentUrl = trim($process->getOutput());

        if ($currentUrl === '' || !str_starts_with($currentUrl, 'https://')) {
            return;
        }

        // Strip any existing auth from the URL before injecting
        $cleanUrl = preg_replace('#^https://[^@]+@#', 'https://', $currentUrl);

        $authenticatedUrl = preg_replace(
            '#^https://#',
            sprintf('https://x-access-token:%s@', $token),
            $cleanUrl
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
        // Delete branch if it already exists (e.g. from a previous failed attempt)
        $process = new Process(['git', 'branch', '-D', $branchName], $workdir);
        $process->run();
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

    private function commitChanges(string $workdir, Scan $scan): bool
    {
        $this->runGit(['git', 'add', '-A'], $workdir);

        // Check if there are staged changes before committing
        $diffProcess = new Process(['git', 'diff', '--cached', '--quiet'], $workdir);
        $diffProcess->run();
        if ($diffProcess->isSuccessful()) {
            // No changes staged — nothing to commit
            return false;
        }

        $message = sprintf(
            "[SecureScan] Security remediations for scan %s\n\nAutomated security fix documentation generated by SecureScan.\nScan score: %s/100\nFindings: %d",
            substr($scan->getId(), 0, 8),
            $scan->getGlobalScore() ?? 'N/A',
            $scan->getFindings()->count()
        );

        $this->runGit(['git', 'commit', '-m', $message], $workdir);

        return true;
    }

    private function pushBranch(string $workdir, string $branchName, string $token = ''): void
    {
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
