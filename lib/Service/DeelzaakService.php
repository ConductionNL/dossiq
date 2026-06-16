<?php

/**
 * Procest Deelzaak (sub-case) Service
 *
 * Backend support for parent-child case relations: efficient sub-case
 * lookup, batch counts (used by the case list to avoid N+1 queries), parent
 * fetch with metadata, deletion safeguards, and constraint validation
 * against `caseType.subCaseTypes`.
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
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Service for parent-child (deelzaak) case relations.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T01
 */
class DeelzaakService
{

    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Shared OR/settings resolver.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch every sub-case linked to the given parent.
     *
     * @param string $parentCaseUuid Parent case UUID.
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T01
     */
    public function listSubCases(string $parentCaseUuid): array
    {
        if ($parentCaseUuid === '') {
            return [];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($schema) === true) {
            return [];
        }

        return $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: [
                'parentCase' => $parentCaseUuid,
                '_limit'     => 200,
            ],
        );
    }//end listSubCases()

    /**
     * Single-query sub-case counts keyed by parent UUID.
     *
     * The frontend case list calls this once per page so badge rendering
     * never fires N independent network requests.
     *
     * @param array<int, string> $parentUuids Parent case UUIDs to count.
     *
     * @return array<string, int>
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T03
     */
    public function getSubCaseCounts(array $parentUuids): array
    {
        $counts = [];
        foreach ($parentUuids as $uuid) {
            if (is_string($uuid) === true && $uuid !== '') {
                $counts[$uuid] = 0;
            }
        }

        if ($counts === []) {
            return [];
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $counts;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($schema) === true) {
            return $counts;
        }

        // OR pre-filter on `parentCase != null`; we still need to bucket by parent
        // in PHP because OR doesn't expose a native group-by here, but it's one
        // round trip rather than N.
        $rows = $this->searchObjectsAsArrays(
            objectService: $objectService,
            register: $register,
            schema: $schema,
            filters: [
                '_limit'     => 5000,
                // Limit to children of the requested parents to keep the page small.
                'parentCase' => array_keys($counts),
            ],
        );

        foreach ($rows as $row) {
            $parent = (string) ($row['parentCase'] ?? '');
            if ($parent !== '' && isset($counts[$parent]) === true) {
                $counts[$parent]++;
            }
        }

        return $counts;
    }//end getSubCaseCounts()

    /**
     * Fetch the PARENT of a sub-case, by dereferencing the child's
     * `parentCase` relation.
     *
     * The argument is the CHILD (sub-case) UUID — this method loads that
     * child, reads its `parentCase` field, and returns the case it points
     * at. Returns null when the child has no parent (it is not a sub-case),
     * when the referenced parent no longer exists, or when the reference is
     * self-pointing (a data-integrity guard so we never echo the child back
     * as its own parent).
     *
     * @param string $childCaseUuid Sub-case (child) UUID.
     *
     * @return array<string, mixed>|null The parent case, or null.
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T02
     */
    public function getParentCase(string $childCaseUuid): ?array
    {
        if ($childCaseUuid === '') {
            return null;
        }

        $child = $this->fetchCaseById(caseUuid: $childCaseUuid);
        if ($child === null) {
            return null;
        }

        $parentRef = $this->extractParentReference(case: $child);
        if ($parentRef === '' || $parentRef === $childCaseUuid) {
            // No parent (not a sub-case) or a self-reference — nothing to
            // dereference. Never return the child as its own parent.
            return null;
        }

        return $this->fetchCaseById(caseUuid: $parentRef);
    }//end getParentCase()

    /**
     * Read the `parentCase` reference UUID out of a case array.
     *
     * Tolerates both the scalar-UUID shape (`parentCase: "<uuid>"`) and an
     * expanded-object shape (`parentCase: { id|uuid: "<uuid>" }`) that OR
     * may emit when the relation is hydrated.
     *
     * @param array<string, mixed> $case Case object as an array.
     *
     * @return string The parent UUID, or '' when absent.
     */
    private function extractParentReference(array $case): string
    {
        $parent = ($case['parentCase'] ?? null);
        if (is_string($parent) === true) {
            return $parent;
        }

        if (is_array($parent) === true) {
            $ref = ($parent['id'] ?? $parent['uuid'] ?? '');
            if (is_string($ref) === true) {
                return $ref;
            }

            return '';
        }

        return '';
    }//end extractParentReference()

    /**
     * Fetch a single case object by UUID and normalise it to an array.
     *
     * @param string $caseUuid Case UUID.
     *
     * @return array<string, mixed>|null The case, or null when missing.
     */
    private function fetchCaseById(string $caseUuid): ?array
    {
        if ($caseUuid === '') {
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $obj = $objectService->find($caseUuid, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Case lookup failed',
                ['uuid' => $caseUuid, 'error' => $e->getMessage()]
            );
            return null;
        }

        if ($obj === null) {
            return null;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $obj = $obj->jsonSerialize();
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return null;
    }//end fetchCaseById()

    /**
     * Validate that creating a sub-case is allowed.
     *
     * Rules (matched against the spec acceptance criteria):
     *   1. Parent must exist.
     *   2. Parent must not itself be a sub-case (no grandparenting).
     *   3. Parent must not be closed (`endDate` null).
     *   4. The chosen child caseType must appear in the parent caseType's
     *      `subCaseTypes` allow-list.
     *
     * @param string $parentCaseUuid  Parent UUID.
     * @param string $childCaseTypeId Child caseType id/slug.
     *
     * @return array{ok: bool, reason?: string}
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T08
     */
    public function validateCreate(string $parentCaseUuid, string $childCaseTypeId): array
    {
        // `validateCreate` receives the PARENT's own UUID (the proposed
        // parent of a new sub-case), so fetch that case directly rather than
        // dereferencing a `parentCase` relation.
        $parent = $this->fetchCaseById(caseUuid: $parentCaseUuid);
        if ($parent === null) {
            return ['ok' => false, 'reason' => 'parent_not_found'];
        }

        if (empty($parent['parentCase']) === false) {
            return ['ok' => false, 'reason' => 'grandparenting_forbidden'];
        }

        if (empty($parent['endDate']) === false) {
            return ['ok' => false, 'reason' => 'parent_closed'];
        }

        $parentCaseTypeId = (string) ($parent['caseType'] ?? '');
        if ($parentCaseTypeId === '') {
            return ['ok' => false, 'reason' => 'parent_missing_case_type'];
        }

        $parentCaseType = $this->loadCaseType(caseTypeId: $parentCaseTypeId);
        if ($parentCaseType === null) {
            return ['ok' => false, 'reason' => 'parent_case_type_not_found'];
        }

        $allowed = (array) ($parentCaseType['subCaseTypes'] ?? []);
        if ($allowed === [] || in_array($childCaseTypeId, $allowed, true) === false) {
            return ['ok' => false, 'reason' => 'case_type_not_allowed'];
        }

        return ['ok' => true];
    }//end validateCreate()

    /**
     * Unlink every sub-case of the given parent — used by the delete-with-children
     * confirmation flow to leave orphans accessible at the registry level.
     *
     * @param string $parentCaseUuid Parent UUID.
     *
     * @return int Number of records unlinked.
     *
     * @spec openspec/changes/deelzaak-support/tasks.md#T11
     */
    public function unlinkSubCases(string $parentCaseUuid): int
    {
        $subCases = $this->listSubCases(parentCaseUuid: $parentCaseUuid);
        if ($subCases === []) {
            return 0;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return 0;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_schema');
        $unlinked = 0;
        foreach ($subCases as $subCase) {
            $id = (string) ($subCase['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $payload = $subCase;
                $payload['parentCase'] = null;
                $objectService->saveObject(
                    object: $payload,
                    register: $register,
                    schema: $schema,
                );
                $unlinked++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to unlink sub-case',
                    ['parent' => $parentCaseUuid, 'sub' => $id, 'error' => $e->getMessage()]
                );
            }
        }//end foreach

        return $unlinked;
    }//end unlinkSubCases()

    /**
     * Load a caseType by id or slug.
     *
     * @param string $caseTypeId Identifier.
     *
     * @return array<string, mixed>|null
     */
    private function loadCaseType(string $caseTypeId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_type_schema');
        if (empty($register) === true || empty($schema) === true) {
            return null;
        }

        try {
            $obj = $objectService->find($caseTypeId, register: $register, schema: $schema);
        } catch (\Throwable) {
            return null;
        }

        if ($obj === null) {
            return null;
        }

        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            $obj = $obj->jsonSerialize();
        }

        if (is_array($obj) === true) {
            return $obj;
        }

        return null;
    }//end loadCaseType()
}//end class
