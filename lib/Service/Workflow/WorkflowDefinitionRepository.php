<?php

/**
 * Procest Workflow Definition Repository.
 *
 * Every OpenRegister read and write the workflow-definition lifecycle
 * performs. Split out of WorkflowDefinitionService so that service keeps only
 * the lifecycle decisions — publish, deprecate, clone, pin — while the
 * mechanics of reaching the object store live here: resolving the
 * ObjectService bridge, reading register/schema ids out of configuration,
 * coercing ObjectEntity results to plain arrays, and swallowing a store
 * failure into the "null / empty / conservative-true" answers the lifecycle
 * expects.
 *
 * The repository spans the four schemas one definition lifecycle touches —
 * `workflowTemplate`, `case`, `caseType` and `statusType` — because they
 * share exactly one concern: they are the persistence surface of a single
 * workflow definition and the referential integrity around it.
 *
 * @category Service
 * @package  OCA\Procest\Service\Workflow
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
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Workflow;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister persistence for workflowTemplate objects and their references.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class WorkflowDefinitionRepository
{

    use SearchesObjects;

    /**
     * Configuration key holding the workflowTemplate schema id.
     *
     * @var string
     */
    public const SCHEMA_DEFINITION = 'workflow_template_schema';

    /**
     * Configuration key holding the case schema id.
     *
     * @var string
     */
    public const SCHEMA_CASE = 'case_schema';

    /**
     * Configuration key holding the caseType schema id.
     *
     * @var string
     */
    public const SCHEMA_CASE_TYPE = 'case_type_schema';

    /**
     * Configuration key holding the statusType schema id.
     *
     * @var string
     */
    public const SCHEMA_STATUS_TYPE = 'status_type_schema';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings/config + ObjectService bridge.
     * @param LoggerInterface $logger          The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the ObjectService bridge plus the register and schema ids for
     * one schema configuration key.
     *
     * A null return means OpenRegister is absent or the register/schema pair
     * is not configured — the two cases every caller collapses into its own
     * "cannot reach the store" answer.
     *
     * @param string $schemaKey One of the SCHEMA_* configuration keys.
     *
     * @return array{objectService: object, register: string, schema: string}|null
     *         The resolved context, or null when the store is unreachable.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    private function context(string $schemaKey): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue($schemaKey);
        if ($register === '' || $schema === '') {
            return null;
        }

        return [
            'objectService' => $objectService,
            'register'      => $register,
            'schema'        => $schema,
        ];
    }//end context()

    /**
     * Whether the store is reachable and the given schema is configured.
     *
     * @param string $schemaKey One of the SCHEMA_* configuration keys.
     *
     * @return bool True when reads/writes against that schema can be attempted.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function isConfiguredFor(string $schemaKey): bool
    {
        return ($this->context(schemaKey: $schemaKey) !== null);
    }//end isConfiguredFor()

    /**
     * Load a single definition by UUID.
     *
     * @param string $id The definition UUID.
     *
     * @return array<string, mixed>|null The definition, or null when unavailable.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function findById(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $context = $this->context(schemaKey: self::SCHEMA_DEFINITION);
        if ($context === null) {
            return null;
        }

        try {
            $obj = $context['objectService']->find(
                $id,
                register: $context['register'],
                schema: $context['schema']
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load workflow definition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $obj);
    }//end findById()

    /**
     * Fetch all versions of the definition for a caseType, sorted by version
     * descending.
     *
     * @param string $caseTypeId The caseType UUID.
     *
     * @return array<int, array<string, mixed>> The versions, newest first.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function listVersionsForCaseType(string $caseTypeId): array
    {
        $context = $this->context(schemaKey: self::SCHEMA_DEFINITION);
        if ($context === null) {
            return [];
        }

        try {
            $results = $this->searchObjectsAsArrays(
                objectService: $context['objectService'],
                register: $context['register'],
                schema: $context['schema'],
                filters: ['caseType' => $caseTypeId, '_limit' => 500],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list workflow definitions for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $rows = [];
        foreach ($results as $row) {
            $normalized = $this->normalize(row: $row);
            if ($normalized !== null) {
                $rows[] = $normalized;
            }
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                return (int) ($b['version'] ?? 0) <=> (int) ($a['version'] ?? 0);
            },
        );

        return $rows;
    }//end listVersionsForCaseType()

    /**
     * Resolve the next monotonically increasing version number for a given
     * caseType. Falls back to 1 when no prior versions exist.
     *
     * @param string $caseTypeId The caseType UUID.
     *
     * @return int Next version number.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function nextVersionFor(string $caseTypeId): int
    {
        $max = 0;
        foreach ($this->listVersionsForCaseType(caseTypeId: $caseTypeId) as $row) {
            $candidate = (int) ($row['version'] ?? 0);
            if ($candidate > $max) {
                $max = $candidate;
            }
        }

        return ($max + 1);
    }//end nextVersionFor()

    /**
     * Create or update a workflowTemplate row.
     *
     * Passing a uuid updates that row; omitting it creates a new one.
     *
     * @param array<string, mixed> $payload The properties to write.
     * @param string|null          $uuid    The row to update, or null to create.
     *
     * @return array<string, mixed>|null The written row, or null on failure.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function save(array $payload, ?string $uuid=null): ?array
    {
        $context = $this->context(schemaKey: self::SCHEMA_DEFINITION);
        if ($context === null) {
            return null;
        }

        try {
            if ($uuid === null) {
                return $this->normalize(
                    row: $context['objectService']->saveObject(
                        object: $payload,
                        register: $context['register'],
                        schema: $context['schema'],
                    )
                );
            }

            $written = $context['objectService']->saveObject(
                object: $payload,
                register: $context['register'],
                schema: $context['schema'],
                uuid: $uuid,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to save workflow definition',
                ['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
            );
            return null;
        }//end try

        return $this->normalize(row: $written);
    }//end save()

    /**
     * Pin `caseType.workflowDefinition` to a definition id.
     *
     * Pinning failure is non-fatal — the consumer entrypoint falls back to
     * the published+active row — so the failure is logged and swallowed.
     *
     * @param string $caseTypeId   The caseType UUID.
     * @param string $definitionId The definition UUID to pin.
     *
     * @return void
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function pinWorkflowDefinition(string $caseTypeId, string $definitionId): void
    {
        $context = $this->context(schemaKey: self::SCHEMA_CASE_TYPE);
        if ($context === null) {
            return;
        }

        try {
            $context['objectService']->saveObject(
                object: ['workflowDefinition' => $definitionId],
                register: $context['register'],
                schema: $context['schema'],
                uuid: $caseTypeId,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to pin caseType.workflowDefinition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
        }
    }//end pinWorkflowDefinition()

    /**
     * Load a case row, used to resolve the definition pinned to a case.
     *
     * @param string $caseId The case UUID.
     *
     * @return array<string, mixed>|null The case, or null when unavailable.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function findCase(string $caseId): ?array
    {
        $context = $this->context(schemaKey: self::SCHEMA_CASE);
        if ($context === null) {
            return null;
        }

        try {
            $case = $context['objectService']->find(
                $caseId,
                register: $context['register'],
                schema: $context['schema']
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load case for definition lookup',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $case);
    }//end findCase()

    /**
     * Fetch every statusType id belonging to a given caseType.
     *
     * @param string $caseTypeId The caseType UUID.
     *
     * @return array<int, string> The statusType UUIDs.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function listStatusTypeIds(string $caseTypeId): array
    {
        $context = $this->context(schemaKey: self::SCHEMA_STATUS_TYPE);
        if ($context === null) {
            return [];
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $context['objectService'],
                register: $context['register'],
                schema: $context['schema'],
                filters: ['caseType' => $caseTypeId, '_limit' => 500],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list statusTypes for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $normalized = $this->normalize(row: $row);
            if ($normalized === null) {
                continue;
            }

            $id = (string) ($normalized['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }//end listStatusTypeIds()

    /**
     * Whether the caseType has any cases pinned to it.
     *
     * Conservative — returns true when the count cannot be established, so a
     * deprecation that would strand open cases is refused rather than risked.
     *
     * @param string $caseTypeId The caseType UUID.
     *
     * @return bool True when cases exist, or when the answer is unknown.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    public function hasCasesFor(string $caseTypeId): bool
    {
        $context = $this->context(schemaKey: self::SCHEMA_CASE);
        if ($context === null) {
            return true;
        }

        try {
            $results = $this->searchObjectsAsArrays(
                objectService: $context['objectService'],
                register: $context['register'],
                schema: $context['schema'],
                filters: ['caseType' => $caseTypeId, '_limit' => 1],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to count open cases for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return true;
        }

        return (is_array($results) === true && count($results) > 0);
    }//end hasCasesFor()

    /**
     * Coerce an OpenRegister result row to an associative array.
     *
     * @param mixed $row Result row from ObjectService.
     *
     * @return array<string, mixed>|null The row as an array, or null when uncoercible.
     *
     * @spec openspec/specs/workflow-definition-model/spec.md
     */
    private function normalize(mixed $row): ?array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialized = $row->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return null;
    }//end normalize()
}//end class
