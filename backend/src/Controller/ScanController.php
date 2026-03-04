<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Scan;
use App\Entity\User;
use App\Repository\ScanRepository;
use App\Service\GitIntegrationService;
use App\Service\ReportGenerator;
use App\Service\ScanManager;
use App\Service\TokenEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/scans')]
class ScanController extends AbstractController
{
    public function __construct(
        private readonly ScanManager $scanManager,
        private readonly ScanRepository $scanRepository,
        private readonly GitIntegrationService $gitIntegrationService,
        private readonly ReportGenerator $reportGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenEncryptor $tokenEncryptor,
    ) {
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $repoUrl = $payload['repositoryUrl'] ?? $payload['repoUrl'] ?? null;
        $projectId = $payload['projectId'] ?? null;

        if (!$repoUrl && !$projectId) {
            return $this->json(['error' => 'repositoryUrl or projectId is required'], 400);
        }

        if ($repoUrl && !preg_match('#^https://#i', $repoUrl)) {
            return $this->json(['error' => 'Only https:// repository URLs are allowed'], 400);
        }

        if ($projectId) {
            $project = $this->entityManager->getRepository(Project::class)->find($projectId);
            if (!$project) {
                return $this->json(['error' => 'Project not found'], 404);
            }
            $this->checkProjectOwnership($project);
        } else {
            // Find or create project from URL — scoped to current user
            $project = $this->entityManager->getRepository(Project::class)
                ->findOneBy(['repositoryUrl' => $repoUrl, 'owner' => $this->getUser()]);

            if (!$project) {
                $name = $this->extractProjectName($repoUrl);
                $project = new Project();
                $project->setName($name);
                $project->setRepositoryUrl($repoUrl);
                $project->setOwner($this->getUser());
                $this->entityManager->persist($project);
                $this->entityManager->flush();
            }
        }

        $user = $this->getUser();
        $token = $user instanceof User ? $this->tokenEncryptor->decrypt($user->getGithubToken()) : null;

        $scan = $this->scanManager->startScan($project, $token);

        return $this->json([
            'id' => $scan->getId(),
            'projectId' => $project->getId(),
            'status' => $scan->getStatus(),
            'globalScore' => $scan->getGlobalScore(),
            'executedAt' => $scan->getExecutedAt()->format('c'),
            'findingsCount' => $scan->getFindings()->count(),
        ], 201);
    }

    private const MAX_ARCHIVE_SIZE_BYTES = 50 * 1024 * 1024; // 50 MB

    #[Route('/from-archive', methods: ['POST'], name: 'scans_from_archive')]
    public function fromArchive(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('archive');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'A valid ZIP archive is required (field name: archive)'], 400);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'zip') {
            return $this->json(['error' => 'Only .zip archives are accepted'], 400);
        }

        if ($file->getSize() > self::MAX_ARCHIVE_SIZE_BYTES) {
            return $this->json(['error' => 'Archive size must not exceed 50 MB'], 400);
        }

        $baseDir = '/tmp/scans';
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0777, true);
        }

        $workdir = $baseDir . '/' . Uuid::v4()->toRfc4122();
        @mkdir($workdir, 0777, true);

        $zip = new \ZipArchive();
        if ($zip->open($file->getPathname(), \ZipArchive::RDONLY) !== true) {
            return $this->json(['error' => 'Invalid or corrupted ZIP file'], 400);
        }

        $zip->extractTo($workdir);
        $zip->close();

        // Ensure extracted files are readable by the process (e.g. www-data) and child tools
        $this->chmodRecursive($workdir, 0755);

        $entries = array_values(array_diff(scandir($workdir), ['.', '..']));
        $effectiveWorkdir = $workdir;

        if (\count($entries) === 1 && is_dir($workdir . '/' . $entries[0])) {
            $effectiveWorkdir = $workdir . '/' . $entries[0];
        } else {
            // Multiple entries: look for a subdir that contains package.json or composer.json (project root)
            foreach ($entries as $entry) {
                $path = $workdir . '/' . $entry;
                if (is_dir($path) && (file_exists($path . '/package.json') || file_exists($path . '/composer.json'))) {
                    $effectiveWorkdir = $path;
                    break;
                }
            }
        }

        $originalName = $file->getClientOriginalName();
        $project = new Project();
        $project->setName('Upload: ' . $originalName);
        $project->setRepositoryUrl('upload:' . $originalName);
        $project->setOwner($this->getUser());
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        $scan = $this->scanManager->startScanFromWorkdir($project, $effectiveWorkdir);

        return $this->json([
            'id' => $scan->getId(),
            'projectId' => $project->getId(),
            'status' => $scan->getStatus(),
            'globalScore' => $scan->getGlobalScore(),
            'executedAt' => $scan->getExecutedAt()->format('c'),
            'findingsCount' => $scan->getFindings()->count(),
        ], 201);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $scan = $this->scanRepository->find($id);

        if (!$scan) {
            return $this->json(['error' => 'Scan not found'], 404);
        }

        $this->checkScanOwnership($scan);

        $findings = [];
        foreach ($scan->getFindings() as $finding) {
            $remediation = $finding->getRemediation();
            $findings[] = [
                'id' => $finding->getId(),
                'toolSource' => $finding->getToolSource(),
                'severity' => $finding->getSeverity(),
                'owaspCategory' => $finding->getOwaspCategory(),
                'filePath' => $finding->getFilePath(),
                'lineNumber' => $finding->getLineNumber(),
                'description' => $finding->getDescription(),
                'rawCode' => $finding->getRawCode(),
                'remediation' => $remediation ? [
                    'proposedFix' => $remediation->getProposedFix(),
                    'status' => $remediation->getStatus(),
                    'gitBranchName' => $remediation->getGitBranchName(),
                    'prUrl' => $remediation->getPrUrl(),
                ] : null,
            ];
        }

        return $this->json([
            'id' => $scan->getId(),
            'project' => [
                'id' => $scan->getProject()->getId(),
                'name' => $scan->getProject()->getName(),
                'repositoryUrl' => $scan->getProject()->getRepositoryUrl(),
            ],
            'executedAt' => $scan->getExecutedAt()->format('c'),
            'globalScore' => $scan->getGlobalScore(),
            'status' => $scan->getStatus(),
            'findings' => $findings,
        ]);
    }

    #[Route('/{id}/findings', methods: ['GET'])]
    public function findings(string $id, Request $request): JsonResponse
    {
        $scan = $this->scanRepository->find($id);

        if (!$scan) {
            return $this->json(['error' => 'Scan not found'], 404);
        }

        $this->checkScanOwnership($scan);

        $severity = $request->query->get('severity');
        $tool = $request->query->get('tool');
        $owasp = $request->query->get('owasp');

        $findings = [];
        foreach ($scan->getFindings() as $finding) {
            if ($severity && strtoupper($finding->getSeverity()) !== strtoupper($severity)) {
                continue;
            }
            if ($tool && $finding->getToolSource() !== $tool) {
                continue;
            }
            if ($owasp && $finding->getOwaspCategory() !== $owasp) {
                continue;
            }

            $remediation = $finding->getRemediation();
            $findings[] = [
                'id' => $finding->getId(),
                'toolSource' => $finding->getToolSource(),
                'severity' => $finding->getSeverity(),
                'owaspCategory' => $finding->getOwaspCategory(),
                'filePath' => $finding->getFilePath(),
                'lineNumber' => $finding->getLineNumber(),
                'description' => $finding->getDescription(),
                'rawCode' => $finding->getRawCode(),
                'remediation' => $remediation ? [
                    'proposedFix' => $remediation->getProposedFix(),
                    'status' => $remediation->getStatus(),
                    'gitBranchName' => $remediation->getGitBranchName(),
                    'prUrl' => $remediation->getPrUrl(),
                ] : null,
            ];
        }

        return $this->json($findings);
    }

    #[Route('/{id}/apply-fixes', methods: ['POST'])]
    public function applyFixes(string $id): JsonResponse
    {
        $scan = $this->scanRepository->find($id);

        if (!$scan) {
            return $this->json(['error' => 'Scan not found'], 404);
        }

        $this->checkScanOwnership($scan);

        $workdir = $scan->getWorkdir();
        if (!$workdir || !is_dir($workdir)) {
            return $this->json(['error' => 'Scan workdir not available'], 400);
        }

        if (str_starts_with($scan->getProject()->getRepositoryUrl(), 'upload:')) {
            return $this->json(['error' => 'Apply Fixes & PR is only available for scans from a Git repository, not for ZIP uploads'], 400);
        }

        $user = $this->getUser();
        $token = $user instanceof User ? $this->tokenEncryptor->decrypt($user->getGithubToken()) : null;

        $result = $this->gitIntegrationService->applyAndPush($scan, $workdir, $token);

        return $this->json([
            'branch' => $result['branch'],
            'prUrl' => $result['prUrl'],
        ]);
    }

    #[Route('/{id}/report', methods: ['GET'])]
    public function report(string $id): BinaryFileResponse|JsonResponse
    {
        $scan = $this->scanRepository->find($id);

        if (!$scan) {
            return $this->json(['error' => 'Scan not found'], 404);
        }

        $this->checkScanOwnership($scan);

        $outputDir = '/var/www/html/public/reports';
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $pdfPath = $this->reportGenerator->generatePdfReport($scan, $outputDir);

        $response = new BinaryFileResponse($pdfPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($pdfPath));
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }

    #[Route('/recent', methods: ['GET'], priority: 10)]
    public function recent(): JsonResponse
    {
        $user = $this->getUser();

        $scans = $this->entityManager->getRepository(Scan::class)
            ->createQueryBuilder('s')
            ->join('s.project', 'p')
            ->where('p.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('s.executedAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $data = array_map(fn ($scan) => [
            'id' => $scan->getId(),
            'project' => [
                'id' => $scan->getProject()->getId(),
                'name' => $scan->getProject()->getName(),
                'repositoryUrl' => $scan->getProject()->getRepositoryUrl(),
            ],
            'executedAt' => $scan->getExecutedAt()->format('c'),
            'globalScore' => $scan->getGlobalScore(),
            'status' => $scan->getStatus(),
            'findingsCount' => $scan->getFindings()->count(),
        ], $scans);

        return $this->json($data);
    }

    private function checkProjectOwnership(Project $project): void
    {
        if ($project->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function checkScanOwnership(Scan $scan): void
    {
        if ($scan->getProject()->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function extractProjectName(string $repoUrl): string
    {
        if (preg_match('#/([^/]+?)(?:\.git)?$#', $repoUrl, $matches)) {
            return $matches[1];
        }

        return 'Unknown Project';
    }

    private function chmodRecursive(string $dir, int $mode): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            @chmod($path, $mode);
            if (is_dir($path)) {
                $this->chmodRecursive($path, $mode);
            }
        }
    }
}
