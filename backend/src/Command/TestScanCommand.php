<?php

namespace App\Command;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\ScanManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commande de test pour déclencher manuellement un scan complet sur un dépôt Git.
 *
 * Utile pour valider de bout en bout le pipeline de sécurité (`ScanManager`)
 * directement depuis la ligne de commande, sans passer par l'API ou le frontend.
 */
#[AsCommand(
    name: 'app:test-scan',
    description: 'Lance un scan de sécurité sur un dépôt Git et enregistre les résultats en base.',
)]
class TestScanCommand extends Command
{
    public function __construct(
        private readonly ScanManager $scanManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectRepository $projectRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'URL du dépôt Git à scanner')
            ->addArgument('name', InputArgument::OPTIONAL, 'Nom du projet (optionnel)');
    }

    /**
     * Crée ou réutilise un `Project` à partir de l'URL fournie, lance un scan via
     * `ScanManager` puis affiche un résumé synthétique (statut, score global, nb de findings).
     *
     * Cette commande est pensée comme un scénario "end-to-end" minimal pour vérifier que
     * la chaîne complète (clone du dépôt, outils externes, persistance Doctrine) fonctionne.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $repositoryUrl = (string) $input->getArgument('url');
            $name = $input->getArgument('name');

            if (!\is_string($name) || $name === '') {
                $name = 'Project for ' . $repositoryUrl;
            }

            $output->writeln('<info>Recherche ou création du projet...</info>');

            $project = $this->projectRepository->findOneBy(['repositoryUrl' => $repositoryUrl]);
            if (!$project instanceof Project) {
                $project = new Project();
                $project
                    ->setName($name)
                    ->setRepositoryUrl($repositoryUrl);

                $this->entityManager->persist($project);
                $this->entityManager->flush();

                $output->writeln('<comment>Nouveau projet créé avec l\'id : ' . $project->getId() . '</comment>');
            } else {
                $output->writeln('<comment>Projet existant trouvé avec l\'id : ' . $project->getId() . '</comment>');
            }

            $output->writeln('<info>Lancement du scan...</info>');

            $scan = $this->scanManager->startScan($project);

            $output->writeln('');
            $output->writeln('<info>Scan terminé.</info>');
            $output->writeln('Scan ID      : ' . $scan->getId());
            $output->writeln('Statut       : ' . $scan->getStatus());
            $output->writeln('Score global : ' . ($scan->getGlobalScore() ?? 'N/A'));
            $output->writeln('Nb findings  : ' . $scan->getFindings()->count());

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Erreur pendant le scan : ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }
}

