<?php

/**
 * Procest Besluitvorming Parafeer Service
 *
 * Orchestrates the parafering (approval-chain) phase of a bestuurlijke
 * besluitvorming case. When a voorstel enters parafering the service snapshots
 * the configured parafeerroute, creates a task for the first parafeerder, and
 * advances the chain on each parafeeractie. When the final required paraaf is
 * collected the case auto-transitions to "Gereed voor agendering". A retour
 * action sends the voorstel back to the steller and records the step it was
 * returned from so the chain can resume at that step.
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
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Besluitvorming parafering-chain orchestrator.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — orchestrates ObjectService + notifications.
 *
 * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
 */
class BesluitvormingParafeerService
{
    /**
     * Voorstel status while the parafering chain is active.
     */
    public const STATUS_IN_PARAFERING = 'in_parafering';

    /**
     * Voorstel status when returned to the steller.
     */
    public const STATUS_RETOUR = 'retour';

    /**
     * Voorstel status when all required parafen are collected.
     */
    public const STATUS_GEREED = 'gereed_voor_agendering';

    /**
     * Paraaf action: approved.
     */
    public const ACTION_GOEDGEKEURD = 'goedgekeurd';

    /**
     * Paraaf action: returned to steller.
     */
    public const ACTION_RETOUR = 'retour';

    /**
     * Paraaf action: optional step skipped.
     */
    public const ACTION_OVERGESLAGEN = 'overgeslagen';

    /**
     * Constructor.
     *
     * @param SettingsService               $settingsService     Bridge to OpenRegister + config.
     * @param ParaferingNotificationService $notificationService Parafering notifications.
     * @param LoggerInterface               $logger              Logger.
     *
     * @return void
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ParaferingNotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Activate the parafering chain for a submitted voorstel.
     *
     * Snapshots the linked parafeerroute onto the voorstel, sets currentStep
     * to the first step (1, or returnedFromStep on resubmit), creates a task
     * for the first parafeerder, and notifies them.
     *
     * @param string $voorstelId The voorstel UUID/slug.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @throws RuntimeException When the voorstel/route cannot be loaded.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
     */
    public function activate(string $voorstelId): array
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrap();
        $routeSchema = $this->requireConfig(key: 'parafeerroute_schema');

        $voorstel = $this->toArray(value: $objectService->find($voorstelId, register: $register, schema: $voorstelSchema));

        // Resume from returnedFromStep when resubmitting after a retour.
        $resumeStep = (int) ($voorstel['returnedFromStep'] ?? 0);

        $steps = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? null);
        if (count($steps) === 0) {
            $routeRef = (string) ($voorstel['parafeerroute'] ?? '');
            if ($routeRef === '') {
                throw new RuntimeException('Voorstel heeft geen gekoppelde parafeerroute');
            }

            $route = $this->toArray(value: $objectService->find($routeRef, register: $register, schema: $routeSchema));
            $steps = $this->normalizeSteps(value: $route['steps'] ?? []);
            if (count($steps) === 0) {
                throw new RuntimeException('Gekoppelde parafeerroute heeft geen stappen');
            }

            $voorstel['routeSnapshot'] = json_encode($steps);
        }

        $firstStep = (int) ($steps[0]['order'] ?? 1);
        if ($resumeStep > 0) {
            $firstStep = $resumeStep;
        }

        $voorstel['currentStep']      = $firstStep;
        $voorstel['status']           = self::STATUS_IN_PARAFERING;
        $voorstel['returnedFromStep'] = null;

        $voorstel = $this->toArray(value: $objectService->saveObject($register, $voorstelSchema, $voorstel, $voorstelId));

        $this->openStep(objectService: $objectService, register: $register, voorstel: $voorstel, step: $firstStep, steps: $steps);

        return $voorstel;
    }//end activate()

    /**
     * Handle a recorded paraaf action and advance or return the chain.
     *
     * @param string $voorstelId      The voorstel UUID/slug.
     * @param string $parafeeractieId The parafeeractie UUID/slug just recorded.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @throws RuntimeException When the voorstel or action cannot be loaded.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
     */
    public function handleParaafAction(string $voorstelId, string $parafeeractieId): array
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrap();
        $actieSchema = $this->requireConfig(key: 'parafeeractie_schema');

        $voorstel = $this->toArray(value: $objectService->find($voorstelId, register: $register, schema: $voorstelSchema));
        $actie    = $this->toArray(value: $objectService->find($parafeeractieId, register: $register, schema: $actieSchema));

        $this->validateDelegation(actie: $actie);

        $action = (string) ($actie['action'] ?? '');
        $step   = (int) ($actie['step'] ?? ($voorstel['currentStep'] ?? 0));
        $steps  = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? null);

        if ($action === self::ACTION_RETOUR) {
            return $this->returnToSteller(
                objectService: $objectService,
                register: $register,
                voorstelSchema: $voorstelSchema,
                voorstel: $voorstel,
                step: $step,
                comment: (string) ($actie['comment'] ?? ''),
                actor: (string) ($actie['actor'] ?? ''),
            );
        }

        if ($action !== self::ACTION_GOEDGEKEURD && $action !== self::ACTION_OVERGESLAGEN) {
            $this->logger->info(
                'Procest: besluitvorming paraaf action does not advance chain',
                ['voorstel' => $voorstelId, 'action' => $action],
            );
            return $voorstel;
        }

        return $this->advance(
            objectService: $objectService,
            register: $register,
            voorstelSchema: $voorstelSchema,
            voorstel: $voorstel,
            steps: $steps,
            fromStep: $step,
        );
    }//end handleParaafAction()

    /**
     * Validate a delegated paraaf — a gemachtigde MUST carry onBehalfOf + mandate.
     *
     * @param array<string, mixed> $actie The parafeeractie payload.
     *
     * @return void
     *
     * @throws RuntimeException When delegation metadata is incomplete.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-002
     */
    private function validateDelegation(array $actie): void
    {
        if ((string) ($actie['actorType'] ?? '') !== 'gemachtigde') {
            return;
        }

        if ((string) ($actie['onBehalfOf'] ?? '') === '' || (string) ($actie['mandate'] ?? '') === '') {
            throw new RuntimeException('Een gemachtigde paraaf vereist onBehalfOf en mandate');
        }
    }//end validateDelegation()

    /**
     * Advance the chain to the next required step, or complete it.
     *
     * @param object               $objectService  The ObjectService.
     * @param string               $register       The register slug.
     * @param string               $voorstelSchema The voorstel schema id.
     * @param array<string, mixed> $voorstel       The voorstel payload.
     * @param array<int, mixed>    $steps          The decoded route steps.
     * @param int                  $fromStep       The step that just completed.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — orchestration arguments.
     */
    private function advance(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
        array $steps,
        int $fromStep,
    ): array {
        $next = $this->nextRequiredStep(steps: $steps, fromStep: $fromStep);

        if ($next === null) {
            return $this->complete(
                objectService: $objectService,
                register: $register,
                voorstelSchema: $voorstelSchema,
                voorstel: $voorstel,
            );
        }

        // Auto-skip optional steps between fromStep and the next required one.
        $this->recordSkippedOptionalSteps(
            objectService: $objectService,
            register: $register,
            voorstel: $voorstel,
            steps: $steps,
            fromStep: $fromStep,
            nextStep: $next,
        );

        $voorstel['currentStep'] = $next;
        $voorstel = $this->toArray(
            value: $objectService->saveObject($register, $voorstelSchema, $voorstel, (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? '')),
        );

        $this->openStep(objectService: $objectService, register: $register, voorstel: $voorstel, step: $next, steps: $steps);

        return $voorstel;
    }//end advance()

    /**
     * Mark the voorstel gereed and auto-transition the case to "Gereed voor agendering".
     *
     * @param object               $objectService  The ObjectService.
     * @param string               $register       The register slug.
     * @param string               $voorstelSchema The voorstel schema id.
     * @param array<string, mixed> $voorstel       The voorstel payload.
     *
     * @return array<string, mixed> The updated voorstel.
     */
    private function complete(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
    ): array {
        $voorstel['status']      = self::STATUS_GEREED;
        $voorstel['currentStep'] = 0;
        $voorstel = $this->toArray(
            value: $objectService->saveObject($register, $voorstelSchema, $voorstel, (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? '')),
        );

        $this->transitionCaseToGereed(
            objectService: $objectService,
            register: $register,
            caseId: (string) ($voorstel['case'] ?? ''),
        );

        $this->logger->info(
            'Procest: besluitvorming voorstel gereed voor agendering',
            ['voorstel' => ($voorstel['id'] ?? ''), 'app' => Application::APP_ID],
        );

        return $voorstel;
    }//end complete()

    /**
     * Return the voorstel to its steller, recording the step it was returned from.
     *
     * @param object               $objectService  The ObjectService.
     * @param string               $register       The register slug.
     * @param string               $voorstelSchema The voorstel schema id.
     * @param array<string, mixed> $voorstel       The voorstel payload.
     * @param int                  $step           The step the retour was issued at.
     * @param string               $comment        The mandatory retour comment.
     * @param string               $actor          The actor issuing the retour.
     *
     * @return array<string, mixed> The updated voorstel.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — orchestration arguments.
     */
    private function returnToSteller(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
        int $step,
        string $comment,
        string $actor,
    ): array {
        $voorstel['status']           = self::STATUS_RETOUR;
        $voorstel['returnedFromStep'] = $step;
        $voorstel = $this->toArray(
            value: $objectService->saveObject($register, $voorstelSchema, $voorstel, (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? '')),
        );

        $steller = (string) ($voorstel['steller'] ?? '');
        if ($steller !== '') {
            $this->notificationService->notifyVoorstelReturned(
                stellerUserId: $steller,
                onderwerp: (string) ($voorstel['onderwerp'] ?? ''),
                voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? ''),
                returnedBy: $actor,
                comment: $comment,
            );
        }

        return $voorstel;
    }//end returnToSteller()

    /**
     * Determine whether the chain is complete (all required steps collected).
     *
     * @param string $voorstelId The voorstel UUID/slug.
     *
     * @return bool True when no required step remains beyond currentStep.
     *
     * @throws RuntimeException When the voorstel cannot be loaded.
     *
     * @spec openspec/changes/besluitvorming-workflow/specs/besluitvorming-workflow/spec.md#req-bvw-003
     */
    public function checkAllParafenCollected(string $voorstelId): bool
    {
        [$objectService, $register, $voorstelSchema] = $this->bootstrap();
        $voorstel = $this->toArray(value: $objectService->find($voorstelId, register: $register, schema: $voorstelSchema));

        $steps   = $this->normalizeSteps(value: $voorstel['routeSnapshot'] ?? null);
        $current = (int) ($voorstel['currentStep'] ?? 0);

        return $this->nextRequiredStep(steps: $steps, fromStep: $current) === null;
    }//end checkAllParafenCollected()

    /**
     * Record overgeslagen parafeeractie entries for optional steps that are skipped.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      The register slug.
     * @param array<string, mixed> $voorstel      The voorstel payload.
     * @param array<int, mixed>    $steps         The decoded route steps.
     * @param int                  $fromStep      The completed step order.
     * @param int                  $nextStep      The next required step order.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — orchestration arguments.
     */
    private function recordSkippedOptionalSteps(
        object $objectService,
        string $register,
        array $voorstel,
        array $steps,
        int $fromStep,
        int $nextStep,
    ): void {
        $actieSchema = $this->settingsService->getConfigValue(key: 'parafeeractie_schema');
        if ($actieSchema === '') {
            return;
        }

        foreach ($steps as $candidate) {
            $order = (int) ($candidate['order'] ?? 0);
            if ($order <= $fromStep || $order >= $nextStep) {
                continue;
            }

            try {
                $objectService->saveObject(
                    $register,
                    $actieSchema,
                    [
                        'voorstel'  => ($voorstel['id'] ?? $voorstel['uuid'] ?? ''),
                        'step'      => $order,
                        'actor'     => 'system',
                        'actorType' => 'system',
                        'action'    => self::ACTION_OVERGESLAGEN,
                    ],
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Procest: could not record skipped optional paraaf step',
                    ['step' => $order, 'exception' => $e->getMessage()],
                );
            }
        }//end foreach
    }//end recordSkippedOptionalSteps()

    /**
     * Find the next required (mandatory, non-skipped) step after a given order.
     *
     * @param array<int, mixed> $steps    The decoded route steps.
     * @param int               $fromStep The reference step order.
     *
     * @return int|null The next required step order, or null when none remain.
     */
    private function nextRequiredStep(array $steps, int $fromStep): ?int
    {
        foreach ($steps as $candidate) {
            $order = (int) ($candidate['order'] ?? 0);
            if ($order <= $fromStep) {
                continue;
            }

            $mandatory = ($candidate['mandatory'] ?? $candidate['required'] ?? true);
            if (((bool) $mandatory) === true && ($candidate['skipped'] ?? false) !== true) {
                return $order;
            }
        }

        return null;
    }//end nextRequiredStep()

    /**
     * Open a step: create a task for the parafeerder and notify them.
     *
     * @param object               $objectService The ObjectService.
     * @param string               $register      The register slug.
     * @param array<string, mixed> $voorstel      The voorstel payload.
     * @param int                  $step          The step order to open.
     * @param array<int, mixed>    $steps         The decoded route steps.
     *
     * @return void
     */
    private function openStep(object $objectService, string $register, array $voorstel, int $step, array $steps): void
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

        $actor     = (string) ($stepInfo['actor'] ?? '');
        $onderwerp = (string) ($voorstel['onderwerp'] ?? '');

        $taskSchema = $this->settingsService->getConfigValue(key: 'task_schema');
        if ($taskSchema !== '' && $actor !== '') {
            try {
                $objectService->saveObject(
                    $register,
                    $taskSchema,
                    [
                        'title'    => sprintf('Paraaf vereist: %s', $onderwerp),
                        'status'   => 'open',
                        'assignee' => $actor,
                        'case'     => (string) ($voorstel['case'] ?? ''),
                    ],
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Procest: could not create paraaf task',
                    ['step' => $step, 'exception' => $e->getMessage()],
                );
            }
        }

        if ($actor !== '') {
            $this->notificationService->notifyStepActivated(
                actorUserId: $actor,
                onderwerp: $onderwerp,
                voorstelId: (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? ''),
                stepLabel: (string) ($stepInfo['type'] ?? 'parafering'),
            );
        }
    }//end openStep()

    /**
     * Transition the owning case to its "Gereed voor agendering" status.
     *
     * Resolves the statusType named "Gereed voor agendering" for the case's
     * caseType and sets the case status field. No-ops when the case or status
     * cannot be resolved (the voorstel update itself is authoritative for the
     * agenda queue).
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $caseId        The owning case UUID.
     *
     * @return void
     */
    private function transitionCaseToGereed(object $objectService, string $register, string $caseId): void
    {
        if ($caseId === '') {
            return;
        }

        $caseSchema       = $this->settingsService->getConfigValue(key: 'case_schema');
        $statusTypeSchema = $this->settingsService->getConfigValue(key: 'status_type_schema');
        if ($caseSchema === '' || $statusTypeSchema === '') {
            return;
        }

        try {
            $case       = $this->toArray(value: $objectService->find($caseId, register: $register, schema: $caseSchema));
            $caseTypeId = (string) ($case['caseType'] ?? '');
            if ($caseTypeId === '') {
                return;
            }

            $statusId = $this->resolveStatusTypeId(
                objectService: $objectService,
                register: $register,
                schema: $statusTypeSchema,
                caseTypeId: $caseTypeId,
                name: 'Gereed voor agendering',
            );
            if ($statusId === '') {
                return;
            }

            $objectService->saveObject($register, $caseSchema, ['status' => $statusId], $caseId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: could not auto-transition case to Gereed voor agendering',
                ['case' => $caseId, 'exception' => $e->getMessage()],
            );
        }//end try
    }//end transitionCaseToGereed()

    /**
     * Resolve a statusType id by caseType + name.
     *
     * @param object $objectService The ObjectService.
     * @param string $register      The register slug.
     * @param string $schema        The statusType schema id.
     * @param string $caseTypeId    The owning caseType id.
     * @param string $name          The statusType name.
     *
     * @return string The status id, or empty string.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — filter arguments.
     */
    private function resolveStatusTypeId(
        object $objectService,
        string $register,
        string $schema,
        string $caseTypeId,
        string $name,
    ): string {
        $results = $objectService->findAll(
            [
                'filters' => ['register' => $register, 'schema' => $schema, 'caseType' => $caseTypeId, 'name' => $name],
                'limit'   => 1,
            ],
        );

        if (is_array($results) === true && isset($results['results']) === true) {
            $results = $results['results'];
        }

        if (is_array($results) === true && count($results) > 0) {
            $first = $this->toArray(value: $results[0]);
            return (string) ($first['id'] ?? $first['uuid'] ?? '');
        }

        return '';
    }//end resolveStatusTypeId()

    /**
     * Resolve ObjectService and the (register, voorstel schema) tuple.
     *
     * @return array{0: object, 1: string, 2: string}
     *
     * @throws RuntimeException When OpenRegister or config is unavailable.
     */
    private function bootstrap(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        return [$objectService, $this->requireConfig(key: 'register'), $this->requireConfig(key: 'voorstel_schema')];
    }//end bootstrap()

    /**
     * Fetch a required config value, throwing when empty.
     *
     * @param string $key The config key.
     *
     * @return string The value.
     *
     * @throws RuntimeException When empty.
     */
    private function requireConfig(string $key): string
    {
        $value = $this->settingsService->getConfigValue(key: $key);
        if ($value === '') {
            throw new RuntimeException(sprintf('Procest configuratie %s ontbreekt', $key));
        }

        return $value;
    }//end requireConfig()

    /**
     * Normalize a steps value (JSON string or array) to a sorted list.
     *
     * @param mixed $value The raw routeSnapshot / steps value.
     *
     * @return array<int, array<string, mixed>> The ordered step list.
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
            static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)),
        );

        return $steps;
    }//end normalizeSteps()

    /**
     * Convert an ObjectService return value to an associative array.
     *
     * @param mixed $value The returned object/array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $serialized = $value->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($value) === true) {
            return (array) $value;
        }

        return [];
    }//end toArray()
}//end class
