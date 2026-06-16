<?php

/**
 * Procest Daily Sync Service.
 *
 * Assembles the offline daily-planning payload an inspector pre-downloads at
 * the start of a shift: the fieldInspection records planned for the requested
 * day (scoped to the requesting inspector), the referenced checklist templates,
 * and a download manifest (estimated size, map-tile bounds) the PWA uses to
 * show a progress indicator and a slow-connection warning.
 *
 * The schedule query is IDOR-safe: an inspector only ever receives their own
 * planned inspections.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the offline daily-planning sync payload for an inspector.
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
 *
 * @psalm-suppress UnusedClass
 */
class DailySyncService
{
    /**
     * The fieldInspection schema slug.
     */
    private const SCHEMA_FIELD_INSPECTION = 'fieldInspection';

    /**
     * The inspectionChecklist (template) schema slug.
     */
    private const SCHEMA_CHECKLIST = 'inspectionChecklist';

    /**
     * Estimated bytes per map tile used for the download-size estimate.
     */
    private const BYTES_PER_TILE = 24000;

    /**
     * Map-tile zoom levels pre-downloaded around each case address.
     */
    private const TILE_ZOOM_LEVELS = [10, 11, 12, 13, 14, 15, 16, 17, 18];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Bridge to OpenRegister.
     * @param LoggerInterface $logger          Logger.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build the daily sync payload for an inspector and date.
     *
     * @param string      $inspectorId The requesting inspector's user UID.
     * @param string|null $date        Target date (Y-m-d); defaults to today.
     *
     * @return array{
     *     date: string,
     *     inspectorRef: string,
     *     cases: array<int, array<string, mixed>>,
     *     checklists: array<int, array<string, mixed>>,
     *     manifest: array<string, mixed>,
     *     readyOffline: bool,
     *     expiresAt: string
     * }
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
     */
    public function getDailyPayload(string $inspectorId, ?string $date=null): array
    {
        $targetDate = ($date ?? (new DateTimeImmutable())->format('Y-m-d'));
        $cases      = $this->getScheduledInspections(inspectorId: $inspectorId, date: $targetDate);
        $checklists = $this->getReferencedChecklists(cases: $cases);
        $manifest   = $this->buildManifest(cases: $cases, checklists: $checklists);

        $expiresAt = (new DateTimeImmutable())->modify('+24 hours')->format(DateTimeInterface::ATOM);

        return [
            'date'         => $targetDate,
            'inspectorRef' => $inspectorId,
            'cases'        => $cases,
            'checklists'   => $checklists,
            'manifest'     => $manifest,
            'readyOffline' => (empty($cases) === false),
            'expiresAt'    => $expiresAt,
        ];
    }//end getDailyPayload()

    /**
     * Fetch the inspector's planned inspections for the target date.
     *
     * @param string $inspectorId The inspector's user UID.
     * @param string $date        Target date (Y-m-d).
     *
     * @return array<int, array<string, mixed>> The planned inspections.
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
     */
    public function getScheduledInspections(string $inspectorId, string $date): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register'     => $register,
                        'schema'       => self::SCHEMA_FIELD_INSPECTION,
                        'inspectorRef' => $inspectorId,
                    ],
                    'limit'   => 200,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to load scheduled inspections: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $inspections = $this->normalizeResults(results: $results);

        // IDOR guard + date filter applied server-side.
        return array_values(
            array_filter(
                $inspections,
                static function (array $inspection) use ($inspectorId, $date): bool {
                    if (((string) ($inspection['inspectorRef'] ?? '')) !== $inspectorId) {
                        return false;
                    }

                    $scheduled = substr((string) ($inspection['scheduledAt'] ?? ''), 0, 10);
                    return $scheduled === $date;
                }
            )
        );
    }//end getScheduledInspections()

    /**
     * Fetch the checklist templates referenced by the supplied inspections.
     *
     * @param array<int, array<string, mixed>> $cases The planned inspections.
     *
     * @return array<int, array<string, mixed>> The referenced checklist templates.
     */
    private function getReferencedChecklists(array $cases): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        // Collect the distinct checklist references actually used by today's
        // inspections so only the needed templates are pre-downloaded.
        $referencedIds = [];
        foreach ($cases as $case) {
            foreach (['checklistTemplateRef', 'checklistRef', 'checklistId'] as $key) {
                $ref = ((string) ($case[$key] ?? ''));
                if ($ref !== '') {
                    $referencedIds[$ref] = true;
                }
            }
        }

        $register = $this->settingsService->getConfigValue('register');

        try {
            $results = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $register,
                        'schema'   => self::SCHEMA_CHECKLIST,
                    ],
                    'limit'   => 200,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to load checklist templates: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $templates = $this->normalizeResults(results: $results);

        // When no inspection references a checklist, fall back to returning all
        // active templates so the inspector still has the catalogue offline.
        if ($referencedIds === []) {
            return $templates;
        }

        return array_values(
            array_filter(
                $templates,
                static function (array $template) use ($referencedIds): bool {
                    $id = ((string) ($template['id'] ?? ''));
                    return isset($referencedIds[$id]) === true;
                }
            )
        );
    }//end getReferencedChecklists()

    /**
     * Build the download manifest (size estimate + map-tile bounds).
     *
     * @param array<int, array<string, mixed>> $cases      The planned inspections.
     * @param array<int, array<string, mixed>> $checklists The checklist templates.
     *
     * @return array{
     *     caseCount: int,
     *     checklistCount: int,
     *     estimatedTiles: int,
     *     estimatedBytes: int,
     *     zoomLevels: array<int, int>,
     *     slowConnectionWarning: bool
     * }
     *
     * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-5
     */
    public function buildManifest(array $cases, array $checklists): array
    {
        // Estimate ~120 tiles per case across the configured zoom range.
        $tilesPerCase   = 120;
        $estimatedTiles = (count($cases) * $tilesPerCase);
        $estimatedBytes = ($estimatedTiles * self::BYTES_PER_TILE);

        // Add a flat payload estimate for the JSON records themselves.
        $estimatedBytes += ((count($cases) + count($checklists)) * 4096);

        // Warn when the payload is large enough to be slow on a 3G link.
        $slowLinkWarning = ($estimatedBytes > (30 * 1024 * 1024));

        return [
            'caseCount'             => count($cases),
            'checklistCount'        => count($checklists),
            'estimatedTiles'        => $estimatedTiles,
            'estimatedBytes'        => $estimatedBytes,
            'zoomLevels'            => self::TILE_ZOOM_LEVELS,
            'slowConnectionWarning' => $slowLinkWarning,
        ];
    }//end buildManifest()

    /**
     * Normalize an OpenRegister findAll result into a flat list of arrays.
     *
     * @param mixed $results The raw findAll result.
     *
     * @return array<int, array<string, mixed>> The normalized records.
     */
    private function normalizeResults(mixed $results): array
    {
        if (is_array($results) === false) {
            return [];
        }

        if (isset($results['results']) === true && is_array($results['results']) === true) {
            $results = $results['results'];
        }

        $records = [];
        foreach ($results as $entry) {
            if (is_array($entry) === true) {
                $records[] = $entry;
            } else if (is_object($entry) === true) {
                if (method_exists($entry, 'jsonSerialize') === true) {
                    $serialized = $entry->jsonSerialize();
                    if (is_array($serialized) === true) {
                        $records[] = $serialized;
                        continue;
                    }
                }

                $records[] = get_object_vars($entry);
            }
        }

        return $records;
    }//end normalizeResults()
}//end class
