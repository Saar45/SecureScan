<?php

namespace App\Tests\Unit\Service;

use App\Entity\Finding;
use App\Entity\Project;
use App\Entity\Scan;
use App\Service\ReportGenerator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class ReportGeneratorTest extends TestCase
{
    public function testPrepareScanDataStructure(): void
    {
        $project = new Project();
        $project->setName('Test Project')->setRepositoryUrl('https://github.com/org/repo');

        $scan = new Scan();
        $scan->setProject($project)
            ->setGlobalScore('85.00')
            ->setStatus('completed');

        $finding = new Finding();
        $finding->setScan($scan)
            ->setSeverity('HIGH')
            ->setOwaspCategory('A03')
            ->setToolSource('semgrep')
            ->setFilePath('src/Example.php')
            ->setDescription('Test finding');
        $scan->addFinding($finding);

        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $name, array $data) {
            $this->assertArrayHasKey('scan', $data);
            $this->assertArrayHasKey('project', $data);
            $this->assertArrayHasKey('findings', $data);
            $this->assertArrayHasKey('severityCounts', $data);
            $this->assertArrayHasKey('byOwasp', $data);
            $this->assertArrayHasKey('totalFindings', $data);
            $this->assertSame(1, $data['totalFindings']);
            $this->assertArrayHasKey('HIGH', $data['severityCounts']);
            $this->assertSame(1, $data['severityCounts']['HIGH']);
            return '<html><body>Report</body></html>';
        });

        $generator = new ReportGenerator($twig);
        $outputDir = sys_get_temp_dir() . '/securescan-test-' . uniqid();
        @mkdir($outputDir, 0777, true);

        try {
            $path = $generator->generatePdfReport($scan, $outputDir);
            $this->assertFileExists($path);
            $this->assertStringContainsString('securescan-report-', basename($path));
            $this->assertStringEndsWith('.pdf', $path);
        } finally {
            if (is_dir($outputDir)) {
                array_map('unlink', glob($outputDir . '/*') ?: []);
                rmdir($outputDir);
            }
        }
    }
}
