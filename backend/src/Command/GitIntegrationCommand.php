<?php

namespace App\Command;

use App\Entity\Scan;
use App\Service\GitIntegrationService;
use App\Service\ReportGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:git-integration',
    description: 'Applique les remediations Git et genere un rapport PDF pour un scan existant.',
)]
class GitIntegrationCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GitIntegrationService $gitIntegration,
        private readonly ReportGenerator $reportGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('scan-id', InputArgument::REQUIRED, 'UUID du scan a traiter')
            ->addOption('report-dir', null, InputOption::VALUE_REQUIRED, 'Repertoire de sortie pour le rapport PDF', '/var/www/html/public/reports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scanId = (string) $input->getArgument('scan-id');
        $reportDir = (string) $input->getOption('report-dir');

        $scan = $this->entityManager->getRepository(Scan::class)->find($scanId);
        if (!$scan instanceof Scan) {
            $output->writeln(sprintf('<error>Scan introuvable : %s</error>', $scanId));
            return Command::FAILURE;
        }

        $workdir = $scan->getWorkdir();
        if ($workdir === null || !is_dir($workdir)) {
            $output->writeln('<error>Workdir introuvable pour ce scan. Le repertoire a peut-etre ete nettoye.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Scan ID    : %s</info>', $scan->getId()));
        $output->writeln(sprintf('<info>Workdir    : %s</info>', $workdir));
        $output->writeln(sprintf('<info>Findings   : %d</info>', $scan->getFindings()->count()));
        $output->writeln('');

        // Git integration
        $output->writeln('<info>Application des remediations Git...</info>');
        try {
            $result = $this->gitIntegration->applyAndPush($scan, $workdir);
            $branch = $result['branch'];
            $prUrl = $result['prUrl'];

            if ($branch !== null) {
                $output->writeln(sprintf('<comment>Branche creee : %s</comment>', $branch));

                $token = $_ENV['GIT_TOKEN'] ?? $_SERVER['GIT_TOKEN'] ?? '';
                if ($token !== '') {
                    $output->writeln('<comment>Push effectue vers le depot distant.</comment>');
                } else {
                    $output->writeln('<comment>GIT_TOKEN non configure : commit local uniquement (pas de push).</comment>');
                }

                if ($prUrl !== null) {
                    $output->writeln(sprintf('<comment>Pull Request creee : %s</comment>', $prUrl));
                }
            } else {
                $output->writeln('<comment>Aucune remediation pending a appliquer.</comment>');
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Erreur Git : %s</error>', $e->getMessage()));
        }

        // PDF report generation
        $output->writeln('');
        $output->writeln('<info>Generation du rapport PDF...</info>');
        try {
            $reportPath = $this->reportGenerator->generatePdfReport($scan, $reportDir);
            $output->writeln(sprintf('<comment>Rapport genere : %s</comment>', $reportPath));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Erreur rapport : %s</error>', $e->getMessage()));
        }

        $output->writeln('');
        $output->writeln('<info>Terminee.</info>');

        return Command::SUCCESS;
    }
}
