<?php

/**
 * Procest ParafeerRoute Service
 *
 * Routing engine for the B&W parafering workflow. Captures route snapshots on
 * voorstel submission, executes sequential step activation (task + notification),
 * advances the voorstel on step completion, and supports authorized override
 * operations (skip step, add ad-hoc step) with audit trail entries.
 *
 * Generic CRUD on parafeerroute objects is delegated to OpenRegister's
 * auto-exposed /api/objects/<register>/<schema> endpoints — this service
 * only owns workflow execution and read-only loads of related entities.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Event\ParafeerTransitionEvent;
use OCA\Procest\Service\Routing\RoutingStrategyMissingException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service implementing the parafeerroute execution engine.
 *
 * All identity is derived from IUserSession, never trusted from caller-supplied
 * data. Real exception details are logged; callers translate failures to static
 * messages.
 *
 * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates ObjectService + IUserSession
 */
class ParafeerRouteService
{
    /**
     * Voorstel status when actively in parafering.
     */
    public const STATUS_IN_PARAFERING = 'in_parafering';

    /**
     * Voorstel status after the final step accordering completes.
     */
    public const STATUS_GEACCORDEERD = 'geaccordeerd';

    /**
     * Parafeeractie action: skipped.
     */
    public const ACTION_SKIPPED = 'skipped';

    /**
     * Parafeeractie action: accorded (final accordering step).
     */
    public const ACTION_ACCORDED = 'accorded';

    /**
     * Constructor.
     *
     * @param SettingsService     $settingsService The Procest settings/config bridge to OpenRegister
     * @param IUserSession        $userSession     The current Nextcloud user session
     * @param LoggerInterface     $logger          The logger
     * @param RoleResolverService $roleResolver    Central role-routing engine
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
        private readonly RoleResolverService $roleResolver,
        private readonly IEventDispatcher $eventDispatcher,
    ) {
    }//end __construct()

    /**
     * Best-effort dispatch of a ParafeerTransitionEvent.
     *
     * Failures MUST NOT propagate back to the routing service — operational
     * transitions must not be blocked by audit-listener outages.
     *
     * @param string      $voorstelId The voorstel UUID/slug
     * @param string      $action     The transition action
     * @param string|null $step       Step identifier when applicable
     * @param string      $actor      The actor user UID
     * @param string      $actorRole  The actor role
     * @param string|null $reason     Reason text when applicable
     *
     * @return void
     */
    private function dispatchTransition(
        string $voorstelId,
        string $action,
        ?string $step,
        string $actor,
        string $actorRole,
        ?string $reason=null,
    ): void {
        try {
            $this->eventDispatcher->dispatchTyped(
                new ParafeerTransitionEvent(
                    voorstelId: $voorstelId,
                    action: $action,
                    step: $step,
                    actor: $actor,
                    actorRole: $actorRole,
                    reason: $reason,
                ),
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: ParafeerTransitionEvent dispatch failed',
                [
                    'voorstel'  => $voorstelId,
                    'action'    => $action,
                    'exception' => $e->getMessage(),
                ],
            );
        }//end try
    }//end dispatchTransition()

    /**
     * Start the parafering workflow for a voorstel.
     *
     * Captures a JSON-encoded snapshot of the linked route's steps onto the
     * voorstel, sets currentStep=1, transitions to in_parafering, and
     * activates step 1 (task + notification).
     *
     * @param string $voorstelId The voorstel UUID
     *
     * @return array<string, mixed> The updated voorstel
     *
     * @throws RuntimeException When voorstel/route cannot be loaded or no steps are defined
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
     */
    public function startParafering(string $voorstelId): array
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrapVoorstel();
        $routeSchema = $this->requireConfig(key: 'parafeerroute_schema');

        $voorstel = $this->toArray(value: $objectService->findObject($register, $voorstelSchema, $voorstelId));

        $routeRef = (string) ($voorstel['parafeerroute'] ?? '');
        if ($routeRef === '') {
            throw new RuntimeException('Voorstel has no linked parafeerroute');
        }

        $route = $this->toArray(value: $objectService->findObject($register, $routeSchema, $routeRef));
        $steps = $this->normalizeSteps(value: $route['steps'] ?? []);
        if (count($steps) === 0) {
            throw new RuntimeException('Linked parafeerroute has no steps');
        }

        $voorstel['routeSnapshot'] = json_encode($steps);
        $voorstel['currentStep']   = 1;
        $voorstel['status']        = self::STATUS_IN_PARAFERING;

        $voorstel = $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel));

        $this->activateStep(voorstel: $voorstel, step: 1, steps: $steps);

        $this->dispatchTransition(
            voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId),
            action: 'started',
            step: '1',
            actor: $this->requireUserId(),
            actorRole: 'steller',
        );

        return $voorstel;
    }//end startParafering()

    /**
     * Complete the current parafering step and advance to the next step.
     *
     * Records a parafeeractie, advances voorstel.currentStep, and either
     * activates the next step or marks the voorstel as geaccordeerd.
     *
     * @param string               $voorstelId The voorstel UUID
     * @param array<string, mixed> $actionData The parafeeractie payload (action, comment, advice, etc.)
     *
     * @return array<string, mixed> The updated voorstel
     *
     * @throws RuntimeException When the voorstel is not active or storage is unavailable
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
     */
    public function completeStep(string $voorstelId, array $actionData): array
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrapVoorstel();
        $actieSchema = $this->requireConfig(key: 'parafeeractie_schema');

        $voorstel = $this->toArray(value: $objectService->findObject($register, $voorstelSchema, $voorstelId));
        $steps    = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? '[]');

        if (($voorstel['status'] ?? '') !== self::STATUS_IN_PARAFERING) {
            throw new RuntimeException('Voorstel is not in parafering');
        }

        $currentStep = (int) ($voorstel['currentStep'] ?? 0);
        if ($currentStep < 1) {
            throw new RuntimeException('Voorstel has no active step');
        }

        $actieData = [
            'voorstel'  => $voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId,
            'step'      => $currentStep,
            'actor'     => $this->requireUserId(),
            'actorType' => (string) ($actionData['actorType'] ?? 'user'),
            'action'    => (string) ($actionData['action'] ?? 'parafered'),
        ];

        foreach (['comment', 'advice', 'mandate', 'onBehalfOf'] as $optional) {
            if (isset($actionData[$optional]) === true && $actionData[$optional] !== '') {
                $actieData[$optional] = (string) $actionData[$optional];
            }
        }

        $objectService->saveObject($register, $actieSchema, $actieData);

        $action     = (string) ($actionData['action'] ?? 'parafered');
        $transition = ($action === 'advised') ? 'advised' : 'paraferd';
        $actorRole  = ($action === 'advised') ? 'adviseur' : 'parafeerder';

        $this->dispatchTransition(
            voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId),
            action: $transition,
            step: (string) $currentStep,
            actor: $actieData['actor'],
            actorRole: $actorRole,
        );

        $voorstel = $this->advanceVoorstel(
            objectService: $objectService,
            register: $register,
            voorstelSchema: $voorstelSchema,
            voorstel: $voorstel,
            steps: $steps,
            fromStep: $currentStep,
        );

        return $voorstel;
    }//end completeStep()

    /**
     * Skip a (non-mandatory) step on a specific in-flight voorstel.
     *
     * Records a parafeeractie with action=skipped, appends an audit trail
     * entry on the voorstel, and advances currentStep if the skipped step
     * is the active one.
     *
     * @param string $voorstelId The voorstel UUID
     * @param int    $step       The step order to skip (1-based)
     * @param string $reason     Mandatory reason text
     *
     * @return array<string, mixed> The updated voorstel
     *
     * @throws RuntimeException When the step is mandatory or input is invalid
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
     */
    public function skipStep(string $voorstelId, int $step, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Reden is verplicht bij overslaan');
        }

        [$objectService, $register, $voorstelSchema] = $this->bootstrapVoorstel();
        $actieSchema = $this->requireConfig(key: 'parafeeractie_schema');

        $voorstel = $this->toArray(value: $objectService->findObject($register, $voorstelSchema, $voorstelId));
        $steps    = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? '[]');

        $target = null;
        foreach ($steps as $candidate) {
            if ((int) ($candidate['order'] ?? 0) === $step) {
                $target = $candidate;
                break;
            }
        }

        if ($target === null) {
            throw new RuntimeException('Stap niet gevonden in routeSnapshot');
        }

        if (($target['mandatory'] ?? true) === true) {
            throw new RuntimeException('Deze stap is verplicht en kan niet worden overgeslagen');
        }

        $userId = $this->requireUserId();

        $objectService->saveObject(
            $register,
            $actieSchema,
            [
                'voorstel'  => $voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId,
                'step'      => $step,
                'actor'     => $userId,
                'actorType' => 'user',
                'action'    => self::ACTION_SKIPPED,
                'comment'   => $reason,
            ],
        );

        $voorstel['routeSnapshot'] = json_encode(
            array_map(
                static function (array $candidate) use ($step): array {
                    if ((int) ($candidate['order'] ?? 0) === $step) {
                        $candidate['skipped'] = true;
                    }

                    return $candidate;
                },
                $steps,
            ),
        );

        $voorstel = $this->appendAuditTrail(
            voorstel: $voorstel,
            entry: [
                'action'    => 'step_skipped',
                'actor'     => $userId,
                'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'step'      => $step,
                'comment'   => sprintf(
                    "Stap overgeslagen: '%s' door %s, reden: %s",
                    (string) ($target['actor'] ?? ''),
                    $userId,
                    $reason,
                ),
            ],
        );

        $this->dispatchTransition(
            voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId),
            action: 'route-changed',
            step: (string) $step,
            actor: $userId,
            actorRole: 'beheerder',
            reason: $reason,
        );

        if ((int) ($voorstel['currentStep'] ?? 0) === $step) {
            return $this->advanceVoorstel(
                objectService: $objectService,
                register: $register,
                voorstelSchema: $voorstelSchema,
                voorstel: $voorstel,
                steps: $this->normalizeSteps(value: $voorstel['routeSnapshot']),
                fromStep: $step,
            );
        }

        return $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel));
    }//end skipStep()

    /**
     * Add an ad-hoc step to the routeSnapshot of a specific voorstel.
     *
     * Inserts the new step after the given order number, renumbers all
     * subsequent steps, and appends an audit trail entry. If currentStep
     * is already past the insertion point, the step is inserted immediately
     * after currentStep instead.
     *
     * @param string               $voorstelId The voorstel UUID
     * @param int                  $afterStep  The order to insert after
     * @param array<string, mixed> $stepData   The step fields (type, actor, actorType, mandatory)
     *
     * @return array<string, mixed> The updated voorstel
     *
     * @throws RuntimeException When the voorstel cannot be loaded
     *
     * @spec openspec/changes/parafeerroute-engine/tasks.md#T04
     */
    public function addAdhocStep(string $voorstelId, int $afterStep, array $stepData): array
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrapVoorstel();

        $voorstel = $this->toArray(value: $objectService->findObject($register, $voorstelSchema, $voorstelId));
        $steps    = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? '[]');

        $currentStep = (int) ($voorstel['currentStep'] ?? 0);
        $insertAfter = $afterStep;
        if ($currentStep > 0 && $afterStep < $currentStep) {
            // Cannot insert before the active step — clamp to immediately after current.
            $insertAfter = $currentStep;
        }

        $newStep = [
            'order'     => 0,
            'type'      => (string) ($stepData['type'] ?? 'advies'),
            'actor'     => (string) ($stepData['actor'] ?? ''),
            'actorType' => (string) ($stepData['actorType'] ?? 'user'),
            'mandatory' => (bool) ($stepData['mandatory'] ?? false),
        ];

        $rebuilt      = [];
        $orderCounter = 1;
        $inserted     = false;
        foreach ($steps as $candidate) {
            $candidate['order'] = $orderCounter;
            $rebuilt[]          = $candidate;
            $orderCounter++;
            if ((int) $candidate['order'] === $insertAfter && $inserted === false) {
                $newStep['order'] = $orderCounter;
                $rebuilt[]        = $newStep;
                $orderCounter++;
                $inserted = true;
            }
        }

        if ($inserted === false) {
            $newStep['order'] = $orderCounter;
            $rebuilt[]        = $newStep;
        }

        $voorstel['routeSnapshot'] = json_encode($rebuilt);

        $userId   = $this->requireUserId();
        $voorstel = $this->appendAuditTrail(
            voorstel: $voorstel,
            entry: [
                'action'    => 'step_added',
                'actor'     => $userId,
                'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'step'      => $newStep['order'],
                'comment'   => sprintf(
                    "Stap toegevoegd: '%s' door %s na stap %d",
                    $newStep['actor'],
                    $userId,
                    $insertAfter,
                ),
            ],
        );

        $saved = $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel));

        $this->dispatchTransition(
            voorstelId: (string) ($saved['id'] ?? $saved['uuid'] ?? $voorstelId),
            action: 'route-changed',
            step: (string) $newStep['order'],
            actor: $userId,
            actorRole: 'beheerder',
            reason: sprintf('Ad-hoc step inserted after step %d', $insertAfter),
        );

        return $saved;
    }//end addAdhocStep()

    /**
     * Advance the voorstel: either activate the next step or mark geaccordeerd.
     *
     * @param object                           $objectService  The OpenRegister ObjectService
     * @param string                           $register       The register slug/UUID
     * @param string                           $voorstelSchema The voorstel schema slug/UUID
     * @param array<string, mixed>             $voorstel       The current voorstel data
     * @param array<int, array<string, mixed>> $steps          The decoded routeSnapshot
     * @param int                              $fromStep       The step that just completed
     *
     * @return array<string, mixed>
     */
    private function advanceVoorstel(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
        array $steps,
        int $fromStep,
    ): array {
        $nextStep = null;
        foreach ($steps as $candidate) {
            $order = (int) ($candidate['order'] ?? 0);
            if ($order > $fromStep && ($candidate['skipped'] ?? false) !== true) {
                $nextStep = $order;
                break;
            }
        }

        if ($nextStep === null) {
            $voorstel['status'] = self::STATUS_GEACCORDEERD;
            $voorstel           = $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel));
            $this->logger->info(
                'Procest: voorstel {id} fully accorded',
                [
                    'id'  => $voorstel['id'] ?? '',
                    'app' => Application::APP_ID,
                ],
            );

            $this->dispatchTransition(
                voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? ''),
                action: 'completed',
                step: (string) $fromStep,
                actor: $this->safeUserId(),
                actorRole: 'accorderend',
            );

            return $voorstel;
        }//end if

        $voorstel['currentStep'] = $nextStep;
        $voorstel = $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel));

        $this->activateStep(voorstel: $voorstel, step: $nextStep, steps: $steps);

        return $voorstel;
    }//end advanceVoorstel()

    /**
     * Activate a step: log a notification intent and (best-effort) create a task.
     *
     * Notification dispatch and task creation are delegated to the platform
     * services when available. Failures are logged but do not abort routing.
     *
     * @param array<string, mixed>             $voorstel The voorstel
     * @param int                              $step     The step order to activate
     * @param array<int, array<string, mixed>> $steps    The decoded routeSnapshot
     *
     * @return void
     */
    private function activateStep(array $voorstel, int $step, array $steps): void
    {
        $stepInfo = null;
        foreach ($steps as $candidate) {
            if ((int) ($candidate['order'] ?? 0) === $step) {
                $stepInfo = $candidate;
                break;
            }
        }

        if ($stepInfo === null) {
            return;
        }

        $resolvedActors = $this->resolveStepActors(stepInfo: $stepInfo, voorstel: $voorstel);

        $this->logger->info(
            'Procest: activated parafering step {step} of voorstel {voorstelId} for actor {actor}',
            [
                'step'       => $step,
                'voorstelId' => $voorstel['id'] ?? $voorstel['uuid'] ?? '',
                'actor'      => (string) ($stepInfo['actor'] ?? ''),
                'resolved'   => $resolvedActors,
                'app'        => Application::APP_ID,
            ],
        );
    }//end activateStep()

    /**
     * Resolve the concrete actor set for a step.
     *
     * For role-typed actors, the step's actor UUID is treated as the
     * `roleType` parameter of an implicit single-role rule and dispatched to
     * the shared RoleResolverService — this inherits delegation + workload
     * features automatically. For user-typed actors the original UUID is
     * returned as-is.
     *
     * @param array<string, mixed> $stepInfo The step from routeSnapshot
     * @param array<string, mixed> $voorstel The voorstel object (provides caseRef + caseType)
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/role-based-step-routing/tasks.md#T07
     */
    private function resolveStepActors(array $stepInfo, array $voorstel): array
    {
        $actorType = (string) ($stepInfo['actorType'] ?? 'user');
        $actor     = (string) ($stepInfo['actor'] ?? '');
        if ($actor === '') {
            return [];
        }

        if ($actorType !== 'role') {
            return [$actor];
        }

        $caseRef = (string) ($voorstel['case'] ?? ($voorstel['zaak'] ?? ''));
        if ($caseRef === '') {
            return [$actor];
        }

        $case = ['id' => $caseRef, 'caseType' => (string) ($voorstel['caseType'] ?? '')];
        $rule = $stepInfo['routingRule'] ?? null;
        if (is_array($rule) === false || isset($rule['strategy']) === false) {
            $rule = [
                'strategy' => RoleResolverService::STRATEGY_SINGLE_ROLE,
                'roleType' => $actor,
            ];
        }

        try {
            return $this->roleResolver->resolve($rule, $case);
        } catch (RoutingStrategyMissingException $e) {
            $this->logger->warning(
                'Procest: parafering step references unknown routing strategy: '.$e->getMessage(),
            );
            return [$actor];
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: failed to resolve parafering step actors: '.$e->getMessage(),
            );
            return [$actor];
        }
    }//end resolveStepActors()

    /**
     * Append an entry to the voorstel auditTrail field.
     *
     * @param array<string, mixed> $voorstel The voorstel
     * @param array<string, mixed> $entry    The entry to append
     *
     * @return array<string, mixed>
     */
    private function appendAuditTrail(array $voorstel, array $entry): array
    {
        $trail = $voorstel['auditTrail'] ?? [];
        if (is_string($trail) === true) {
            $decoded = json_decode($trail, true);
            $trail   = [];
            if (is_array($decoded) === true) {
                $trail = $decoded;
            }
        }

        if (is_array($trail) === false) {
            $trail = [];
        }

        $trail[] = $entry;
        $voorstel['auditTrail'] = $trail;

        return $voorstel;
    }//end appendAuditTrail()

    /**
     * Normalize a steps value (JSON string or array) to a plain ordered array.
     *
     * @param mixed $value The raw value from routeSnapshot or schema field
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSteps(mixed $value): array
    {
        if (is_string($value) === true) {
            $decoded = json_decode($value, true);
            $value   = [];
            if (is_array($decoded) === true) {
                $value = $decoded;
            }
        }

        if (is_array($value) === false) {
            return [];
        }

        $steps = [];
        foreach ($value as $candidate) {
            if (is_array($candidate) === true) {
                $steps[] = $candidate;
            }
        }

        usort(
            $steps,
            static function (array $left, array $right): int {
                return ((int) ($left['order'] ?? 0)) <=> ((int) ($right['order'] ?? 0));
            },
        );

        return $steps;
    }//end normalizeSteps()

    /**
     * Convert an arbitrary ObjectService return value to an associative array.
     *
     * @param mixed $value The returned object/array
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

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }

            return (array) $value;
        }

        return [];
    }//end toArray()

    /**
     * Resolve ObjectService and the (register, voorstel schema) pair.
     *
     * @return array{0: object, 1: string, 2: string}
     *
     * @throws RuntimeException When configuration is missing
     */
    private function bootstrapVoorstel(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->requireConfig(key: 'register');
        $schema   = $this->requireConfig(key: 'voorstel_schema');

        return [$objectService, $register, $schema];
    }//end bootstrapVoorstel()

    /**
     * Fetch a required configuration value, throwing when empty.
     *
     * @param string $key The config key
     *
     * @return string
     *
     * @throws RuntimeException When the value is empty
     */
    private function requireConfig(string $key): string
    {
        $value = $this->settingsService->getConfigValue($key);
        if ($value === '') {
            throw new RuntimeException(sprintf('Procest configuration key %s is not set', $key));
        }

        return $value;
    }//end requireConfig()

    /**
     * Resolve the current user UID. Falls back to "system" only when no
     * session user is available — write operations require a real session.
     *
     * @return string
     */
    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('Authenticated user required');
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Resolve the current user UID, falling back to "system" when no session
     * user is present (used by best-effort audit dispatch).
     *
     * @return string
     */
    private function safeUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return 'system';
        }

        return $user->getUID();
    }//end safeUserId()
}//end class
