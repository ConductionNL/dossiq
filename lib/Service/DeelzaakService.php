<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Procest Deelzaak Service
 *
 * Service for creating and managing deelzaken (sub-cases) in Procest.
 * Supports hierarchical case structures (hoofdzaak → deelzaken) and
 * vervolg-zaak (follow-up case) creation with typed relatie linking.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for deelzaak (sub-case) creation and hierarchy management.
 *
 * Implements:
 * - Manual and automatic deelzaak creation with field inheritance
 * - Zaaktype deelzaaktype validation (only allowed subCaseTypes)
 * - Closure guard: blocks parent closure when deelzaken are open
 * - Vervolg-zaak creation with predecessor/successor relatie links
 * - Recursive hierarchy retrieval (n levels)
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
class DeelzaakService
{

    /**
     * Relationship type constant for deelzaak (parent-child).
     */
    private const AARD_RELATIE_BIJDRAGE = 'bijdrage';

    /**
     * Relationship type constant for vervolg-zaak (successor).
     */
    private const AARD_RELATIE_VERVOLG = 'vervolg';

    /**
     * Relationship type constant for onderverdeling (predecessor).
     */
    private const AARD_RELATIE_ONDERWERP = 'onderwerp';

    /**
     * Maximum hierarchy depth guard to prevent infinite recursion.
     */
    private const MAX_HIERARCHY_DEPTH = 10;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings and OpenRegister access
     * @param LoggerInterface $logger          PSR logger
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a deelzaak (sub-case) under a parent case.
     *
     * Validates that the requested caseTypeId is in the parent caseType's
     * `subCaseTypes` list. Inherits deadline and archiveNomination from the
     * parent. Sets parentCase to the parent's ID.
     *
     * @param string      $parentCaseId The UUID of the parent (hoofdzaak) case
     * @param string      $caseTypeId   The UUID of the deelzaak's caseType
     * @param string|null $title        Optional title override for the deelzaak
     * @param string|null $assignee     Optional assignee UID
     * @param string|null $requestedBy  UID of the user requesting the creation
     *
     * @return array<string, mixed> The created deelzaak object (serialized)
     *
     * @throws \RuntimeException When OpenRegister unavailable, parent not found,
     *                           or caseTypeId not allowed as deelzaak
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function createDeelzaak(
        string $parentCaseId,
        string $caseTypeId,
        ?string $title=null,
        ?string $assignee=null,
        ?string $requestedBy=null,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister objectService unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        $ctSchema   = $this->settingsService->getConfigValue(key: 'case_type_schema');

        if ($register === '' || $caseSchema === '' || $ctSchema === '') {
            throw new \RuntimeException('Case register/schema settings not configured');
        }

        // Load parent case.
        $parentCase = $this->findObject(
            objectService: $objectService,
            uuid: $parentCaseId,
            register: $register,
            schema: $caseSchema
        );
        if ($parentCase === null) {
            throw new \RuntimeException("Parent case '{$parentCaseId}' not found");
        }

        // Load parent caseType to validate allowed sub-case types.
        $parentCaseTypeId = $parentCase['caseType'] ?? '';
        if ($parentCaseTypeId === '') {
            throw new \RuntimeException('Parent case has no caseType configured');
        }

        $parentCaseTypeUuid = $this->extractUuidFromValue(value: $parentCaseTypeId);
        $parentCaseType     = $this->findObject(
            objectService: $objectService,
            uuid: $parentCaseTypeUuid,
            register: $register,
            schema: $ctSchema
        );
        if ($parentCaseType === null) {
            throw new \RuntimeException("Parent caseType '{$parentCaseTypeUuid}' not found");
        }

        // Validate that caseTypeId is in the allowed subCaseTypes.
        $this->assertAllowedSubCaseType(
            caseTypeId: $caseTypeId,
            parentCaseType: $parentCaseType
        );

        // Load the deelzaak's caseType for title derivation.
        $deelzaakCaseType = $this->findObject(
            objectService: $objectService,
            uuid: $caseTypeId,
            register: $register,
            schema: $ctSchema
        );

        // Build deelzaak data, inheriting applicable fields from parent.
        $deelzaakTitle = $title ?? ($deelzaakCaseType['title'] ?? 'Deelzaak');
        $deelzaakData  = [
            'title'             => $deelzaakTitle,
            'caseType'          => $caseTypeId,
            'parentCase'        => $parentCaseId,
            'deadline'          => $parentCase['deadline'] ?? null,
            'archiveNomination' => $parentCase['archiveNomination'] ?? null,
            'confidentiality'   => $parentCase['confidentiality'] ?? null,
            'assignee'          => $assignee ?? ($deelzaakCaseType['defaultAssignee'] ?? null),
            'startDate'         => date('Y-m-d'),
        ];

        // Strip null values to avoid overriding OpenRegister defaults.
        $deelzaakData = array_filter(
            $deelzaakData,
            static fn ($v) => $v !== null
        );

        $created = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $deelzaakData
        );

        if (is_array($created) === true) {
            $createdArray = $created;
        } else {
            $createdArray = $created->jsonSerialize();
        }

        $this->logger->info(
            'DeelzaakService: created deelzaak {id} under parent {parent} (requestedBy={user})',
            [
                'id'     => $createdArray['id'] ?? 'unknown',
                'parent' => $parentCaseId,
                'user'   => $requestedBy ?? 'system',
            ]
        );

        return $createdArray;
    }//end createDeelzaak()

    /**
     * Retrieve the full case hierarchy rooted at a given case.
     *
     * Returns a nested structure: `['case' => [...], 'children' => [...]]`.
     * Recursion is capped at MAX_HIERARCHY_DEPTH to prevent runaway queries.
     *
     * @param string $caseId The UUID of the root case
     * @param int    $depth  Current recursion depth (internal use)
     *
     * @return array<string, mixed> Nested hierarchy tree
     *
     * @throws \RuntimeException When OpenRegister unavailable or case not found
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function getHierarchy(string $caseId, int $depth=0): array
    {
        if ($depth >= self::MAX_HIERARCHY_DEPTH) {
            return ['case' => ['id' => $caseId], 'children' => []];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister objectService unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

        if ($register === '' || $caseSchema === '') {
            throw new \RuntimeException('Case register/schema settings not configured');
        }

        $rootCase = $this->findObject(
            objectService: $objectService,
            uuid: $caseId,
            register: $register,
            schema: $caseSchema
        );
        if ($rootCase === null) {
            throw new \RuntimeException("Case '{$caseId}' not found");
        }

        $children   = $this->fetchDirectChildren(
            objectService: $objectService,
            parentCaseId: $caseId,
            register: $register,
            schema: $caseSchema
        );
        $childTrees = [];
        foreach ($children as $child) {
            $childId = $child['id'] ?? '';
            if ($childId === '') {
                continue;
            }

            $childTrees[] = $this->getHierarchy(caseId: $childId, depth: ($depth + 1));
        }

        return [
            'case'     => $rootCase,
            'children' => $childTrees,
        ];
    }//end getHierarchy()

    /**
     * Validate whether a case can be closed.
     *
     * Returns `['canClose' => true]` when no open deelzaken block closure.
     * Returns `['canClose' => false, 'openDeelzaken' => [...]]` when blocked.
     * Only blocks when the caseType has `requireAllDeelzakenClosed` set to true.
     *
     * @param string $caseId The UUID of the case being closed
     *
     * @return array{canClose: bool, openDeelzaken: array<int, array<string, mixed>>}
     *
     * @throws \RuntimeException When OpenRegister unavailable
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function validateClosureAllowed(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister objectService unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        $ctSchema   = $this->settingsService->getConfigValue(key: 'case_type_schema');

        if ($register === '' || $caseSchema === '' || $ctSchema === '') {
            return ['canClose' => true, 'openDeelzaken' => []];
        }

        // Load the case to get its caseType.
        $caseData = $this->findObject(
            objectService: $objectService,
            uuid: $caseId,
            register: $register,
            schema: $caseSchema
        );
        if ($caseData === null) {
            return ['canClose' => true, 'openDeelzaken' => []];
        }

        // Check if caseType requires all deelzaken to be closed.
        $caseTypeId   = $caseData['caseType'] ?? '';
        $ctUuid       = $this->extractUuidFromValue(value: (string) $caseTypeId);
        $caseTypeData = $this->findObject(
            objectService: $objectService,
            uuid: $ctUuid,
            register: $register,
            schema: $ctSchema
        );

        $requireAll = $caseTypeData['requireAllDeelzakenClosed'] ?? false;
        if ($requireAll === false || $requireAll === 'false' || $requireAll === 0) {
            return ['canClose' => true, 'openDeelzaken' => []];
        }

        // Find all direct deelzaken that are not yet closed (endDate is null/empty).
        $children  = $this->fetchDirectChildren(
            objectService: $objectService,
            parentCaseId: $caseId,
            register: $register,
            schema: $caseSchema
        );
        $openCases = [];
        foreach ($children as $child) {
            $endDate = $child['endDate'] ?? null;
            if ($endDate === null || $endDate === '') {
                $openCases[] = [
                    'id'     => $child['id'] ?? '',
                    'title'  => $child['title'] ?? '',
                    'status' => $child['status'] ?? '',
                ];
            }
        }

        if (empty($openCases) === true) {
            return ['canClose' => true, 'openDeelzaken' => []];
        }

        return [
            'canClose'      => false,
            'openDeelzaken' => $openCases,
        ];
    }//end validateClosureAllowed()

    /**
     * Create a vervolg-zaak (follow-up case) from an existing case.
     *
     * Creates a new case of the given caseType and links it back to the
     * original case via `relatedCases` with `aardRelatie=vervolg`. The
     * original case's `relatedCases` is updated with the successor's URL.
     *
     * @param string      $sourceCaseId The UUID of the source case (predecessor)
     * @param string      $caseTypeId   The UUID of the follow-up caseType
     * @param string|null $title        Optional title override
     * @param string|null $requestedBy  UID of the user requesting creation
     *
     * @return array<string, mixed> The created vervolg-zaak object (serialized)
     *
     * @throws \RuntimeException When OpenRegister unavailable or source not found
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function createVervolgzaak(
        string $sourceCaseId,
        string $caseTypeId,
        ?string $title=null,
        ?string $requestedBy=null,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister objectService unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        $ctSchema   = $this->settingsService->getConfigValue(key: 'case_type_schema');

        if ($register === '' || $caseSchema === '') {
            throw new \RuntimeException('Case register/schema settings not configured');
        }

        // Load source case.
        $sourceCase = $this->findObject(
            objectService: $objectService,
            uuid: $sourceCaseId,
            register: $register,
            schema: $caseSchema
        );
        if ($sourceCase === null) {
            throw new \RuntimeException("Source case '{$sourceCaseId}' not found");
        }

        // Load caseType for title derivation.
        $vervolgCaseType = null;
        if ($ctSchema !== '') {
            $vervolgCaseType = $this->findObject(
                objectService: $objectService,
                uuid: $caseTypeId,
                register: $register,
                schema: $ctSchema
            );
        }

        $vervolgTitle = $title ?? ($vervolgCaseType['title'] ?? 'Vervolg-zaak');

        // Build the predecessor relatie entry referencing the source case.
        $sourceUri           = $sourceCase['uri'] ?? $sourceCase['@self']['uri'] ?? null;
        $predecessorRelaties = [
            [
                'url'         => $sourceUri ?? $sourceCaseId,
                'aardRelatie' => self::AARD_RELATIE_ONDERWERP,
            ],
        ];
        $predecessorRelatieJson = json_encode($predecessorRelaties);

        $vervolgData = [
            'title'        => $vervolgTitle,
            'caseType'     => $caseTypeId,
            'relatedCases' => $predecessorRelatieJson,
            'startDate'    => date('Y-m-d'),
        ];

        // Inherit assignee from caseType default.
        if ($vervolgCaseType !== null && isset($vervolgCaseType['defaultAssignee']) === true) {
            $vervolgData['assignee'] = $vervolgCaseType['defaultAssignee'];
        }

        $created = $objectService->saveObject(
            register: $register,
            schema: $caseSchema,
            object: $vervolgData
        );

        if (is_array($created) === true) {
            $createdArray = $created;
        } else {
            $createdArray = $created->jsonSerialize();
        }

        // Update the source case's relatedCases to include the successor.
        $this->addSuccessorRelatie(
            objectService: $objectService,
            sourceCaseId: $sourceCaseId,
            sourceCase: $sourceCase,
            successorUri: ($createdArray['uri'] ?? $createdArray['@self']['uri'] ?? $createdArray['id'] ?? ''),
            register: $register,
            schema: $caseSchema
        );

        $this->logger->info(
            'DeelzaakService: created vervolgzaak {id} from source {source} (requestedBy={user})',
            [
                'id'     => $createdArray['id'] ?? 'unknown',
                'source' => $sourceCaseId,
                'user'   => $requestedBy ?? 'system',
            ]
        );

        return $createdArray;
    }//end createVervolgzaak()

    /**
     * Assert that a caseTypeId is in the parent caseType's subCaseTypes list.
     *
     * @param string               $caseTypeId     The deelzaak caseType UUID to check
     * @param array<string, mixed> $parentCaseType The parent caseType data
     *
     * @return void
     *
     * @throws \RuntimeException When caseTypeId is not allowed
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    private function assertAllowedSubCaseType(string $caseTypeId, array $parentCaseType): void
    {
        $subCaseTypes = $parentCaseType['subCaseTypes'] ?? [];
        if (is_string($subCaseTypes) === true) {
            $subCaseTypes = json_decode($subCaseTypes, true) ?? [];
        }

        if (is_array($subCaseTypes) === false || empty($subCaseTypes) === true) {
            // No restrictions configured — all types allowed.
            return;
        }

        // Normalize allowed UUIDs (strip URLs to bare UUID).
        $allowedUuids = array_map(
            fn ($v) => $this->extractUuidFromValue(value: (string) $v),
            $subCaseTypes
        );

        $requestedUuid = $this->extractUuidFromValue(value: $caseTypeId);

        if (in_array($requestedUuid, $allowedUuids, true) === false) {
            throw new \RuntimeException(
                "CaseType '{$caseTypeId}' is not configured as an allowed deelzaaktype"
            );
        }
    }//end assertAllowedSubCaseType()

    /**
     * Fetch direct children of a case (first level only).
     *
     * @param object $objectService OpenRegister objectService
     * @param string $parentCaseId  The parent case UUID
     * @param string $register      Register ID
     * @param string $schema        Case schema ID
     *
     * @return array<int, array<string, mixed>> Array of child case objects
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    private function fetchDirectChildren(
        object $objectService,
        string $parentCaseId,
        string $register,
        string $schema
    ): array {
        try {
            $query  = $objectService->buildSearchQuery(
                requestParams: [
                    '_filters[parentCase]' => $parentCaseId,
                    '_limit'               => 500,
                ],
                register: $register,
                schema: $schema
            );
            $result = $objectService->searchObjectsPaginated(query: $query);

            $children = [];
            foreach (($result['results'] ?? []) as $obj) {
                if (is_array($obj) === true) {
                    $children[] = $obj;
                } else {
                    $children[] = $obj->jsonSerialize();
                }
            }

            return $children;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DeelzaakService: failed to fetch children for {id}: {msg}',
                ['id' => $parentCaseId, 'msg' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end fetchDirectChildren()

    /**
     * Update the source case's relatedCases to add a successor relatie entry.
     *
     * @param object               $objectService OpenRegister objectService
     * @param string               $sourceCaseId  The source case UUID
     * @param array<string, mixed> $sourceCase    The source case data
     * @param string               $successorUri  The successor URI/URL
     * @param string               $register      Register ID
     * @param string               $schema        Case schema ID
     *
     * @return void
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    private function addSuccessorRelatie(
        object $objectService,
        string $sourceCaseId,
        array $sourceCase,
        string $successorUri,
        string $register,
        string $schema
    ): void {
        if ($successorUri === '') {
            return;
        }

        // Decode existing relatedCases.
        $existing = $sourceCase['relatedCases'] ?? null;
        if (is_string($existing) === true && $existing !== '') {
            $relaties = json_decode($existing, true) ?? [];
        } else if (is_array($existing) === true) {
            $relaties = $existing;
        } else {
            $relaties = [];
        }

        $relaties[] = [
            'url'         => $successorUri,
            'aardRelatie' => self::AARD_RELATIE_VERVOLG,
        ];

        try {
            $objectService->saveObject(
                register: $register,
                schema: $schema,
                object: ['id' => $sourceCaseId, 'relatedCases' => json_encode($relaties)]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DeelzaakService: failed to update relatedCases on source {id}: {msg}',
                ['id' => $sourceCaseId, 'msg' => $e->getMessage()]
            );
        }
    }//end addSuccessorRelatie()

    /**
     * Find an OpenRegister object by UUID.
     *
     * @param object $objectService The OpenRegister objectService
     * @param string $uuid          The UUID to look up
     * @param string $register      Register ID
     * @param string $schema        Schema ID
     *
     * @return array<string, mixed>|null The object data, or null when not found
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    private function findObject(
        object $objectService,
        string $uuid,
        string $register,
        string $schema
    ): ?array {
        if ($uuid === '' || $register === '' || $schema === '') {
            return null;
        }

        try {
            $obj = $objectService->find(id: $uuid, register: $register, schema: $schema);
            if (is_array($obj) === true) {
                return $obj;
            }

            return $obj->jsonSerialize();
        } catch (\Throwable $e) {
            return null;
        }
    }//end findObject()

    /**
     * Extract a bare UUID from a value that may be a URL, UUID, or UUID string.
     *
     * @param string $value The value to extract a UUID from
     *
     * @return string The extracted UUID, or the original string when no UUID found
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    private function extractUuidFromValue(string $value): string
    {
        $pattern = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';
        if (preg_match($pattern, $value, $matches) === 1) {
            return $matches[0];
        }

        return $value;
    }//end extractUuidFromValue()
}//end class
