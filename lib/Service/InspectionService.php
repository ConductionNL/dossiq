<?php

/**
 * Procest Inspection Service
 *
 * Service for managing inspection checklists and recording inspection results.
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
 * Service for inspection checklist management and completion.
 */
class InspectionService
{


    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }


    /**
     * Create an inspection checklist template.
     *
     * @param array<string, mixed> $data Checklist template data
     *
     * @return array<string, mixed> Created checklist with ID
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    public function createChecklist(array $data): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('inspection_checklist_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Inspection checklist schema not configured');
        }

        $checklist = $objectService->saveObject($register, $schema, $data);

        $this->logger->info(
            'Inspection checklist created: ' . $checklist->getUuid(),
            ['app' => Application::APP_ID],
        );

        return [
            'id'    => $checklist->getUuid(),
            'title' => $data['title'] ?? '',
            'items' => $data['items'] ?? [],
        ];
    }


    /**
     * Record an inspection result (completed checklist).
     *
     * @param string               $caseId      The case UUID
     * @param string               $checklistId The checklist template UUID
     * @param string               $inspector   The inspector user ID
     * @param array<string, mixed> $results     Per-item results
     * @param string|null          $location    GPS coordinates (optional)
     *
     * @return array<string, mixed> The inspection result
     *
     * @throws \RuntimeException If OpenRegister unavailable
     */
    public function recordInspection(
        string $caseId,
        string $checklistId,
        string $inspector,
        array $results,
        ?string $location = null,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('inspection_result_schema');

        if (empty($register) === true || empty($schema) === true) {
            throw new \RuntimeException('Inspection result schema not configured');
        }

        // Calculate summary: count passed, failed, n/a items.
        $summary = $this->calculateSummary($results);

        $resultData = [
            'case'        => $caseId,
            'checklist'   => $checklistId,
            'inspector'   => $inspector,
            'date'        => date('Y-m-d\TH:i:s'),
            'location'    => $location,
            'results'     => json_encode($results),
            'outcome'     => $summary['failed'] > 0 ? 'niet_conform' : 'conform',
            'passedCount' => $summary['passed'],
            'failedCount' => $summary['failed'],
            'naCount'     => $summary['na'],
        ];

        $inspectionResult = $objectService->saveObject($register, $schema, $resultData);

        $this->logger->info(
            'Inspection recorded for case ' . $caseId . ': ' . $inspectionResult->getUuid()
            . ' (' . $resultData['outcome'] . ')',
            ['app' => Application::APP_ID],
        );

        return [
            'id'      => $inspectionResult->getUuid(),
            'outcome' => $resultData['outcome'],
            'summary' => $summary,
        ];
    }


    /**
     * Calculate inspection summary from item results.
     *
     * @param array<string, mixed> $results Per-item results
     *
     * @return array<string, int> Summary counts
     */
    private function calculateSummary(array $results): array
    {
        $summary = ['passed' => 0, 'failed' => 0, 'na' => 0];

        foreach ($results as $item) {
            $result = is_array($item) ? ($item['result'] ?? '') : (string) $item;
            switch ($result) {
                case 'ja':
                case 'pass':
                case 'passed':
                    $summary['passed']++;
                    break;
                case 'nee':
                case 'fail':
                case 'failed':
                    $summary['failed']++;
                    break;
                case 'nvt':
                case 'na':
                case 'not_applicable':
                    $summary['na']++;
                    break;
            }
        }

        return $summary;
    }


    /**
     * Look up the LHS (Landelijke Handhavingsstrategie) intervention.
     *
     * Maps ernst (severity) x gedrag (behavior) to a recommended intervention.
     *
     * @param string $ernst  Severity level (gering, aanzienlijk, ernstig)
     * @param string $gedrag Behavior classification (goedwillend, onverschillig, calculerend, crimineel)
     *
     * @return string Recommended intervention
     */
    public function lookupLhsIntervention(string $ernst, string $gedrag): string
    {
        $matrix = [
            'gering' => [
                'goedwillend'   => 'Waarschuwing',
                'onverschillig' => 'Last onder dwangsom',
                'calculerend'   => 'Last onder dwangsom + bestuurlijke boete',
                'crimineel'     => 'Last onder bestuursdwang + proces-verbaal',
            ],
            'aanzienlijk' => [
                'goedwillend'   => 'Last onder dwangsom',
                'onverschillig' => 'Last onder dwangsom + proces-verbaal',
                'calculerend'   => 'Last onder bestuursdwang + bestuurlijke boete',
                'crimineel'     => 'Last onder bestuursdwang + proces-verbaal + bestuurlijke boete',
            ],
            'ernstig' => [
                'goedwillend'   => 'Last onder bestuursdwang',
                'onverschillig' => 'Last onder bestuursdwang + proces-verbaal',
                'calculerend'   => 'Last onder bestuursdwang + bestuurlijke boete + proces-verbaal',
                'crimineel'     => 'Last onder bestuursdwang + bestuurlijke boete + proces-verbaal + sluiting',
            ],
        ];

        return $matrix[$ernst][$gedrag] ?? 'Waarschuwing';
    }
}
