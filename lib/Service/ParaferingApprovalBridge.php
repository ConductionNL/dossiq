<?php

/**
 * Procest Parafering Approval Bridge
 *
 * Translates procest parafeerroute concepts into OpenRegister's
 * `approval-workflow` abstraction (ApprovalChain / ApprovalStep) and routes
 * all step decisions (paraferen, terugsturen, adviseren, overslaan, delegeren)
 * through OpenRegister's ApprovalService.
 *
 * Per ADR-022 (apps consume OpenRegister abstractions), procest no longer owns
 * a bespoke approval-chain state machine: chain-state, role enforcement,
 * advance-on-approval and decision history are delegated to OpenRegister. This
 * bridge is the single seam between procest's voorstel/parafeerroute model and
 * OpenRegister's approval store. App-specific parafering semantics (actorType,
 * onBehalfOf mandate, advisory text, skip reason) are encoded in the step
 * `comment` field as a JSON object `{"text": "...", "_meta": {...}}` — the
 * metadata-in-comment pattern from the umbrella design.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/parafering-via-or-approval/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Bridge between procest parafering and OpenRegister approval-workflow.
 *
 * Every method degrades gracefully when OpenRegister's ApprovalService is
 * unavailable (fresh install / OR disabled): the bridge reports its
 * availability via isAvailable() so callers can fall back to the legacy
 * in-array routing path during the migration window.
 *
 * @spec openspec/specs/parafering-via-or-approval/spec.md
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ParaferingApprovalBridge
{
    /**
     * Fully-qualified name of OpenRegister's ApprovalChainMapper.
     */
    private const OR_CHAIN_MAPPER = 'OCA\OpenRegister\Db\ApprovalChainMapper';

    /**
     * Fully-qualified name of OpenRegister's ApprovalStepMapper.
     */
    private const OR_STEP_MAPPER = 'OCA\OpenRegister\Db\ApprovalStepMapper';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The procest settings bridge to OpenRegister.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether OpenRegister's approval-workflow backend is reachable.
     *
     * @return bool True when the ApprovalService and mappers can be resolved.
     *
     * @spec openspec/specs/parafering-via-or-approval/spec.md
     */
    public function isAvailable(): bool
    {
        return $this->settingsService->getApprovalService() !== null
            && $this->settingsService->getOpenRegisterClass(self::OR_CHAIN_MAPPER) !== null
            && $this->settingsService->getOpenRegisterClass(self::OR_STEP_MAPPER) !== null;
    }//end isAvailable()

    /**
     * Create an OpenRegister ApprovalChain for a voorstel from its route steps.
     *
     * Each parafeerroute step (advies/parafering/accordering) maps to one
     * ApprovalStep whose `role` is the Nextcloud group bound to the step. The
     * created chain is initialised against the voorstel UUID so OpenRegister
     * sets step 1 to `pending` and dispatches ApprovalStepInitiatedEvent.
     *
     * No procest-local `Parafeerroute` row is created here — the chain lives in
     * OpenRegister's approval store.
     *
     * @param string                           $voorstelUuid The voorstel UUID.
     * @param string                           $name         A human-readable chain name.
     * @param array<int, array<string, mixed>> $steps        The route steps (order/type/actor/...).
     *
     * @return string|null The created ApprovalChain UUID, or null when OpenRegister is unavailable.
     *
     * @throws RuntimeException When chain creation fails while OpenRegister is available.
     *
     * @spec openspec/specs/parafering-via-or-approval/spec.md
     */
    public function initializeChainForVoorstel(string $voorstelUuid, string $name, array $steps): ?string
    {
        $approvalService = $this->settingsService->getApprovalService();
        $chainMapper     = $this->settingsService->getOpenRegisterClass(self::OR_CHAIN_MAPPER);
        if ($approvalService === null || $chainMapper === null) {
            return null;
        }

        try {
            $chainSteps = $this->mapStepsToApprovalSteps(steps: $steps);
            if (count($chainSteps) === 0) {
                throw new RuntimeException('Cannot create an approval chain with zero steps');
            }

            // Create the ApprovalChain in OpenRegister via its mapper.
            $chain = $chainMapper->createFromArray(
                [
                    'name'    => $name,
                    'steps'   => $chainSteps,
                    'enabled' => true,
                ]
            );

            // Initialise the steps against the voorstel UUID (step 1 -> pending).
            $approvalService->initializeChain($chain, $voorstelUuid);

            $chainUuid = (string) ($chain->getUuid() ?? '');
            $this->logger->info(
                'Procest: parafering ApprovalChain created in OpenRegister',
                ['voorstel' => $voorstelUuid, 'chain' => $chainUuid, 'steps' => count($chainSteps)]
            );

            return $chainUuid;
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to create parafering ApprovalChain',
                ['voorstel' => $voorstelUuid, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Approval chain creation failed');
        }//end try
    }//end initializeChainForVoorstel()

    /**
     * Approve the currently pending OpenRegister step for a voorstel.
     *
     * Resolves the pending step for the voorstel UUID, then delegates to
     * OpenRegister's ApprovalService::approveStep — which enforces the step
     * role, sets the step `approved`, advances the next waiting step to
     * `pending`, and dispatches the approval events. App-specific metadata is
     * encoded in the comment as JSON.
     *
     * @param string               $voorstelUuid The voorstel UUID.
     * @param string               $userId       The acting user UID (from IUserSession).
     * @param string               $text         The human-readable comment/reden.
     * @param array<string, mixed> $meta         Machine-readable meta (action, actorType, onBehalfOf, mandate, advice).
     *
     * @return array<string, mixed>|null The OpenRegister approveStep result, or null when unavailable.
     *
     * @throws RuntimeException When no pending step exists or the approval fails.
     *
     * @spec openspec/specs/parafering-via-or-approval/spec.md
     */
    public function approveCurrentStep(string $voorstelUuid, string $userId, string $text, array $meta): ?array
    {
        $approvalService = $this->settingsService->getApprovalService();
        if ($approvalService === null) {
            return null;
        }

        $stepId = $this->findPendingStepId(voorstelUuid: $voorstelUuid);
        if ($stepId === null) {
            throw new RuntimeException('No pending approval step for voorstel');
        }

        try {
            return $approvalService->approveStep($stepId, $userId, $this->encodeComment(text: $text, meta: $meta));
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: ApprovalService::approveStep failed',
                ['voorstel' => $voorstelUuid, 'step' => $stepId, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Approval step transition failed');
        }
    }//end approveCurrentStep()

    /**
     * Reject (terugsturen) the currently pending OpenRegister step for a voorstel.
     *
     * @param string               $voorstelUuid The voorstel UUID.
     * @param string               $userId       The acting user UID (from IUserSession).
     * @param string               $text         The mandatory rejection reason.
     * @param array<string, mixed> $meta         Machine-readable meta.
     *
     * @return array<string, mixed>|null The OpenRegister rejectStep result, or null when unavailable.
     *
     * @throws RuntimeException When no pending step exists or the rejection fails.
     *
     * @spec openspec/specs/parafering-via-or-approval/spec.md
     */
    public function rejectCurrentStep(string $voorstelUuid, string $userId, string $text, array $meta): ?array
    {
        $approvalService = $this->settingsService->getApprovalService();
        if ($approvalService === null) {
            return null;
        }

        $stepId = $this->findPendingStepId(voorstelUuid: $voorstelUuid);
        if ($stepId === null) {
            throw new RuntimeException('No pending approval step for voorstel');
        }

        try {
            return $approvalService->rejectStep($stepId, $userId, $this->encodeComment(text: $text, meta: $meta));
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: ApprovalService::rejectStep failed',
                ['voorstel' => $voorstelUuid, 'step' => $stepId, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Rejection step transition failed');
        }
    }//end rejectCurrentStep()

    /**
     * Map procest route steps to OpenRegister ApprovalChain step definitions.
     *
     * The OpenRegister step `role` is the Nextcloud group ID bound to the
     * procest step actor. Role-typed actors use the actor as the role group;
     * user-typed actors fall back to the actor UID as the role token (the OR
     * group check then governs membership).
     *
     * @param array<int, array<string, mixed>> $steps The procest route steps.
     *
     * @return array<int, array<string, mixed>> The OpenRegister step definitions.
     */
    private function mapStepsToApprovalSteps(array $steps): array
    {
        $mapped = [];
        $order  = 1;
        foreach ($steps as $step) {
            if (($step['skipped'] ?? false) === true) {
                continue;
            }

            $mapped[] = [
                'order'           => (int) ($step['order'] ?? $order),
                'role'            => (string) ($step['role'] ?? ($step['actor'] ?? '')),
                'type'            => (string) ($step['type'] ?? 'parafering'),
                'statusOnApprove' => (string) ($step['statusOnApprove'] ?? 'approved'),
                'statusOnReject'  => (string) ($step['statusOnReject'] ?? 'rejected'),
            ];
            $order++;
        }//end foreach

        return $mapped;
    }//end mapStepsToApprovalSteps()

    /**
     * Find the pending OpenRegister ApprovalStep id for a voorstel UUID.
     *
     * @param string $voorstelUuid The voorstel UUID.
     *
     * @return int|null The pending step id, or null when none is pending.
     */
    private function findPendingStepId(string $voorstelUuid): ?int
    {
        $stepMapper = $this->settingsService->getOpenRegisterClass(self::OR_STEP_MAPPER);
        if ($stepMapper === null) {
            return null;
        }

        try {
            $steps = $stepMapper->findByObjectUuid($voorstelUuid);
            foreach ($steps as $step) {
                if ($step->getStatus() === 'pending') {
                    return (int) $step->getId();
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: could not resolve pending approval step',
                ['voorstel' => $voorstelUuid, 'exception' => $e->getMessage()]
            );
        }

        return null;
    }//end findPendingStepId()

    /**
     * Encode app-specific parafering metadata into the OpenRegister comment field.
     *
     * When no structured meta is present the plain human-readable text is
     * returned as-is (keeps simple paraferingen readable in the OR store);
     * otherwise a JSON object `{"text": "...", "_meta": {...}}` is emitted.
     *
     * @param string               $text The human-readable comment.
     * @param array<string, mixed> $meta The machine-readable meta (filtered of empty values).
     *
     * @return string The encoded comment.
     */
    private function encodeComment(string $text, array $meta): string
    {
        $filtered = [];
        foreach ($meta as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        if (count($filtered) === 0) {
            return $text;
        }

        $encoded = json_encode(['text' => $text, '_meta' => $filtered]);
        if ($encoded === false) {
            return $text;
        }

        return $encoded;
    }//end encodeComment()
}//end class
