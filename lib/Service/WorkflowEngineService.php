<?php

/**
 * Procest WorkflowEngineService
 *
 * Unified facade over the procest workflow engine: definition lookup,
 * available-transition listing, guard evaluation, and transition execution
 * with automatic-action side-effect dispatch.
 *
 * This service is the public consumer entry point named in the
 * workflow-engine-enhancement spec (W-3); the underlying mechanics are
 * already implemented as smaller services (WorkflowDefinitionService,
 * StatusTransitionService, GuardRegistry, ActionHandlerRegistry,
 * SideEffectDispatcher). The facade unifies them behind the four method
 * signatures the spec promises:
 *
 *   - getActiveWorkflow($caseTypeId)
 *   - getAvailableTransitions($caseId, $userId = null)
 *   - evaluateGuards($transition, $case, $userId = null)
 *   - executeTransition($caseId, $transitionId, $userId = null, $comment = null)
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Transitions\GuardRegistry;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public facade for the procest workflow engine.
 */
class WorkflowEngineService
{
    /**
     * Constructor.
     *
     * @param WorkflowDefinitionService $definitionService Workflow definition CRUD/lookup.
     * @param StatusTransitionService   $transitionService Transition execution engine.
     * @param GuardRegistry             $guardRegistry     Strategy-pattern guard registry.
     * @param LoggerInterface           $logger            Logger.
     */
    public function __construct(
        private readonly WorkflowDefinitionService $definitionService,
        private readonly StatusTransitionService $transitionService,
        private readonly GuardRegistry $guardRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the currently-active workflow definition for a case type.
     *
     * Delegates to WorkflowDefinitionService::getActiveDefinitionFor() so that
     * the validFrom/validUntil temporal-validity rules in W-5 are applied
     * consistently with the rest of the engine.
     *
     * @param string $caseTypeId The case-type id.
     *
     * @return array<string, mixed>|null The active definition, or null when none.
     *
     * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
     */
    public function getActiveWorkflow(string $caseTypeId): ?array
    {
        return $this->definitionService->getActiveDefinitionFor(caseTypeId: $caseTypeId);
    }//end getActiveWorkflow()

    /**
     * Resolve a workflow definition by case id (uses case.workflowTemplate +
     * case.workflowVersion binding).
     *
     * @param string $caseId The case id.
     *
     * @return array<string, mixed>|null The bound workflow definition.
     *
     * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-5
     */
    public function getWorkflowForCase(string $caseId): ?array
    {
        return $this->definitionService->getDefinitionForCase(caseId: $caseId);
    }//end getWorkflowForCase()

    /**
     * List the transitions the given user can attempt from the case's current
     * status. Guard evaluation is performed per-transition; transitions whose
     * roleGuard hides them are silently filtered out, while non-role failed
     * guards are returned with `available: false` + `unmetGuards: [...]` so
     * the UI can render disabled buttons with explanations.
     *
     * @param string      $caseId The case id.
     * @param string|null $userId Optional user id; defaults to the current user.
     *
     * @return array<int, array<string, mixed>> The transitions list.
     *
     * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
     */
    public function getAvailableTransitions(string $caseId, ?string $userId=null): array
    {
        return $this->transitionService->getAvailableTransitions(caseId: $caseId, userId: $userId);
    }//end getAvailableTransitions()

    /**
     * Evaluate a single transition's guards against a case + user.
     *
     * @param array<string, mixed> $transition The transition definition.
     * @param array<string, mixed> $case       The hydrated case object.
     * @param string|null          $userId     Optional user id; defaults to the current user.
     *
     * @return array{isSatisfied: bool, unmetGuards: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
     */
    public function evaluateGuards(array $transition, array $case, ?string $userId=null): array
    {
        $guards = $this->extractGuards(transition: $transition);

        $results     = $this->guardRegistry->evaluateAll(
            guards: $guards,
            case: $case,
            userId: (string) ($userId ?? '')
        );
        $isSatisfied = $this->guardRegistry->allPassed(results: $results);

        $unmet = [];
        foreach ($results as $r) {
            $passed = (bool) ($r['passed'] ?? ($r['result']['passed'] ?? false));
            if ($passed === false) {
                $unmet[] = $r;
            }
        }

        return [
            'isSatisfied' => $isSatisfied,
            'unmetGuards' => $unmet,
        ];
    }//end evaluateGuards()

    /**
     * Execute a transition by id on a case. Guards are re-evaluated server-side
     * by the underlying StatusTransitionService; on guard failure the
     * transition is refused and the unmet guards are surfaced to the caller.
     *
     * @param string      $caseId       The case id.
     * @param string      $transitionId The transition id.
     * @param string|null $userId       Optional user id; defaults to the current user.
     * @param string|null $comment      Optional comment for the transition record.
     *
     * @return array<string, mixed> The transition outcome envelope.
     *
     * @spec openspec/changes/workflow-engine-enhancement/tasks.md#W-3
     */
    public function executeTransition(
        string $caseId,
        string $transitionId,
        ?string $userId=null,
        ?string $comment=null,
    ): array {
        try {
            return $this->transitionService->execute(
                caseId: $caseId,
                transitionId: $transitionId,
                comment: $comment,
                userId: $userId,
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'WorkflowEngineService::executeTransition failed: '.$e->getMessage(),
                [
                    'app'          => Application::APP_ID,
                    'caseId'       => $caseId,
                    'transitionId' => $transitionId,
                ]
            );
            throw $e;
        }
    }//end executeTransition()

    /**
     * Extract the guards array from a transition definition, recognising both
     * the modern `guards: [{ type, ... }]` shape and the legacy `allowedRoles`
     * promotion form. Mirrors StatusTransitionService::extractGuards() so the
     * facade and the engine read the same definition consistently.
     *
     * @param array<string, mixed> $transition The transition.
     *
     * @return array<int, array<string, mixed>> The guards list.
     */
    private function extractGuards(array $transition): array
    {
        $guards = $transition['guards'] ?? [];
        if (is_array($guards) === false) {
            $guards = [];
        }

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
}//end class
