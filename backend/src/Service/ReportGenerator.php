<?php

namespace App\Service;

use App\Entity\Scan;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class ReportGenerator
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * Genere un rapport PDF pour le scan et le place dans $outputDir.
     *
     * @return string Chemin absolu du fichier PDF genere
     */
    public function generatePdfReport(Scan $scan, string $outputDir): string
    {
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0777, true);
        }

        $data = $this->prepareScanData($scan);

        $html = $this->twig->render('report/security_report.html.twig', $data);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('securescan-report-%s-%s.pdf', substr($scan->getId(), 0, 8), date('Ymd-His'));
        $filepath = $outputDir . '/' . $filename;

        file_put_contents($filepath, $dompdf->output());

        return $filepath;
    }

    private function prepareScanData(Scan $scan): array
    {
        $findings = $scan->getFindings()->toArray();

        $severityCounts = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'INFO' => 0];
        $byOwasp = [];

        foreach ($findings as $finding) {
            $sev = strtoupper($finding->getSeverity());
            if (isset($severityCounts[$sev])) {
                $severityCounts[$sev]++;
            }

            $owasp = $finding->getOwaspCategory() ?? 'Non catégorisé';
            if (!isset($byOwasp[$owasp])) {
                $byOwasp[$owasp] = [];
            }
            $byOwasp[$owasp][] = $finding;
        }

        ksort($byOwasp);

        return [
            'scan' => $scan,
            'project' => $scan->getProject(),
            'findings' => $findings,
            'severityCounts' => $severityCounts,
            'byOwasp' => $byOwasp,
            'totalFindings' => \count($findings),
            'generatedAt' => new \DateTime(),
        ];
    }
}
