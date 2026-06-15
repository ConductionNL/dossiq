<?php

/**
 * Procest Status Transition Service.
 *
 * The single deterministic write path for `case.status` across Procest. The
 * REST API, the case detail UI, and the bezwaar/parafering/VTH workflow
 * specs all funnel transitions through `execute()`. Responsibilities:
 *
 *  - load the active workflow template per caseType (via WorkflowTemplateLoader)
 *  - evaluate every transition's guards (via GuardRegistry)
 *  - update `case.status` atomically with a `statusRecord` write
 *  - dispatch automatic actions sequentially (via SideEffectDispatcher)
 *  - replay transition history from the `statusRecord` chain
 *
 * Identity is ALWAYS derived from IUserSession when the caller does not pass
 * an explicit userId. Static error messages only — never bubble exception
 * detail to controllers or callers.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

use OCA\Procest\Service\Transitions\GuardFailedException;
use OCA\Procest\Service\Transitions\GuardRegistry;
use OCA\Procest\Service\Transitions\SideEffectDispatcher;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The status-transition engine.
 *
 * @spec openspec/changes/status-transition-engine/tasks.md#T10
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates many collaborators by design
 */
class StatusTransitionService
{

    /**
     * Group ID used to gate admin-only free-form transitions. Matches the
     * naming used elsewhere in Procest for the admin role.
     */
    public const ADMIN_GROUP_ID = 'procest-admin';

    /**
     * Constructor.
     *
     * @param SettingsService        $settingsService      Bridge to OpenRegister + config
     * @param WorkflowTemplateLoader $templateLoader       Active workflowTemplate loader
     * @param GuardRegistry          $guardRegistry        Guard registry
     * @param SideEffectDispatcher   $sideEffectDispatcher Side-effect dispatcher
     * @param IUserSession           $userSession          Current session
     * @param IGroupManager          $groupManager         Group manager (admin gate)
     * @param LoggerInterface        $logger               Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly WorkflowTemplateLoader $templateLoader,
        private readonly GuardRegistry $guardRegistry,
        private readonly SideEffectDispatcher $sideEffectDispatcher,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Compute the set of transitions available to a user on a case.
     *
     * @param string      $caseId Case UUID
     * @param string|null $userId Optional explicit user UID; defaults to IUserSession
     *
     * @return array{transitions: array<int, array<string, mixed>>, current: array<string, mixed>}

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function getAvailableTransitions(string $caseId, ?string $userId=null): array
    {
        $userId = $this->resolveUserId(explicit: $userId);
        $case   = $this->loadCase(caseId: $caseId);
        if ($case === null) {
            return ['transitions' => [], 'current' => []];
        }

        $caseTypeId = (string) ($case['caseType'] ?? '');
        $currentId  = (string) ($case['status'] ?? '');
        $template   = $this->templateLoader->getActiveTemplate(caseTypeId: $caseTypeId);

        $result = [
            'transitions' => [],
            'current'     => ['statusId' => $currentId, 'statusName' => $this->lookupStatusName(statusTypeId: $currentId)],
        ];

        if ($template === null) {
            return $result;
        }

        $transitions = $template['transitions'] ?? [];
        if (is_array($transitions) === false) {
            return $result;
        }

        foreach ($transitions as $transition) {
            if (is_array($transition) === false) {
                continue;
            }

            if ((string) ($transition['fromStatus'] ?? '') !== $currentId) {
                continue;
            }

            $guards = $this->extractGuards(transition: $transition);
            $eval   = $this->guardRegistry->evaluateAll(guards: $guards, case: $case, userId: $userId);

            // Drop transitions whose role guard hides them silently.
            if ($this->isRoleHidden(evalResults: $eval) === true) {
                continue;
            }

            $failed = array_values(array_filter($eval, static fn(array $g): bool => $g['passed'] === false));

            $result['transitions'][] = [
                'id'           => (string) ($transition['id'] ?? ''),
                'label'        => (string) ($transition['label'] ?? ''),
                'toStatus'     => (string) ($transition['toStatus'] ?? ''),
                'guardsPassed' => count($failed) === 0,
                'failedGuards' => $failed,
            ];
        }//end foreach

        return $result;
    }//end getAvailableTransitions()

    /**
     * Execute a guarded transition.
     *
     * @param string      $caseId       Case UUID
     * @param string      $transitionId Transition id from the active workflowTemplate
     * @param string|null $comment      Optional free-form comment
     * @param string|null $userId       Optional explicit user UID; defaults to IUserSession
     *
     * @return array{status: string, statusRecord: array<string, mixed>, dispatchedActions: array<int, array<string, mixed>>, version: int}
     *
     * @throws GuardFailedException When server-side re-evaluation fails any guard
     * @throws RuntimeException     When case/transition/template are not found

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function execute(string $caseId, string $transitionId, ?string $comment, ?string $userId=null): array
    {
        $userId = $this->resolveUserId(explicit: $userId);
        $case   = $this->loadCase(caseId: $caseId);
        if ($case === null) {
            throw new RuntimeException('case_not_found');
        }

        // H2: Capture the @self.version at read time for the optimistic lock check below.
        $readVersion = (int) (($case['@self']['version'] ?? ($case['version'] ?? 0)));

        $caseTypeId = (string) ($case['caseType'] ?? '');
        $transition = $this->templateLoader->getTransitionById(caseTypeId: $caseTypeId, transitionId: $transitionId);
        if ($transition === null) {
            throw new RuntimeException('transition_not_found');
        }

        $currentId  = (string) ($case['status'] ?? '');
        $fromStatus = (string) ($transition['fromStatus'] ?? '');
        if ($fromStatus !== '' && $fromStatus !== $currentId) {
            throw new RuntimeException('transition_from_status_mismatch');
        }

        // OR-RBAC role-routing gate (ADR-022). At publish time
        // WorkflowDefinitionService resolves each transition's assignee role
        // to its `roleType.ncGroupId` and freezes the literal group id(s) on
        // the transition `authorization` list — the same OR PR #153 gate
        // format OR enforces declaratively on schemas that carry an
        // x-openregister-lifecycle. `case.status` is a per-caseType dynamic
        // state machine with no static lifecycle table, so OR cannot enforce
        // it on saveObject; this engine therefore enforces the SAME group
        // model here using OR's single trusted membership check (IGroupManager),
        // not a bespoke role-resolution scheme. An empty/absent list = open.
        if ($this->isTransitionGroupAuthorized(transition: $transition, userId: $userId) === false) {
            throw new RuntimeException('transition_unauthorized');
        }

        // Defence in depth — re-evaluate guards on the server side.
        $guards = $this->extractGuards(transition: $transition);
        $eval   = $this->guardRegistry->evaluateAll(guards: $guards, case: $case, userId: $userId);
        $failed = array_values(array_filter($eval, static fn(array $g): bool => $g['passed'] === false));
        // @phpstan-ignore greaterThan.alwaysFalse (PHPDoc type marks passed as bool, but runtime values may differ)
        if (count($failed) > 0) {
            $this->logger->info('StatusTransitionService: guards failed', ['caseId' => $caseId, 'transitionId' => $transitionId]);
            throw new GuardFailedException(failedGuards: $failed);
        }

        $toStatus = (string) ($transition['toStatus'] ?? '');
        if ($toStatus === '') {
            throw new RuntimeException('transition_missing_to_status');
        }

        // H2: Optimistic concurrency guard — re-load the case immediately before writing
        // and abort if its status changed since we read it (concurrent transition executed
        // between our guard evaluation and our save).
        $caseAtSave = $this->loadCase(caseId: $caseId);
        if ($caseAtSave === null) {
            throw new RuntimeException('case_not_found');
        }

        $versionAtSave = (int) (($caseAtSave['@self']['version'] ?? ($caseAtSave['version'] ?? 0)));
        if ($versionAtSave !== $readVersion) {
            throw new RuntimeException('transition_conflict');
        }

        $statusAtSave = (string) ($caseAtSave['status'] ?? '');
        if ($statusAtSave !== $currentId) {
            throw new RuntimeException('transition_conflict');
        }

        // Status mutation BEFORE side-effects per REQ-STE-5-002.
        // Include @self.version so the store can detect a concurrent modification.
        $caseAtSave['status'] = $toStatus;
        if (isset($caseAtSave['@self']) === false || is_array($caseAtSave['@self']) === false) {
            $caseAtSave['@self'] = [];
        }

        $caseAtSave['@self']['version'] = $readVersion;
        $savedCase    = $this->saveCase(case: $caseAtSave);
        $savedVersion = (int) (($savedCase['@self']['version'] ?? ($savedCase['version'] ?? 0)));

        // Alias for the remainder of the method.
        $case = $savedCase;

        $label  = (string) ($transition['label'] ?? '');
        $record = $this->writeStatusRecord(
            caseId: $caseId,
            toStatus: $toStatus,
            fromStatus: $currentId,
            label: $label,
            comment: $comment,
            evaluatedGuards: $eval,
            noWorkflowTemplate: false,
        );

        $statusRecordId = (string) ($record['id'] ?? '');
        $context        = [
            'fromStatus'       => $currentId,
            'toStatus'         => $toStatus,
            'transitionLabel'  => $label,
            'userId'           => $userId,
            'statusRecordUuid' => $statusRecordId,
        ];

        $actions    = $this->extractActions(transition: $transition);
        $dispatched = $this->sideEffectDispatcher->dispatch(actions: $actions, case: $case, transitionContext: $context);

        // Update the statusRecord with the actual dispatched-action results.
        if ($statusRecordId !== '') {
            $record['dispatchedActions'] = $dispatched;
            try {
                $record = $this->updateStatusRecord(record: $record);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'StatusTransitionService: dispatchedActions persist failed',
                    ['exception' => $e->getMessage(), 'statusRecord' => $statusRecordId],
                );
            }
        }

        return [
            'status'            => 'ok',
            'statusRecord'      => $record,
            'dispatchedActions' => $dispatched,
            'version'           => $savedVersion,
        ];
    }//end execute()

    /**
     * Execute an admin-only free-form transition for caseTypes without an active workflow template.
     *
     * @param string      $caseId     Case UUID
     * @param string      $toStatusId Target statusType UUID
     * @param string|null $comment    Optional free-form comment
     * @param string|null $userId     Optional explicit user UID; defaults to IUserSession
     *
     * @return array{status: string, statusRecord: array<string, mixed>}
     *
     * @throws RuntimeException When the caller is not in the admin group or the target is invalid

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function executeFreeForm(string $caseId, string $toStatusId, ?string $comment, ?string $userId=null): array
    {
        $userId = $this->resolveUserId(explicit: $userId);
        if ($this->isAdmin(userId: $userId) === false) {
            throw new RuntimeException('forbidden_admin_only');
        }

        $case = $this->loadCase(caseId: $caseId);
        if ($case === null) {
            throw new RuntimeException('case_not_found');
        }

        $caseTypeId = (string) ($case['caseType'] ?? '');
        $this->validateStatusBelongsToCaseType(caseTypeId: $caseTypeId, statusTypeId: $toStatusId);

        $currentId      = (string) ($case['status'] ?? '');
        $case['status'] = $toStatusId;
        $case           = $this->saveCase(case: $case);

        $record = $this->writeStatusRecord(
            caseId: $caseId,
            toStatus: $toStatusId,
            fromStatus: $currentId,
            label: 'Free-form transition',
            comment: $comment,
            evaluatedGuards: [],
            noWorkflowTemplate: true,
        );

        return ['status' => 'ok', 'statusRecord' => $record];
    }//end executeFreeForm()

    /**
     * Return the chronological history of transitions for a case.
     *
     * @param string $caseId Case UUID
     *
     * @return array{history: array<int, array<string, mixed>>, replayable: bool}

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function replay(string $caseId): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return ['history' => [], 'replayable' => false];
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            return ['history' => [], 'replayable' => false];
        }

        try {
            // OpenRegister's ObjectService exposes `searchObjects($query)` —
            // there is NO `findObjects()` method. Register/schema context lives
            // under the `@self` block; the `case` field filter sits at the top
            // level as a server-side equality match.
            $records = $objectService->searchObjects(
                [
                    '@self' => [
                        'register' => (int) $register,
                        'schema'   => (int) $recordSchema,
                    ],
                    'case'  => $caseId,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'StatusTransitionService: replay searchObjects failed',
                ['exception' => $e->getMessage(), 'caseId' => $caseId],
            );
            return ['history' => [], 'replayable' => false];
        }//end try

        $recordList = [];
        if (is_array($records) === true) {
            $recordList = $records;
        }

        $list = [];
        foreach ($recordList as $record) {
            $list[] = $this->toArray(value: $record);
        }

        usort(
            $list,
            static function (array $left, array $right): int {
                $leftAt  = (string) ($left['createdAt'] ?? ($left['@self']['createdAt'] ?? ''));
                $rightAt = (string) ($right['createdAt'] ?? ($right['@self']['createdAt'] ?? ''));
                return strcmp($leftAt, $rightAt);
            },
        );

        return ['history' => $list, 'replayable' => true];
    }//end replay()

    /**
     * Check if the current (or given) user is in the procest admin group.
     *
     * @param string $userId UID
     *
     * @return bool

     * @spec openspec/specs/status-transition-engine/spec.md
     */
    public function isAdmin(string $userId): bool
    {
        if ($userId === '') {
            return false;
        }

        try {
            // Accept membership in either the dedicated procest admin group OR the global admin group.
            if ($this->groupManager->isInGroup($userId, self::ADMIN_GROUP_ID) === true) {
                return true;
            }

            return $this->groupManager->isInGroup($userId, 'admin');
        } catch (\Throwable $e) {
            $this->logger->error('StatusTransitionService: admin check failed', ['exception' => $e->getMessage()]);
            return false;
        }
    }//end isAdmin()

    /**
     * Enforce a transition's OR-RBAC group authorization list.
     *
     * Consumes the `authorization` array frozen onto the transition at
     * publish time (literal NC group ids resolved from `roleType.ncGroupId`).
     * Semantics mirror OR's `PermissionHandler::isTransitionAuthorized`:
     *   - an absent or empty list authorises everyone (open transition);
     *   - an anonymous caller can never satisfy a group gate;
     *   - admins bypass;
     *   - otherwise the caller MUST belong to at least one listed group.
     *
     * @param array<string, mixed> $transition The transition spec.
     * @param string               $userId     The acting user UID.
     *
     * @return bool True when the caller may perform the transition.
     *
     * @spec openspec/changes/migrate-role-routing-to-or-rbac/tasks.md#P-2.1
     */
    private function isTransitionGroupAuthorized(array $transition, string $userId): bool
    {
        $authorization = ($transition['authorization'] ?? []);
        if (is_array($authorization) === false || $authorization === []) {
            return true;
        }

        if ($userId === '') {
            return false;
        }

        if ($this->isAdmin(userId: $userId) === true) {
            return true;
        }

        foreach ($authorization as $groupId) {
            $groupId = (string) $groupId;
            if ($groupId === '') {
                continue;
            }

            try {
                if ($this->groupManager->isInGroup($userId, $groupId) === true) {
                    return true;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'StatusTransitionService: group membership check failed',
                    ['exception' => $e->getMessage(), 'groupId' => $groupId],
                );
            }
        }

        return false;
    }//end isTransitionGroupAuthorized()

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Resolve a user UID either from the explicit parameter or IUserSession.
     *
     * @param string|null $explicit Caller-supplied UID, or null
     *
     * @return string
     */
    private function resolveUserId(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return '';
        }

        return $user->getUID();
    }//end resolveUserId()

    /**
     * Load a case from OpenRegister.
     *
     * @param string $caseId Case UUID
     *
     * @return array<string, mixed>|null
     */
    private function loadCase(string $caseId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            return null;
        }

        try {
            return $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
        } catch (\Throwable $e) {
            $this->logger->error(
                'StatusTransitionService: loadCase failed',
                ['exception' => $e->getMessage(), 'caseId' => $caseId],
            );
            return null;
        }
    }//end loadCase()

    /**
     * Persist the (mutated) case via ObjectService.
     *
     * @param array<string, mixed> $case Case payload
     *
     * @return array<string, mixed>
     */
    private function saveCase(array $case): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register   = $this->settingsService->getConfigValue(key: 'register');
        $caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
        if ($register === '' || $caseSchema === '') {
            throw new RuntimeException('case_schema_not_configured');
        }

        return $this->toArray(value: $objectService->saveObject(object: $case, register: $register, schema: $caseSchema));
    }//end saveCase()

    /**
     * Write a statusRecord row for a transition.
     *
     * @param string                           $caseId             Case UUID
     * @param string                           $toStatus           Target statusType UUID
     * @param string                           $fromStatus         Prior statusType UUID
     * @param string                           $label              Transition label
     * @param string|null                      $comment            Free-form comment
     * @param array<int, array<string, mixed>> $evaluatedGuards    Guard snapshots
     * @param bool                             $noWorkflowTemplate Flag for free-form transitions
     *
     * @return array<string, mixed>
     */
    private function writeStatusRecord(
        string $caseId,
        string $toStatus,
        string $fromStatus,
        string $label,
        ?string $comment,
        array $evaluatedGuards,
        bool $noWorkflowTemplate,
    ): array {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            throw new RuntimeException('status_record_schema_not_configured');
        }

        $payload = [
            'case'               => $caseId,
            'statusType'         => $toStatus,
            'transitionLabel'    => $label,
            'evaluatedGuards'    => $evaluatedGuards,
            'dispatchedActions'  => [],
            'noWorkflowTemplate' => $noWorkflowTemplate,
        ];
        if ($fromStatus !== '') {
            $payload['fromStatus'] = $fromStatus;
        }

        if ($comment !== null && $comment !== '') {
            $payload['description'] = $comment;
        }

        return $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $recordSchema));
    }//end writeStatusRecord()

    /**
     * Persist an updated statusRecord.
     *
     * @param array<string, mixed> $record Current record payload
     *
     * @return array<string, mixed>
     */
    private function updateStatusRecord(array $record): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $record;
        }

        $register     = $this->settingsService->getConfigValue(key: 'register');
        $recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
        if ($register === '' || $recordSchema === '') {
            return $record;
        }

        return $this->toArray(value: $objectService->saveObject(object: $record, register: $register, schema: $recordSchema));
    }//end updateStatusRecord()

    /**
     * Validate that a statusType belongs to the case's caseType.
     *
     * @param string $caseTypeId   CaseType UUID
     * @param string $statusTypeId StatusType UUID
     *
     * @return void
     *
     * @throws RuntimeException When the statusType is not a child of the caseType
     */
    private function validateStatusBelongsToCaseType(string $caseTypeId, string $statusTypeId): void
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('storage_unavailable');
        }

        $register       = $this->settingsService->getConfigValue(key: 'register');
        $caseTypeSchema = $this->settingsService->getConfigValue(key: 'case_type_schema');
        if ($register === '' || $caseTypeSchema === '' || $caseTypeId === '' || $statusTypeId === '') {
            throw new RuntimeException('case_type_not_configured');
        }

        try {
            $caseType = $this->toArray(value: $objectService->find($caseTypeId, register: $register, schema: $caseTypeSchema));
        } catch (\Throwable $e) {
            throw new RuntimeException('case_type_not_found');
        }

        $statuses = $caseType['statusTypes'] ?? ($caseType['statusses'] ?? []);
        if (is_array($statuses) === false) {
            $statuses = [];
        }

        foreach ($statuses as $entry) {
            $id = (string) $entry;
            if (is_array($entry) === true) {
                $id = (string) ($entry['id'] ?? ($entry['uuid'] ?? ''));
            }

            if ($id === $statusTypeId) {
                return;
            }
        }

        throw new RuntimeException('status_type_not_in_case_type');
    }//end validateStatusBelongsToCaseType()

    /**
     * Look up a human-readable status name for the case-detail panel header.
     *
     * @param string $statusTypeId StatusType UUID
     *
     * @return string
     */
    private function lookupStatusName(string $statusTypeId): string
    {
        if ($statusTypeId === '') {
            return '';
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return '';
        }

        $register         = $this->settingsService->getConfigValue(key: 'register');
        $statusTypeSchema = $this->settingsService->getConfigValue(key: 'status_type_schema');
        if ($register === '' || $statusTypeSchema === '') {
            return '';
        }

        try {
            $statusType = $this->toArray(value: $objectService->find($statusTypeId, register: $register, schema: $statusTypeSchema));
        } catch (\Throwable $e) {
            return '';
        }

        return (string) ($statusType['name'] ?? ($statusType['title'] ?? ''));
    }//end lookupStatusName()

    /**
     * Extract the guards list from a transition definition (supports both
     * `guards: []` and a single `guard: {...}` shape).
     *
     * @param array<string, mixed> $transition The transition
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractGuards(array $transition): array
    {
        $guards = $transition['guards'] ?? [];
        if (is_array($guards) === false) {
            $guards = [];
        }

        // Promote allowedRoles[] on the transition itself into a roleGuard entry.
        $allowedRoles = $transition['allowedRoles'] ?? null;
        if (is_array($allowedRoles) === true && count($allowedRoles) > 0) {
            $guards[] = ['type' => 'roleGuard', 'allowedRoles' => $allowedRoles];
        }

        $list = [];
        foreach ($guards as $guard) {
            if (is_array($guard) === true) {
                $list[] = $guard;
            }
        }

        return $list;
    }//end extractGuards()

    /**
     * Extract automaticActions[] from a transition definition.
     *
     * @param array<string, mixed> $transition The transition
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractActions(array $transition): array
    {
        $actions = $transition['automaticActions'] ?? ($transition['actions'] ?? []);
        if (is_array($actions) === false) {
            return [];
        }

        $list = [];
        foreach ($actions as $action) {
            if (is_array($action) === true) {
                $list[] = $action;
            }
        }

        return $list;
    }//end extractActions()

    /**
     * Detect whether the role guard has hidden the transition silently.
     *
     * @param array<int, array<string, mixed>> $evalResults Guard evaluation snapshots
     *
     * @return bool
     */
    private function isRoleHidden(array $evalResults): bool
    {
        foreach ($evalResults as $entry) {
            if (($entry['type'] ?? '') === 'roleGuard'
                && $entry['passed'] === false
                && (($entry['details']['silent'] ?? false) === true)
            ) {
                return true;
            }
        }

        return false;
    }//end isRoleHidden()

    /**
     * Coerce ObjectService results to an array.
     *
     * @param mixed $value Raw result
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialized = $value->jsonSerialize();
                if (is_array($serialized) === true) {
                    return $serialized;
                }
            }
        }

        return [];
    }//end toArray()
}//end class
