<?php
/**
 * Procest MCP Tool Provider
 *
 * Per-app implementation of OCA\OpenRegister\Mcp\IMcpToolProvider for the
 * Procest case-management app. Exposes a minimal, read-only MVP skeleton of
 * MCP tools so the AI Chat Companion (hydra ADR-034 / ADR-035) can surface
 * Procest capabilities — listing running process instances (cases) and
 * reading one process instance with its current step + history — to an LLM.
 *
 * @category Mcp
 * @package  OCA\Procest\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-mcp-integration/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-mcp-integration/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-mcp-integration/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-mcp-integration/tasks.md#task-4
 * @spec openspec/changes/retrofit-2026-05-24-mcp-integration/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Mcp;

use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\Procest\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Procest MCP Tool Provider.
 *
 * Implements IMcpToolProvider (from openregister PR #1466,
 * change ai-chat-companion-orchestrator) exposing 2 read-only tools to the
 * AI Chat Companion. This is an MVP skeleton — the full tool set tracked in
 * ConductionNL/procest#416 (startProcess, advanceStep, listMyTasks,
 * getTaskDetails) is intentionally out of scope of this change.
 *
 * Auth design (OWASP A01:2021 / ADR-005):
 * - Per-object authorisation runs inside invokeTool(), AFTER argument
 *   validation but BEFORE business logic. The helper actually runs — it does
 *   NOT return true unconditionally and is NOT wrapped in catch(\Throwable).
 * - isAdmin() resolves via IGroupManager (the dedicated procest admin group OR
 *   the Nextcloud system admin group), mirroring StatusTransitionService.
 * - A non-admin caller may read a case only when they are its assignee
 *   (primary handler) or hold a role record linking them to the case.
 */
class ProcestToolProvider implements IMcpToolProvider
{

    /**
     * Maximum number of items / source descriptors returned per tool result.
     *
     * @var int
     */
    private const ITEMS_CAP = 20;

    /**
     * Hard upper bound for the listProcesses limit argument.
     *
     * @var int
     */
    private const LIMIT_MAX = 50;

    /**
     * Dedicated procest admin group id (mirrors StatusTransitionService).
     *
     * @var string
     */
    private const ADMIN_GROUP_ID = 'procest-admin';

    /**
     * Tool catalogue — hard-coded so unit tests can assert it as a fixture.
     *
     * Exactly 2 read-only tools for the MVP skeleton.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TOOL_DESCRIPTORS = [
        [
            'id'          => 'procest.listProcesses',
            'name'        => 'List processes',
            'description' => 'List running process instances (cases). Optionally filter by status'
                .' type id (status) and cap the result with limit (1-50, default 20).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'limit'  => [
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => 50,
                        'default' => 20,
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => 'Optional status type id or slug to filter by.',
                    ],
                ],
                'required'   => [],
            ],
        ],
        [
            'id'          => 'procest.getProcessDetails',
            'name'        => 'Get process details',
            'description' => 'Get one process instance (case) by id or uuid, including its'
                .' current step (status) and chronological transition history.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'id'   => [
                        'type'        => 'string',
                        'description' => 'The process instance (case) id or uuid.',
                    ],
                    'uuid' => [
                        'type'        => 'string',
                        'description' => 'Alias for id — the process instance (case) uuid.',
                    ],
                ],
                'required'   => [],
            ],
        ],
    ];

    /**
     * Constructor for ProcestToolProvider.
     *
     * @param SettingsService $settingsService The Procest settings service (OpenRegister bridge + config)
     * @param IUserSession    $userSession     The current user session
     * @param IGroupManager   $groupManager    The group manager (for admin checks)
     * @param LoggerInterface $logger          The PSR-3 logger
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the app ID that namespaces every tool id.
     *
     * @return string "procest"
     */
    public function getAppId(): string
    {
        return 'procest';

    }//end getAppId()

    /**
     * Returns the full tool catalogue (2 tools, always).
     *
     * The full catalogue is returned regardless of caller permissions.
     * Per-object authorisation runs in invokeTool().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTools(): array
    {
        return self::TOOL_DESCRIPTORS;

    }//end getTools()

    /**
     * Dispatch a tool call by id.
     *
     * Argument validation runs BEFORE authorisation, which runs BEFORE
     * business logic. Unknown tool ids return a structured error; no
     * exception is thrown.
     *
     * @param string               $toolId    The tool id (e.g. "procest.listProcesses")
     * @param array<string, mixed> $arguments Tool arguments from the LLM call
     *
     * @return array<string, mixed>
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        switch ($toolId) {
            case 'procest.listProcesses':
                return $this->handleListProcesses(args: $arguments);
            case 'procest.getProcessDetails':
                return $this->handleGetProcessDetails(args: $arguments);
            default:
                return $this->errorEnvelope(
                    code: 'unknown_tool',
                    message: "Unknown tool id '{$toolId}'. Available tools: "
                        .implode(', ', array_column(self::TOOL_DESCRIPTORS, 'id')).'.'
                );
        }//end switch

    }//end invokeTool()

    // =========================================================================
    // Private tool handlers
    // =========================================================================

    /**
     * Handle procest.listProcesses.
     *
     * Lists running process instances (cases) the caller may read.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleListProcesses(array $args): array
    {
        $limit = $this->parseLimit(args: $args);
        if ($limit === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Invalid limit. Must be an integer between 1 and 50.');
        }

        $store = $this->resolveCaseStore();
        if (isset($store['error']) === true) {
            return $store;
        }

        $filters  = $this->buildListFilters(args: $args);
        $rawCases = $this->findCases(store: $store, filters: $filters, limit: $limit);
        if ($rawCases === null) {
            return $this->errorEnvelope(code: 'internal_error', message: 'Failed to list processes. See server log for details.');
        }

        $items   = [];
        $sources = [];
        foreach ($rawCases as $raw) {
            $case = $this->toArray(value: $raw);
            if ($this->canReadCase(case: $case) === false) {
                continue;
            }

            $items[]   = $case;
            $sources[] = $this->buildCaseSource(case: $case);
        }

        return [
            'success'   => true,
            'processes' => array_slice($items, 0, self::ITEMS_CAP),
            'sources'   => array_slice($sources, 0, self::ITEMS_CAP),
        ];

    }//end handleListProcesses()

    /**
     * Handle procest.getProcessDetails.
     *
     * Fetches one process instance (case) with its current step (status) and
     * chronological transition history.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function handleGetProcessDetails(array $args): array
    {
        $caseId = $this->parseCaseId(args: $args);
        if ($caseId === null) {
            return $this->errorEnvelope(code: 'invalid_arguments', message: 'Required argument id (or uuid) is missing.');
        }

        $store = $this->resolveCaseStore();
        if (isset($store['error']) === true) {
            return $store;
        }

        $case = $this->findCase(store: $store, caseId: $caseId);
        if ($case === null) {
            return $this->errorEnvelope(code: 'internal_error', message: 'Failed to load the process. See server log for details.');
        }

        if ($case === []) {
            return $this->errorEnvelope(code: 'not_found', message: 'Process not found.');
        }

        // Authorisation BEFORE business logic — actually runs, not wrapped in catch.
        if ($this->canReadCase(case: $case) === false) {
            return $this->errorEnvelope(code: 'forbidden', message: 'You are not authorised to read this process.');
        }

        $caseUuid = $this->extractUuid(item: $case);
        $history  = $this->loadHistory(store: $store, caseUuid: $caseUuid);

        return [
            'success'     => true,
            'process'     => $case,
            'currentStep' => ($case['status'] ?? null),
            'history'     => array_slice($history, 0, self::ITEMS_CAP),
            'sources'     => [$this->buildCaseSource(case: $case)],
        ];

    }//end handleGetProcessDetails()

    // =========================================================================
    // Private helpers — argument parsing
    // =========================================================================

    /**
     * Parse and validate the optional `limit` argument.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return int|null The clamped limit, or null when the supplied value is out of range.
     */
    private function parseLimit(array $args): ?int
    {
        if (isset($args['limit']) === false) {
            return self::ITEMS_CAP;
        }

        $limit = (int) $args['limit'];
        if ($limit < 1 || $limit > self::LIMIT_MAX) {
            return null;
        }

        return $limit;

    }//end parseLimit()

    /**
     * Build the OpenRegister filter map for procest.listProcesses.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return array<string, mixed>
     */
    private function buildListFilters(array $args): array
    {
        $filters = [];
        if (isset($args['status']) === true && $args['status'] !== '') {
            $filters['status'] = (string) $args['status'];
        }

        return $filters;

    }//end buildListFilters()

    /**
     * Resolve the case id from either the `id` or `uuid` argument.
     *
     * @param array<string, mixed> $args Tool arguments
     *
     * @return string|null The case id, or null when neither argument is supplied.
     */
    private function parseCaseId(array $args): ?string
    {
        if (isset($args['id']) === true && $args['id'] !== '') {
            return (string) $args['id'];
        }

        if (isset($args['uuid']) === true && $args['uuid'] !== '') {
            return (string) $args['uuid'];
        }

        return null;

    }//end parseCaseId()

    // =========================================================================
    // Private helpers — OpenRegister access
    // =========================================================================

    /**
     * Resolve the OpenRegister object store + configured register/case schema.
     *
     * @return array{objectService: object, register: string, caseSchema: string}|array{error: array{code: string, message: string}}
     */
    private function resolveCaseStore(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $this->errorEnvelope(code: 'storage_unavailable', message: 'The OpenRegister object store is not available.');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            return $this->errorEnvelope(code: 'not_configured', message: 'The Procest case schema is not configured.');
        }

        return [
            'objectService' => $objectService,
            'register'      => $register,
            'caseSchema'    => $caseSchema,
        ];

    }//end resolveCaseStore()

    /**
     * Find cases via the OpenRegister object store.
     *
     * @param array<string, mixed> $store   The resolved case store
     * @param array<string, mixed> $filters The OpenRegister filter map
     * @param int                  $limit   The maximum number of rows to fetch
     *
     * @return array<int, mixed>|null The raw rows, or null on backend failure.
     */
    private function findCases(array $store, array $filters, int $limit): ?array
    {
        try {
            $rows = $store['objectService']->findObjects(
                $store['register'],
                $store['caseSchema'],
                $filters,
                [],
                $limit,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest MCP: listProcesses findObjects failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        if (is_array($rows) === false) {
            return [];
        }

        return $rows;

    }//end findCases()

    /**
     * Find a single case via the OpenRegister object store.
     *
     * @param array<string, mixed> $store  The resolved case store
     * @param string               $caseId The case id or uuid
     *
     * @return array<string, mixed>|null The case array (empty when not found), or null on backend failure.
     */
    private function findCase(array $store, string $caseId): ?array
    {
        try {
            return $this->toArray(value: $store['objectService']->find($caseId, register: $store['register'], schema: $store['caseSchema']));
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest MCP: getProcessDetails findObject failed',
                ['caseId' => $caseId, 'exception' => $e->getMessage()]
            );
            return null;
        }

    }//end findCase()

    /**
     * Load the chronological transition history (statusRecord rows) for a case.
     *
     * @param array<string, mixed> $store    The resolved case store
     * @param string               $caseUuid The case uuid
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadHistory(array $store, string $caseUuid): array
    {
        if ($caseUuid === '') {
            return [];
        }

        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($recordSchema === '') {
            return [];
        }

        try {
            $records = $store['objectService']->findObjects(
                $store['register'],
                $recordSchema,
                ['case' => $caseUuid],
                [],
                self::ITEMS_CAP,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest MCP: loadHistory findObjects failed',
                ['caseUuid' => $caseUuid, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $rows = [];
        if (is_array($records) === true) {
            $rows = $records;
        }

        $list = [];
        foreach ($rows as $record) {
            $list[] = $this->toArray(value: $record);
        }

        usort(
            $list,
            static function (array $left, array $right): int {
                $leftAt  = (string) ($left['createdAt'] ?? ($left['@self']['createdAt'] ?? ''));
                $rightAt = (string) ($right['createdAt'] ?? ($right['@self']['createdAt'] ?? ''));
                return strcmp($leftAt, $rightAt);
            }
        );

        return $list;

    }//end loadHistory()

    // =========================================================================
    // Private helpers — authorisation
    // =========================================================================

    /**
     * Check whether the calling user may read a case.
     *
     * Auth design (OWASP A01:2021 / ADR-005):
     * - This helper actually runs — it does NOT return true unconditionally
     *   and is NOT wrapped in catch(\Throwable).
     * - An admin (procest admin group OR system admin group) may read any case.
     * - A non-admin may read a case only when they are its assignee (primary
     *   handler) or hold a role record linking them to the case.
     *
     * @param array<string, mixed> $case The case object as an associative array
     *
     * @return bool True when the caller may read the case.
     */
    private function canReadCase(array $case): bool
    {
        $userId = $this->currentUserId();
        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        $assignee = $case['assignee'] ?? null;
        if ($assignee !== null && (string) $assignee === $userId) {
            return true;
        }

        return $this->hasRoleOnCase(caseUuid: $this->extractUuid(item: $case), userId: $userId);

    }//end canReadCase()

    /**
     * Check whether the user holds a role record linking them to the case.
     *
     * @param string $caseUuid The case uuid
     * @param string $userId   The Nextcloud user id
     *
     * @return bool True when at least one role record links the user to the case.
     */
    private function hasRoleOnCase(string $caseUuid, string $userId): bool
    {
        if ($caseUuid === '') {
            return false;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return false;
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $roleSchema = $this->settingsService->getConfigValue(key: 'role_schema');
        if ($register === '' || $roleSchema === '') {
            return false;
        }

        try {
            $roles = $objectService->findObjects(
                $register,
                $roleSchema,
                [
                    'case'        => $caseUuid,
                    'participant' => $userId,
                ],
                [],
                1,
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest MCP: hasRoleOnCase findObjects failed',
                ['caseUuid' => $caseUuid, 'exception' => $e->getMessage()]
            );
            return false;
        }

        return is_array($roles) === true && count($roles) > 0;

    }//end hasRoleOnCase()

    /**
     * Resolve the current user id, or an empty string when unauthenticated.
     *
     * @return string
     */
    private function currentUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();

    }//end currentUserId()

    /**
     * Check whether the user is a Procest or Nextcloud system administrator.
     *
     * @param string $userId The Nextcloud user id
     *
     * @return bool True when the user is an admin.
     */
    private function isAdmin(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        try {
            if ($this->groupManager->isInGroup($userId, self::ADMIN_GROUP_ID) === true) {
                return true;
            }

            return $this->groupManager->isAdmin($userId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest MCP: admin check failed',
                ['userId' => $userId, 'exception' => $e->getMessage()]
            );
            return false;
        }

    }//end isAdmin()

    // =========================================================================
    // Private helpers — shaping
    // =========================================================================

    /**
     * Build a structured error envelope (never thrown).
     *
     * @param string $code    Machine-readable error code
     * @param string $message Human-readable message
     *
     * @return array{error: array{code: string, message: string}}
     */
    private function errorEnvelope(string $code, string $message): array
    {
        return [
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];

    }//end errorEnvelope()

    /**
     * Build a source descriptor for a case.
     *
     * @param array<string, mixed> $case The case array
     *
     * @return array{type: string, uuid: string, url: string, label: string}
     */
    private function buildCaseSource(array $case): array
    {
        $uuid = $this->extractUuid(item: $case);
        return [
            'type'  => 'procest.case',
            'uuid'  => $uuid,
            'url'   => "/apps/procest/cases/{$uuid}",
            'label' => (string) ($case['title'] ?? ($case['identifier'] ?? 'Case')),
        ];

    }//end buildCaseSource()

    /**
     * Normalise an OpenRegister object (entity / array / null) to a plain array.
     *
     * @param mixed $value Raw value from ObjectService
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === false) {
            return [];
        }

        if (method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (method_exists($value, 'getObject') === true) {
            $object = $value->getObject();
            if (is_array($object) === true) {
                return $object;
            }
        }

        return (array) $value;

    }//end toArray()

    /**
     * Extract the uuid from a normalised object array.
     *
     * @param array<string, mixed> $item The normalised object array
     *
     * @return string The uuid, or empty string when not found.
     */
    private function extractUuid(array $item): string
    {
        $uuid = $item['uuid'] ?? ($item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? '')));
        return (string) $uuid;

    }//end extractUuid()
}//end class
