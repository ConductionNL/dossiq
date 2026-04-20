<?php

/**
 * Procest Export Service
 *
 * Service for exporting reports in various formats (CSV, XLSX).
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for exporting report data to various formats.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
 */
class ExportService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Generate CSV export from report data.
     *
     * @param array<string, mixed> $reportData The report data
     * @param array<string, mixed> $filters    Applied filters
     *
     * @return string CSV content
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
     */
    public function generateCsv(array $reportData, array $filters): string
    {
        $this->logger->debug('Generating CSV export');

        $csv = '';

        // Add header with title and generation date.
        $csv .= "Doorlooptijd Management Report\n";
        $csv .= "Generated: ".date('Y-m-d H:i:s')."\n";
        $csv .= "\n";

        // Add filters applied.
        $csv .= "Filters Applied:\n";
        foreach ($filters as $key => $value) {
            $csv .= ucfirst($key).": ".$value."\n";
        }

        $csv .= "\n";

        // Add summary statistics.
        $csv .= "Summary Statistics\n";
        if (!empty($reportData['summary'])) {
            $this->appendSummaryToCsv($csv, $reportData['summary']);
        }

        $csv .= "\n";

        // Add case data table.
        $csv .= "Case Details\n";
        $csv .= implode(
                ',',
                [
                    'Case ID',
                    'Case Type',
                    'Created',
                    'Closed',
                    'Doorlooptijd (days)',
                    'SLA Target (days)',
                    'SLA Status',
                    'Team',
                    'Assignee',
                    'Status',
                ]
                )."\n";

        if (!empty($reportData['data'])) {
            foreach ($reportData['data'] as $case) {
                $row  = [
                    $case['caseId'] ?? '',
                    $case['caseType'] ?? '',
                    $case['createdAt'] ?? '',
                    $case['closedAt'] ?? '',
                    $case['doorlooptijd'] ?? '',
                    $case['slaTarget'] ?? '',
                    $case['slaStatus'] ?? '',
                    $case['team'] ?? '',
                    $case['assignee'] ?? '',
                    $case['status'] ?? '',
                ];
                $csv .= implode(
                        ',',
                        array_map(
                        function ($val) {
                            // Escape quotes and wrap in quotes if contains comma.
                            return '"'.str_replace('"', '""', $val).'"';
                        },
                        $row
                        )
                        )."\n";
            }//end foreach
        }//end if

        return $csv;
    }//end generateCsv()

    /**
     * Generate Excel (XLSX) export from report data.
     *
     * Note: Full XLSX support requires PhpSpreadsheet library.
     * For now, returns CSV format.
     *
     * @param array<string, mixed> $reportData The report data
     * @param array<string, mixed> $filters    Applied filters
     *
     * @return string Excel-compatible content (currently CSV)
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
     */
    public function generateExcel(array $reportData, array $filters): string
    {
        $this->logger->debug('Generating Excel export');

        // Placeholder: with PhpSpreadsheet, would generate actual XLSX
        // For now, return CSV which Excel can import
        return $this->generateCsv($reportData, $filters);
    }//end generateExcel()

    /**
     * Export report with all applied filters included.
     *
     * @param array<string, mixed> $reportData Report data
     * @param array<string, mixed> $filters    Applied filters
     * @param string               $format     Export format (csv, xlsx)
     *
     * @return string Export content
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
     */
    public function export(
        array $reportData,
        array $filters,
        string $format='csv'
    ): string {
        $this->logger->info('Exporting report in format: '.$format);

        if ($format === 'xlsx') {
            return $this->generateExcel($reportData, $filters);
        }

        return $this->generateCsv($reportData, $filters);
    }//end export()

    /**
     * Append summary data to CSV content.
     *
     * @param string               $csv     CSV content reference
     * @param array<string, mixed> $summary Summary data
     *
     * @return void
     */
    private function appendSummaryToCsv(string &$csv, array $summary): void
    {
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                $csv .= $key.":\n";
                foreach ($value as $subKey => $subValue) {
                    if (is_array($subValue)) {
                        $csv .= "  ".$subKey.":\n";
                        foreach ($subValue as $k => $v) {
                            $csv .= "    ".$k.": ".$v."\n";
                        }
                    } else {
                        $csv .= "  ".$subKey.": ".$subValue."\n";
                    }
                }
            } else {
                $csv .= $key.": ".$value."\n";
            }
        }
    }//end appendSummaryToCsv()

    /**
     * Get available export formats.
     *
     * @return array<int, string> List of supported formats
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
     */
    public function getAvailableFormats(): array
    {
        return ['csv', 'xlsx'];
    }//end getAvailableFormats()

    /**
     * Validate export format.
     *
     * @param string $format The format to validate
     *
     * @return bool True if format is supported
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-15
     */
    public function isFormatSupported(string $format): bool
    {
        return in_array($format, $this->getAvailableFormats());
    }//end isFormatSupported()
}//end class
