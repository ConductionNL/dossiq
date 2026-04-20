<?php

/**
 * Procest Reporting Controller
 *
 * REST API controller for management reporting functionality.
 * Provides endpoints for generating, filtering, and exporting reports.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
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

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for reporting endpoints.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-7
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param string           $appName          The app name
     * @param IRequest         $request          The request
     * @param ReportingService $reportingService Reporting service
     * @param LoggerInterface  $logger           Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ReportingService $reportingService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Get filtered doorlooptijd report.
     *
     * @param string $caseType  Case type filter
     * @param string $team      Team filter
     * @param string $startDate Start date filter
     * @param string $endDate   End date filter
     * @param string $status    Status filter
     *
     * @return JSONResponse Report data
     *
     * @NoAdminRequired
     * @spec            openspec/changes/doorlooptijd-dashboard/tasks.md#task-7
     */
    public function getReport(
        string $caseType='',
        string $team='',
        string $startDate='',
        string $endDate='',
        string $status=''
    ): JSONResponse {
        try {
            // Build filters from parameters
            $filters = [];

            if (!empty($caseType)) {
                $filters['caseType'] = $caseType;
            }

            if (!empty($team)) {
                $filters['team'] = $team;
            }

            if (!empty($startDate)) {
                $filters['startDate'] = $startDate;
            } else {
                $filters['startDate'] = date('Y-m-d', strtotime('-90 days'));
            }

            if (!empty($endDate)) {
                $filters['endDate'] = $endDate;
            } else {
                $filters['endDate'] = date('Y-m-d');
            }

            if (!empty($status)) {
                $filters['status'] = $status;
            }

            // Generate report
            $report = $this->reportingService->generateReport($filters);

            // Apply filters to case data
            if (!empty($filters)) {
                $report['data'] = $this->reportingService->applyFilters(
                    $report['data'],
                    $filters
                );
            }

            return new JSONResponse($report);
        } catch (\Exception $e) {
            $this->logger->error('Error generating report: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'An error occurred processing your request'],
                500
            );
        }//end try
    }//end getReport()

    /**
     * Export report as CSV or Excel.
     *
     * @param string $format    Export format: csv or xlsx
     * @param string $caseType  Case type filter
     * @param string $team      Team filter
     * @param string $startDate Start date filter
     * @param string $endDate   End date filter
     * @param string $status    Status filter
     *
     * @return DataDownloadResponse|JSONResponse Export file or error
     *
     * @NoAdminRequired
     * @spec            openspec/changes/doorlooptijd-dashboard/tasks.md#task-7
     */
    public function export(
        string $format='csv',
        string $caseType='',
        string $team='',
        string $startDate='',
        string $endDate='',
        string $status=''
    ) {
        try {
            // Validate format
            if (!in_array($format, ['csv', 'xlsx'])) {
                return new JSONResponse(
                    ['error' => 'Invalid format. Must be csv or xlsx'],
                    400
                );
            }

            // Build filters
            $filters = [];
            if (!empty($caseType)) {
                $filters['caseType'] = $caseType;
            }

            if (!empty($team)) {
                $filters['team'] = $team;
            }

            if (!empty($startDate)) {
                $filters['startDate'] = $startDate;
            }

            if (!empty($endDate)) {
                $filters['endDate'] = $endDate;
            }

            if (!empty($status)) {
                $filters['status'] = $status;
            }

            // Generate report
            $report = $this->reportingService->generateReport($filters);

            // Prepare export data
            $exportData = $this->reportingService->prepareExportData($report, $format);

            // Convert to appropriate format
            if ($format === 'csv') {
                $content  = $this->generateCsv($exportData);
                $filename = 'doorlooptijd-report-'.date('Y-m-d-His').'.csv';
            } else {
                // For now, return CSV. Proper XLSX would require a library like PhpSpreadsheet
                $content  = $this->generateCsv($exportData);
                $filename = 'doorlooptijd-report-'.date('Y-m-d-His').'.xlsx';
            }

            return new DataDownloadResponse($content, $filename, 'application/octet-stream');
        } catch (\Exception $e) {
            $this->logger->error('Error exporting report: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'An error occurred processing your request'],
                500
            );
        }//end try
    }//end export()

    /**
     * Get available filter options.
     *
     * @return JSONResponse Filter options
     *
     * @NoAdminRequired
     * @spec            openspec/changes/doorlooptijd-dashboard/tasks.md#task-7
     */
    public function getFilterOptions(): JSONResponse
    {
        try {
            $options = $this->reportingService->getFilterOptions();
            return new JSONResponse($options);
        } catch (\Exception $e) {
            $this->logger->error('Error getting filter options: '.$e->getMessage());
            return new JSONResponse(
                ['error' => 'An error occurred processing your request'],
                500
            );
        }
    }//end getFilterOptions()

    /**
     * Generate CSV content from export data.
     *
     * @param array<string, mixed> $exportData Export data structure
     *
     * @return string CSV content
     */
    private function generateCsv(array $exportData): string
    {
        $csv = '';

        // Add header with title and generation date
        $csv .= "Doorlooptijd Management Report\n";
        $csv .= "Generated: ".($exportData['metadata']['generatedAt'] ?? date('Y-m-d H:i:s'))."\n";
        $csv .= "\n";

        // Add filters applied
        $csv .= "Filters Applied:\n";
        if (!empty($exportData['metadata']['filters'])) {
            foreach ($exportData['metadata']['filters'] as $key => $value) {
                $csv .= $key.": ".$value."\n";
            }
        }

        $csv .= "\n";

        // Add summary statistics
        $csv .= "Summary Statistics\n";
        if (!empty($exportData['summary'])) {
            foreach ($exportData['summary'] as $key => $value) {
                if (is_array($value)) {
                    $csv .= $key.":\n";
                    foreach ($value as $subKey => $subValue) {
                        $csv .= "  ".$subKey.": ".$subValue."\n";
                    }
                } else {
                    $csv .= $key.": ".$value."\n";
                }
            }
        }

        $csv .= "\n";

        // Add case data table
        $csv .= "Case Details\n";
        if (!empty($exportData['csvHeaders'])) {
            $csv .= implode(',', $exportData['csvHeaders'])."\n";
        }

        if (!empty($exportData['caseData'])) {
            foreach ($exportData['caseData'] as $case) {
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
                            // Escape quotes and wrap in quotes if contains comma
                            return '"'.str_replace('"', '""', $val).'"';
                        },
                        $row
                        )
                        )."\n";
            }//end foreach
        }//end if

        return $csv;
    }//end generateCsv()
}//end class
