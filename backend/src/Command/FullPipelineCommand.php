<?php

namespace App\Command;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\GitIntegrationService;
use App\Service\ReportGenerator;
use App\Service\ScanManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:full-pipeline',
    description: 'Execute le pipeline complet : scan, integration Git et generation du rapport PDF.',
)]
class FullPipelineCommand extends Command
{
    public function __construct(
        private readonly ScanManager $scanManager,
        private readonly GitIntegrationService $gitIntegration,
        private readonly ReportGenerator $reportGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectRepository $projectRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('repo-url', InputArgument::REQUIRED, 'URL du depot Git a scanner')
            ->addArgument('project-name', InputArgument::OPTIONAL, 'Nom du projet')
            ->addOption('report-dir', null, InputOption::VALUE_REQUIRED, 'Repertoire de sortie pour le rapport PDF', '/var/www/html/public/reports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoUrl = (string) $input->getArgument('repo-url');
        $projectName = $input->getArgument('project-name');
        $reportDir = (string) $input->getOption('report-dir');

        if (!\is_string($projectName) || $projectName === '') {
            $projectName = 'Project for ' . $repoUrl;
        }

        // 1. Create or find existing project
        $output->writeln('<info>=== ETAPE 1/3 : Preparation du projet ===</info>');

        $project = $this->projectRepository->findOneBy(['repositoryUrl' => $repoUrl]);
        if (!$project instanceof Project) {
            $project = new Project();
            $project
                ->setName($projectName)
                ->setRepositoryUrl($repoUrl);

            $this->entityManager->persist($project);
            $this->entityManager->flush();

            $output->writeln(sprintf('<comment>Nouveau projet cree : %s</comment>', $project->getId()));
        } else {
            $output->writeln(sprintf('<comment>Projet existant : %s</comment>', $project->getId()));
        }

        // 2. Run the scan
        $output->writeln('');
        $output->writeln('<info>=== ETAPE 2/3 : Scan de securite ===</info>');

        try {
            $scan = $this->scanManager->startScan($project);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Erreur pendant le scan : %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Scan ID      : %s', $scan->getId()));
        $output->writeln(sprintf('Statut       : %s', $scan->getStatus()));
        $output->writeln(sprintf('Score global : %s', $scan->getGlobalScore() ?? 'N/A'));
        $output->writeln(sprintf('Findings     : %d', $scan->getFindings()->count()));

        if ($scan->getStatus() === 'failed') {
            $output->writeln('<error>Le scan a echoue. Arret du pipeline.</error>');
            return Command::FAILURE;
        }

        // 3. Git integration + Report
        $output->writeln('');
        $output->writeln('<info>=== ETAPE 3/3 : Integration Git et rapport ===</info>');

        $workdir = $scan->getWorkdir();
        if ($workdir !== null && is_dir($workdir)) {
            try {
                $branch = $this->gitIntegration->applyAndPush($scan, $workdir);

                if ($branch !== null) {
                    $output->writeln(sprintf('<comment>Branche creee : %s</comment>', $branch));

                    $token = $_ENV['GIT_TOKEN'] ?? $_SERVER['GIT_TOKEN'] ?? '';
                    if ($token !== '') {
                        $output->writeln('<comment>Push effectue vers le depot distant.</comment>');
                    } else {
                        $output->writeln('<comment>GIT_TOKEN non configure : commit local uniquement.</comment>');
                    }
                } else {
                    $output->writeln('<comment>Aucune remediation a appliquer.</comment>');
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Erreur Git : %s</error>', $e->getMessage()));
            }
        } else {
            $output->writeln('<comment>Workdir indisponible, integration Git ignoree.</comment>');
        }

        // PDF report
        try {
            $reportPath = $this->reportGenerator->generatePdfReport($scan, $reportDir);
            $output->writeln(sprintf('<comment>Rapport PDF : %s</comment>', $reportPath));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Erreur rapport : %s</error>', $e->getMessage()));
        }

        $output->writeln('');
        $output->writeln('<info>=== Pipeline termine ===</info>');

        return Command::SUCCESS;
    }
}
