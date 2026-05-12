<?php

/**
 * Procest Workflow Definition Service
 *
 * Lifecycle and consumer service for workflowTemplate objects (aka
 * "workflow definitions"). Pure CRUD over workflowTemplate is delegated to
 * the manifest renderer / OpenRegister auto-routing; this service owns the
 * domain logic that CRUD cannot express:
 *
 *   - createDraft()          — create a new draft definition from a
 *                              fully-resolved payload (used by seed-data
 *                              and catalog-import flows; mutations to
 *                              workflowTemplate MUST go through this
 *                              service to respect the immutability
 *                              invariant of published rows).
 *   - publish()              — flip a draft to published, deprecate the
 *                              previously active version, pin the
 *                              caseType.workflowDefinition reference.
 *   - deprecate()            — flip a published version to deprecated and
 *                              clear isActive (refuses if the caseType has
 *                              no other published version while open cases
 *                              remain).
 *   - cloneDefinition()      — produce a new draft from an existing
 *                              published or deprecated version with
 *                              version + 1.
 *   - getActiveDefinitionFor — read-only consumer entrypoint used by
 *                              status-transition-engine and
 *                              role-based-step-routing.
 *   - getDefinition          — read-only by UUID.
 *   - getDefinitionForCase   — resolves through case.workflowTemplate +
 *                              case.workflowVersion.
 *   - listVersions           — admin UI listing.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Lifecycle + consumer service for workflowTemplate objects.
 */
class WorkflowDefinitionService
{

    /**
     * Lifecycle states. Mirrors the enum on the workflowTemplate schema.
     */
    public const STATUS_DRAFT      = 'draft';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_DEPRECATED = 'deprecated';

    /**
     * Static error strings — never leak OpenRegister exception details to
     * the HTTP layer.
     */
    private const ERR_OR_UNAVAILABLE     = 'Workflow definition store is not available';
    private const ERR_SCHEMA_NOT_CONFIG  = 'Workflow definition schema is not configured';
    private const ERR_NOT_FOUND          = 'Workflow definition not found';
    private const ERR_PUBLISH_FAILED     = 'Could not publish workflow definition';
    private const ERR_DEPRECATE_FAILED   = 'Could not deprecate workflow definition';
    private const ERR_CLONE_FAILED       = 'Could not clone workflow definition';
    private const ERR_NOT_DRAFT          = 'Only draft definitions can be edited';
    private const ERR_NOT_PUBLISHABLE    = 'Only draft definitions can be published';
    private const ERR_NOT_DEPRECATABLE   = 'Only published definitions can be deprecated';
    private const ERR_INVALID_REFERENCES = 'Definition references statuses not belonging to its case type';
    private const ERR_LAST_PUBLISHED     = 'Cannot deprecate the last published definition while open cases remain';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param IUserSession    $userSession     The user session
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the latest published+active definition for a caseType, or
     * null when none exists. Read-only consumer entrypoint used by
     * status-transition-engine and role-based-step-routing.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return array<string, mixed>|null The definition or null
     */
    public function getActiveDefinitionFor(string $caseTypeId): ?array
    {
        if ($caseTypeId === '') {
            return null;
        }

        $versions = $this->listVersionsInternal(caseTypeId: $caseTypeId);
        if ($versions === []) {
            return null;
        }

        foreach ($versions as $candidate) {
            if ($this->statusOf(row: $candidate) !== self::STATUS_PUBLISHED) {
                continue;
            }

            if ((bool) ($candidate['isActive'] ?? false) === true) {
                return $candidate;
            }
        }

        return null;
    }//end getActiveDefinitionFor()

    /**
     * Read a single definition by UUID. Returns null when not found.
     *
     * @param string $id The definition UUID
     *
     * @return array<string, mixed>|null The definition or null
     */
    public function getDefinition(string $id): ?array
    {
        return $this->loadDefinition(id: $id);
    }//end getDefinition()

    /**
     * Resolve the definition pinned to a case via case.workflowTemplate +
     * case.workflowVersion. Falls back to the active definition for the
     * case's caseType when no pin is set.
     *
     * @param string $caseId The case UUID
     *
     * @return array<string, mixed>|null The definition or null
     */
    public function getDefinitionForCase(string $caseId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if ($register === '' || $caseSchema === '') {
            return null;
        }

        try {
            $case = $objectService->findObject($register, $caseSchema, $caseId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load case for definition lookup',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        $case = $this->normalize(row: $case);
        if ($case === null) {
            return null;
        }

        $templateId = (string) ($case['workflowTemplate'] ?? '');
        if ($templateId !== '') {
            return $this->loadDefinition(id: $templateId);
        }

        $caseTypeId = (string) ($case['caseType'] ?? '');
        if ($caseTypeId === '') {
            return null;
        }

        return $this->getActiveDefinitionFor(caseTypeId: $caseTypeId);
    }//end getDefinitionForCase()

    /**
     * List every version of the definition for a given caseType, ordered
     * by version descending. Used by the admin UI.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return array<int, array<string, mixed>> The versions
     */
    public function listVersions(string $caseTypeId): array
    {
        return $this->listVersionsInternal(caseTypeId: $caseTypeId);
    }//end listVersions()

    /**
     * Publish a draft definition. Atomically:
     *   - sets target lifecycleStatus=published, isActive=true, isDraft=false
     *   - moves any previously active version of the same caseType to
     *     lifecycleStatus=deprecated, isActive=false
     *   - updates caseType.workflowDefinition to point at the new active id
     *
     * Returns the updated definition or null on error (errors logged).
     *
     * @param string $id The definition UUID to publish
     *
     * @return array<string, mixed>|null Updated definition or null
     */
    public function publish(string $id): ?array
    {
        $current = $this->loadDefinition(id: $id);
        if ($current === null) {
            $this->logger->warning(
                'Procest: publish() — definition not found',
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return null;
        }

        if ($this->statusOf(row: $current) !== self::STATUS_DRAFT) {
            $this->logger->warning(
                'Procest: publish() — definition is not a draft',
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return null;
        }

        $caseTypeId = (string) ($current['caseType'] ?? '');
        if ($caseTypeId === '' || $this->transitionsReferenceForeignStatuses(definition: $current) === true) {
            $this->logger->warning(
                'Procest: publish() — referential integrity failure',
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register    = $this->settingsService->getConfigValue('register');
        $schema      = $this->settingsService->getConfigValue('workflow_template_schema');
        $caseTypeSch = $this->settingsService->getConfigValue('case_type_schema');

        if ($register === '' || $schema === '' || $caseTypeSch === '') {
            return null;
        }

        // Deprecate previously active versions of the same caseType.
        $previousActive = $this->getActiveDefinitionFor(caseTypeId: $caseTypeId);
        if ($previousActive !== null && (string) ($previousActive['id'] ?? '') !== $id) {
            try {
                $objectService->saveObject(
                    $register,
                    $schema,
                    [
                        'lifecycleStatus' => self::STATUS_DEPRECATED,
                        'isActive'        => false,
                    ],
                    (string) $previousActive['id'],
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Procest: failed to deprecate previous active definition',
                    ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
                );
                return null;
            }
        }

        // Flip target to published+active.
        try {
            $updated = $objectService->saveObject(
                $register,
                $schema,
                [
                    'lifecycleStatus' => self::STATUS_PUBLISHED,
                    'isActive'        => true,
                    'isDraft'         => false,
                ],
                $id,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to publish workflow definition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        // Pin caseType.workflowDefinition to the new active version.
        try {
            $objectService->saveObject(
                $register,
                $caseTypeSch,
                ['workflowDefinition' => $id],
                $caseTypeId,
            );
        } catch (\Throwable $e) {
            // Pinning failure is non-fatal — log and continue. The
            // consumer entrypoint falls back to getActiveDefinitionFor()
            // which finds the new published+active row.
            $this->logger->error(
                'Procest: failed to pin caseType.workflowDefinition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
        }

        return $this->normalize(row: $updated);
    }//end publish()

    /**
     * Deprecate a published definition. Refuses (returns null + logs) if
     * doing so would leave the caseType with no published definition while
     * open cases remain pinned to it.
     *
     * @param string $id The definition UUID to deprecate
     *
     * @return array<string, mixed>|null Updated definition or null
     */
    public function deprecate(string $id): ?array
    {
        $current = $this->loadDefinition(id: $id);
        if ($current === null) {
            return null;
        }

        if ($this->statusOf(row: $current) !== self::STATUS_PUBLISHED) {
            $this->logger->warning(
                'Procest: deprecate() — definition is not published',
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return null;
        }

        $caseTypeId = (string) ($current['caseType'] ?? '');
        if ($caseTypeId !== '' && $this->isLastPublishedForCaseType(id: $id, caseTypeId: $caseTypeId) === true
            && $this->hasOpenCasesFor(caseTypeId: $caseTypeId) === true
        ) {
            $this->logger->warning(
                'Procest: deprecate() — last published definition with open cases',
                ['app' => Application::APP_ID, 'id' => $id, 'caseType' => $caseTypeId]
            );
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $updated = $objectService->saveObject(
                $register,
                $schema,
                [
                    'lifecycleStatus' => self::STATUS_DEPRECATED,
                    'isActive'        => false,
                ],
                $id,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to deprecate workflow definition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $updated);
    }//end deprecate()

    /**
     * Clone an existing published or deprecated definition into a new
     * draft with version + 1.
     *
     * @param string $id The source definition UUID
     *
     * @return array<string, mixed>|null New draft definition or null
     */
    public function cloneDefinition(string $id): ?array
    {
        $source = $this->loadDefinition(id: $id);
        if ($source === null) {
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return null;
        }

        $caseTypeId  = (string) ($source['caseType'] ?? '');
        $nextVersion = $this->nextVersionFor(caseTypeId: $caseTypeId);

        $draft = [
            'title'           => $this->cloneTitle(base: (string) ($source['title'] ?? 'Workflow')),
            'description'     => (string) ($source['description'] ?? ''),
            'caseType'        => $caseTypeId,
            'version'         => $nextVersion,
            'isActive'        => false,
            'isDraft'         => true,
            'lifecycleStatus' => self::STATUS_DRAFT,
            'steps'           => (string) ($source['steps'] ?? ''),
            'transitions'     => (string) ($source['transitions'] ?? ''),
            'nodePositions'   => (string) ($source['nodePositions'] ?? ''),
        ];

        try {
            $new = $objectService->saveObject($register, $schema, $draft);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to clone workflow definition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $new);
    }//end cloneDefinition()

    /**
     * Create a brand-new draft definition from a fully-resolved payload.
     * Used by seed-data / catalog import flows where the caller has already
     * resolved caseType slug → UUID and statusType names → UUIDs.
     *
     * The payload SHALL provide:
     *   - title (string, required)
     *   - description (string, optional)
     *   - caseType (UUID string, required)
     *   - version (int, optional — defaults to next version for caseType)
     *   - steps (array of step rows, will be JSON-encoded if not already a string)
     *   - transitions (array of transition rows, will be JSON-encoded if not already a string)
     *
     * The method enforces lifecycleStatus=draft, isDraft=true, isActive=false.
     * Returns the created definition (with id) or null on failure.
     *
     * @param array<string, mixed> $payload The fully-resolved draft payload
     *
     * @return array<string, mixed>|null The created draft or null on failure
     */
    public function createDraft(array $payload): ?array
    {
        $caseTypeId = (string) ($payload['caseType'] ?? '');
        if ($caseTypeId === '' || (string) ($payload['title'] ?? '') === '') {
            $this->logger->warning(
                'Procest: createDraft() — missing caseType or title',
                ['app' => Application::APP_ID]
            );
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return null;
        }

        $version = (int) ($payload['version'] ?? 0);
        if ($version <= 0) {
            $version = $this->nextVersionFor(caseTypeId: $caseTypeId);
        }

        $steps       = $payload['steps'] ?? [];
        $transitions = $payload['transitions'] ?? [];

        if (is_string($steps) === true) {
            $stepsValue = $steps;
        } else {
            $stepsValue = json_encode($steps);
        }

        if (is_string($transitions) === true) {
            $transitionsValue = $transitions;
        } else {
            $transitionsValue = json_encode($transitions);
        }

        $draft = [
            'title'           => (string) $payload['title'],
            'description'     => (string) ($payload['description'] ?? ''),
            'caseType'        => $caseTypeId,
            'version'         => $version,
            'isActive'        => false,
            'isDraft'         => true,
            'lifecycleStatus' => self::STATUS_DRAFT,
            'steps'           => $stepsValue,
            'transitions'     => $transitionsValue,
            'nodePositions'   => (string) ($payload['nodePositions'] ?? ''),
        ];

        try {
            $new = $objectService->saveObject($register, $schema, $draft);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to create workflow draft',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $new);
    }//end createDraft()

    // -----------------------------------------------------------------
    // Internal helpers.
    // -----------------------------------------------------------------

    /**
     * Internal — fetch all versions of the definition for a caseType,
     * sorted by version descending.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return array<int, array<string, mixed>>
     */
    private function listVersionsInternal(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return [];
        }

        try {
            $results = $objectService->findObjects(
                $register,
                $schema,
                ['caseType' => $caseTypeId],
                [],
                500,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list workflow definitions for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($results) === false) {
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
    }//end listVersionsInternal()

    /**
     * Load a single definition by UUID.
     *
     * @param string $id The definition UUID
     *
     * @return array<string, mixed>|null
     */
    private function loadDefinition(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('workflow_template_schema');

        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $obj = $objectService->findObject($register, $schema, $id);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to load workflow definition',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return null;
        }

        return $this->normalize(row: $obj);
    }//end loadDefinition()

    /**
     * Resolve the next monotonically increasing version number for a
     * given caseType. Falls back to 1 when no prior versions exist.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return int Next version number
     */
    private function nextVersionFor(string $caseTypeId): int
    {
        $versions = $this->listVersionsInternal(caseTypeId: $caseTypeId);
        $max      = 0;
        foreach ($versions as $row) {
            $candidate = (int) ($row['version'] ?? 0);
            if ($candidate > $max) {
                $max = $candidate;
            }
        }

        return ($max + 1);
    }//end nextVersionFor()

    /**
     * Whether this id is the last published row for its caseType.
     *
     * @param string $id         The current definition UUID
     * @param string $caseTypeId The caseType UUID
     *
     * @return bool
     */
    private function isLastPublishedForCaseType(string $id, string $caseTypeId): bool
    {
        $count = 0;
        foreach ($this->listVersionsInternal(caseTypeId: $caseTypeId) as $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                continue;
            }

            if ($this->statusOf(row: $row) === self::STATUS_PUBLISHED) {
                $count++;
            }
        }

        return ($count === 0);
    }//end isLastPublishedForCaseType()

    /**
     * Whether the caseType has any open cases (status not in a final
     * state). Conservative — returns true when we cannot establish the
     * count to avoid silent data loss.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return bool
     */
    private function hasOpenCasesFor(string $caseTypeId): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return true;
        }

        $register   = $this->settingsService->getConfigValue('register');
        $caseSchema = $this->settingsService->getConfigValue('case_schema');

        if ($register === '' || $caseSchema === '') {
            return true;
        }

        try {
            $results = $objectService->findObjects(
                $register,
                $caseSchema,
                ['caseType' => $caseTypeId],
                [],
                1,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to count open cases for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return true;
        }

        return (is_array($results) === true && count($results) > 0);
    }//end hasOpenCasesFor()

    /**
     * Validate that every status referenced in transitions belongs to the
     * linked caseType. Returns true when the references are *invalid*.
     *
     * @param array<string, mixed> $definition The definition to validate
     *
     * @return bool
     */
    private function transitionsReferenceForeignStatuses(array $definition): bool
    {
        $caseTypeId  = (string) ($definition['caseType'] ?? '');
        $transitions = $this->decodeArray(raw: ($definition['transitions'] ?? ''));

        if ($caseTypeId === '' || $transitions === []) {
            return false;
        }

        $statusIds = $this->collectStatusTypeIdsFor(caseTypeId: $caseTypeId);
        if ($statusIds === []) {
            // No statusTypes yet — cannot validate. Treat as ok.
            return false;
        }

        foreach ($transitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            foreach (['fromStatus', 'toStatus'] as $key) {
                $ref = (string) ($transition[$key] ?? '');
                if ($ref !== '' && in_array($ref, $statusIds, true) === false) {
                    return true;
                }
            }
        }

        return false;
    }//end transitionsReferenceForeignStatuses()

    /**
     * Fetch every statusType id belonging to a given caseType.
     *
     * @param string $caseTypeId The caseType UUID
     *
     * @return array<int, string>
     */
    private function collectStatusTypeIdsFor(string $caseTypeId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register     = $this->settingsService->getConfigValue('register');
        $statusSchema = $this->settingsService->getConfigValue('status_type_schema');

        if ($register === '' || $statusSchema === '') {
            return [];
        }

        try {
            $rows = $objectService->findObjects(
                $register,
                $statusSchema,
                ['caseType' => $caseTypeId],
                [],
                500,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: failed to list statusTypes for caseType',
                ['app' => Application::APP_ID, 'exception' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($rows) === false) {
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
    }//end collectStatusTypeIdsFor()

    /**
     * Decode a JSON-encoded array property; returns an empty array on any
     * decoding error or non-array payload.
     *
     * @param mixed $raw The raw property value
     *
     * @return array<int, mixed>
     */
    private function decodeArray(mixed $raw): array
    {
        if (is_array($raw) === true) {
            return $raw;
        }

        if (is_string($raw) === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decodeArray()

    /**
     * Coerce an OpenRegister result row to an associative array.
     *
     * @param mixed $row Result row from ObjectService
     *
     * @return array<string, mixed>|null
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

    /**
     * Resolve the authoritative lifecycle status of a row. Prefers the
     * new lifecycleStatus field; falls back to the legacy isDraft +
     * isActive booleans for objects created before the schema bump.
     *
     * @param array<string, mixed> $row Definition row
     *
     * @return string One of draft|published|deprecated
     */
    private function statusOf(array $row): string
    {
        $status = (string) ($row['lifecycleStatus'] ?? '');
        if ($status === self::STATUS_DRAFT
            || $status === self::STATUS_PUBLISHED
            || $status === self::STATUS_DEPRECATED
        ) {
            return $status;
        }

        // Legacy fallback.
        $isDraft  = (bool) ($row['isDraft'] ?? true);
        $isActive = (bool) ($row['isActive'] ?? false);

        if ($isDraft === true) {
            return self::STATUS_DRAFT;
        }

        if ($isActive === true) {
            return self::STATUS_PUBLISHED;
        }

        return self::STATUS_DEPRECATED;
    }//end statusOf()

    /**
     * Build a title for a cloned draft.
     *
     * @param string $base The source title
     *
     * @return string
     */
    private function cloneTitle(string $base): string
    {
        return rtrim($base).' (kopie)';
    }//end cloneTitle()
}//end class
