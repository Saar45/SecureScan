<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects')]
class ProjectController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $projects = $this->projectRepository->findBy(
            ['owner' => $this->getUser()],
            ['createdAt' => 'DESC'],
        );

        $data = array_map(fn (Project $p) => [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'repositoryUrl' => $p->getRepositoryUrl(),
            'mainBranch' => $p->getMainBranch(),
            'createdAt' => $p->getCreatedAt()->format('c'),
            'scanCount' => $p->getScans()->count(),
        ], $projects);

        return $this->json($data);
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $name = $payload['name'] ?? null;
        $repoUrl = $payload['repositoryUrl'] ?? null;

        if (!$name || !$repoUrl) {
            return $this->json(['error' => 'name and repositoryUrl are required'], 400);
        }

        $project = new Project();
        $project->setName($name);
        $project->setRepositoryUrl($repoUrl);
        $project->setOwner($this->getUser());

        if (isset($payload['mainBranch'])) {
            $project->setMainBranch($payload['mainBranch']);
        }

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $this->json([
            'id' => $project->getId(),
            'name' => $project->getName(),
            'repositoryUrl' => $project->getRepositoryUrl(),
            'mainBranch' => $project->getMainBranch(),
            'createdAt' => $project->getCreatedAt()->format('c'),
        ], 201);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $project = $this->projectRepository->find($id);

        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $this->checkOwnership($project);

        $scans = [];
        foreach ($project->getScans() as $scan) {
            $scans[] = [
                'id' => $scan->getId(),
                'executedAt' => $scan->getExecutedAt()->format('c'),
                'globalScore' => $scan->getGlobalScore(),
                'status' => $scan->getStatus(),
                'findingsCount' => $scan->getFindings()->count(),
            ];
        }

        return $this->json([
            'id' => $project->getId(),
            'name' => $project->getName(),
            'repositoryUrl' => $project->getRepositoryUrl(),
            'mainBranch' => $project->getMainBranch(),
            'createdAt' => $project->getCreatedAt()->format('c'),
            'scans' => $scans,
        ]);
    }

    private function checkOwnership(Project $project): void
    {
        if ($project->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
