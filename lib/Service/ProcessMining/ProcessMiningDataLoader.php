<?php

/**
 * Procest ProcessMiningDataLoader.
 *
 * The single OpenRegister read path behind the process-mining report. Split
 * out of ProcessMiningService so that service keeps only the orchestration:
 * every register this report touches — cases, caseTypes, statusTypes and the
 * `statusRecord` chain {@see \OCA\Procest\Service\StatusTransitionService}
 * writes — is fetched here and nowhere else, together with the two lookup
 * indexes the metric calculators need to turn a UUID back into a label.
 *
 * Each loader is deliberately tolerant: a missing OpenRegister or an
 * unconfigured register/schema yields an empty list rather than an error, so
 * a partially configured instance still renders an (empty) dashboard.
 *
 * @category Service
 * @package  OCA\Procest\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service\ProcessMining;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;

/**
 * Loads and indexes every register the process-mining report reads.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class ProcessMiningDataLoader
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared settings/OR resolver.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {
    }//end __construct()

    /**
     * Load every case record via OpenRegister.
     *
     * @param string|null $caseTypeFilter Optional caseType filter (UUID or slug).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function loadCases(?string $caseTypeFilter): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        $filters = ['_limit' => 2000];
        if ($caseTypeFilter !== null && $caseTypeFilter !== '') {
            $filters['caseType'] = $caseTypeFilter;
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: $filters,
        );
    }//end loadCases()

    /**
     * Load all caseType definitions.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function loadCaseTypes(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_type_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 500],
        );
    }//end loadCaseTypes()

    /**
     * Load all statusType definitions.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function loadStatusTypes(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('status_type_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 500],
        );
    }//end loadStatusTypes()

    /**
     * Load statusRecord rows — the same register {@see \OCA\Procest\Service\StatusTransitionService}
     * writes on every transition. No `case` filter: process mining reads
     * across the whole case population, then groups in-memory (mirrors
     * that service's single-case read, scaled up).
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function loadStatusRecords(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('status_record_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: ['_limit' => 10000],
        );
    }//end loadStatusRecords()

    /**
     * Index a list of rows by their `id` field.
     *
     * @param array<int, array<string, mixed>> $rows Rows to index.
     *
     * @return array<string, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function indexById(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $index[$id] = $row;
            }
        }

        return $index;
    }//end indexById()

    /**
     * Index a list of rows by both `id` and `slug`, mirroring
     * {@see \OCA\Procest\Service\DoorlooptijdService::enrichCases()}'s caseType
     * lookup so a case's `caseType` field resolves whether it stores the UUID
     * or the slug.
     *
     * @param array<int, array<string, mixed>> $rows Rows to index.
     *
     * @return array<string, array<string, mixed>>
     *
     * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
     */
    public function indexByIdAndSlug(array $rows): array
    {
        $index = [];
        foreach ($rows as $row) {
            $id   = (string) ($row['id'] ?? '');
            $slug = (string) ($row['slug'] ?? '');
            if ($id !== '') {
                $index[$id] = $row;
            }

            if ($slug !== '') {
                $index[$slug] = $row;
            }
        }

        return $index;
    }//end indexByIdAndSlug()
}//end class
