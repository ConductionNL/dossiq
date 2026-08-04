<?php

/**
 * Procest ParafeerActie Service
 *
 * Service for recording parafering actions (advies, paraferen, accorderen,
 * terugsturen) for a voorstel. Enforces per-step actor authorization,
 * advances the parafeerroute on successful action, and applies a PDF
 * signature annotation when an accordering step is completed.
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
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Event\ParafeerTransitionEvent;
use OCA\Procest\Service\Parafeer\ParafeerStepGuard;
use OCA\Procest\Service\Parafeer\ParafeerVoorstelRepository;
use OCA\Procest\Service\Parafeer\ParaferingActionMapper;
use OCA\Procest\Service\Support\ObjectArrayNormalizer;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Records parafeeractie objects and orchestrates step advancement.
 *
 * Per ADR-005: identity is derived from IUserSession (passed in by the
 * controller); the request body is NEVER trusted for actor identity.
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — pre-existing; still 21 after the step-guard,
 *   voorstel-repository and array-normalisation seams were extracted.
 */
class ParafeerActieService
{

    use SearchesObjects;

    /**
     * Action: actor advised on an advies step.
     *
     * Canonically owned by {@see ParafeerStepGuard}, which enforces it.
     *
     * @var string
     */
    public const ACTION_ADVISED = ParafeerStepGuard::ACTION_ADVISED;

    /**
     * Action: actor parafered a parafering step.
     *
     * @var string
     */
    public const ACTION_PARAFERED = ParafeerStepGuard::ACTION_PARAFERED;

    /**
     * Action: actor accorded an accordering step.
     *
     * @var string
     */
    public const ACTION_ACCORDED = ParafeerStepGuard::ACTION_ACCORDED;

    /**
     * Action: actor returned the voorstel to the steller.
     *
     * @var string
     */
    public const ACTION_RETURNED = ParafeerStepGuard::ACTION_RETURNED;

    /**
     * Action: step was skipped.
     *
     * @var string
     */
    public const ACTION_SKIPPED = 'skipped';

    /**
     * Step type: advies.
     *
     * @var string
     */
    public const STEP_TYPE_ADVIES = ParafeerStepGuard::STEP_TYPE_ADVIES;

    /**
     * Step type: parafering.
     *
     * @var string
     */
    public const STEP_TYPE_PARAFERING = ParafeerStepGuard::STEP_TYPE_PARAFERING;

    /**
     * Step type: accordering.
     *
     * @var string
     */
    public const STEP_TYPE_ACCORDERING = ParafeerStepGuard::STEP_TYPE_ACCORDERING;

    /**
     * Voorstel status: in_parafering (active route).
     */
    public const STATUS_IN_PARAFERING = 'in_parafering';

    /**
     * Voorstel status: ter_accordering.
     */
    public const STATUS_TER_ACCORDERING = 'ter_accordering';

    /**
     * Voorstel status: geaccordeerd (all steps complete).
     */
    public const STATUS_GEACCORDEERD = 'geaccordeerd';

    /**
     * Voorstel status: teruggestuurd (returned to steller).
     */
    public const STATUS_TERUGGESTUURD = 'teruggestuurd';

    /**
     * Constructor.
     *
     * @param ParaferingNotificationService $notificationService The Nextcloud notification service.
     * @param IRootFolder                   $rootFolder          The Nextcloud root folder (for PDF signing).
     * @param LoggerInterface               $logger              The logger.
     * @param IEventDispatcher              $eventDispatcher     The event dispatcher (parafering transition events).
     * @param ParaferingApprovalBridge      $approvalBridge      Bridge to OpenRegister approval-workflow (ADR-022).
     * @param ParaferingActionMapper        $actionMapper        Pure shaping of action input, payload and route steps.
     * @param ParafeerStepGuard             $stepGuard           Current-step resolution + fail-closed authorisation.
     * @param ParafeerVoorstelRepository    $voorstelRepository  Register/schema resolution + voorstel loads.
     * @param ObjectArrayNormalizer         $normalizer          Collapses OpenRegister's array-or-entity shape.
     */
    public function __construct(
        private readonly ParaferingNotificationService $notificationService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly ParaferingApprovalBridge $approvalBridge,
        private readonly ParaferingActionMapper $actionMapper,
        private readonly ParafeerStepGuard $stepGuard,
        private readonly ParafeerVoorstelRepository $voorstelRepository,
        private readonly ObjectArrayNormalizer $normalizer,
    ) {
    }//end __construct()

    /**
     * Map a parafeeractie action to a parafering audit transition.
     *
     * @param string $action Operational action (parafered, advised, returned, skipped, accorded)
     *
     * @return array{0:string,1:string} Tuple of [transitionType, actorRole]
     */
    private function transitionForAction(string $action): array
    {
        return match ($action) {
            'parafered' => ['paraferd',    'parafeerder'],
            'advised'   => ['advised',     'adviseur'],
            'returned'  => ['terugsturen', 'parafeerder'],
            'accorded'  => ['completed',   'accorderend'],
            'skipped'   => ['route-changed', 'beheerder'],
            default     => ['paraferd', 'parafeerder'],
        };
    }//end transitionForAction()

    /**
     * Best-effort dispatch of a ParafeerTransitionEvent.
     *
     * @param string      $voorstelId The voorstel UUID
     * @param string      $action     Transition action
     * @param string|null $step       Step identifier
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
        } catch (\Throwable $e) {
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
     * Record a parafering action for the current step of a voorstel.
     *
     * Authorization model (ADR-005):
     *   - currentUser->getUID() MUST equal the step actor, OR
     *   - $data['onBehalfOf'] MUST equal the step actor AND a mandate reference is provided.
     *
     * @param string               $voorstelId  The voorstel UUID.
     * @param array<string, mixed> $data        Request payload (action, comment, advice, onBehalfOf, mandate).
     * @param IUser                $currentUser The authenticated user from IUserSession.
     *
     * @return array<string, mixed> Result envelope with parafeeractie and updated voorstel.
     *
     * @throws OCSForbiddenException  When the current user is not authorized for this step.
     * @throws OCSBadRequestException When request data is invalid (e.g. missing reason on returned).
     *
     * @spec openspec/changes/parafering-actions/tasks.md#T02
     */
    public function recordAction(string $voorstelId, array $data, IUser $currentUser): array
    {
        try {
            return $this->performRecordAction(
                voorstelId: $voorstelId,
                data: $data,
                currentUser: $currentUser,
            );
        } catch (OCSForbiddenException | OCSBadRequestException $e) {
            // Re-throw intentional exceptions for the controller to map to HTTP codes.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'ParafeerActieService::recordAction failed',
                ['voorstel' => $voorstelId, 'exception' => $e->getMessage()]
            );
            throw new RuntimeException('Operation failed');
        }//end try
    }//end recordAction()

    /**
     * Run the parafering action pipeline: validate, persist, propagate, advance.
     *
     * @param string               $voorstelId  The voorstel UUID.
     * @param array<string, mixed> $data        Request payload (action, comment, advice, onBehalfOf, mandate).
     * @param IUser                $currentUser The authenticated user from IUserSession.
     *
     * @return array<string, mixed> Result envelope with parafeeractie and updated voorstel.
     *
     * @throws OCSForbiddenException  When the current user is not authorized for this step.
     * @throws OCSBadRequestException When request data is invalid (e.g. missing reason on returned).
     */
    private function performRecordAction(string $voorstelId, array $data, IUser $currentUser): array
    {
        [$register, $voorstelSchema, $actieSchema] = $this->voorstelRepository->resolveSchemas();
        $objectService = $this->voorstelRepository->requireObjectService();

        $voorstel = $this->voorstelRepository->findVoorstel(
            objectService: $objectService,
            register: $register,
            schema: $voorstelSchema,
            voorstelId: $voorstelId,
        );
        $step     = $this->stepGuard->resolveCurrentStep(voorstel: $voorstel);

        $input   = $this->actionMapper->parseActionInput(data: $data);
        $action  = (string) $input['action'];
        $comment = (string) $input['comment'];

        $this->stepGuard->authorize(
            step: $step,
            currentUser: $currentUser,
            onBehalfOf: $input['onBehalfOf'],
            mandate: $input['mandate'],
        );
        $this->stepGuard->validateActionForStepType(step: $step, action: $action);
        $this->stepGuard->validateRequiredFields(
            action: $action,
            comment: $comment,
            advice: (string) $input['advice'],
        );

        $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $stepOrder = (int) ($step['order'] ?? ($voorstel['currentStep'] ?? 0));

        $actieData = $this->actionMapper->buildActieData(
            voorstelId: $voorstelId,
            stepOrder: $stepOrder,
            actor: $currentUser->getUID(),
            input: $input,
        );

        // Persist the parafeeractie.
        $savedActie = $objectService->saveObject(object: $actieData, register: $register, schema: $actieSchema);

        $this->propagateDecision(
            voorstel: $voorstel,
            voorstelId: $voorstelId,
            input: $input,
            stepOrder: $stepOrder,
            currentUser: $currentUser,
        );

        // Handle terugsturen: set voorstel status + notify steller, no route advance.
        if ($action === self::ACTION_RETURNED) {
            $this->handleReturn(
                objectService: $objectService,
                register: $register,
                voorstelSchema: $voorstelSchema,
                voorstel: $voorstel,
                voorstelId: $voorstelId,
                currentUser: $currentUser,
                reason: $comment,
            );

            return [
                'parafeeractie' => $this->normalizer->toArray(value: $savedActie),
                'voorstel'      => ['id' => $voorstelId, 'status' => self::STATUS_TERUGGESTUURD],
            ];
        }

        // Advance the route on success.
        $updatedVoorstel = $this->advanceVoorstel(
            objectService: $objectService,
            register: $register,
            voorstelSchema: $voorstelSchema,
            voorstel: $voorstel,
            voorstelId: $voorstelId,
        );

        $this->applyAccorderingEffects(
            step: $step,
            action: $action,
            voorstel: $voorstel,
            voorstelId: $voorstelId,
            currentUser: $currentUser,
            timestamp: $timestamp,
        );

        return [
            'parafeeractie' => $this->normalizer->toArray(value: $savedActie),
            'voorstel'      => $this->normalizer->toArray(value: $updatedVoorstel),
        ];
    }//end performRecordAction()

    /**
     * Propagate the step decision to OpenRegister and emit the audit transition.
     *
     * @param array<string, mixed> $voorstel    The voorstel array (provides approvalChainUuid).
     * @param string               $voorstelId  The voorstel UUID.
     * @param array<string, mixed> $input       The parsed action inputs.
     * @param int                  $stepOrder   The step order this action applies to.
     * @param IUser                $currentUser The authenticated user from IUserSession.
     *
     * @return void
     */
    private function propagateDecision(
        array $voorstel,
        string $voorstelId,
        array $input,
        int $stepOrder,
        IUser $currentUser,
    ): void {
        $action  = (string) $input['action'];
        $comment = (string) $input['comment'];

        // Per ADR-022: delegate the step transition to OpenRegister's
        // approval-workflow when this voorstel is backed by an OR
        // ApprovalChain. OpenRegister enforces the step role, advances the
        // next step, records the decision, and dispatches the approval
        // events that ParaferingNotificationService observes. The legacy
        // in-array currentStep/status update below remains the
        // consumer-facing projection during the migration window.
        $this->delegateToApprovalWorkflow(
            voorstel: $voorstel,
            voorstelId: $voorstelId,
            action: $action,
            comment: $comment,
            advice: (string) $input['advice'],
            onBehalfOf: $input['onBehalfOf'],
            mandate: $input['mandate'],
            currentUser: $currentUser,
        );

        // Emit the parafering transition event for the audit listener.
        [$transitionType, $actorRoleForAudit] = $this->transitionForAction(action: $action);
        $dispatchReason = null;
        if ($action === self::ACTION_RETURNED || $action === self::ACTION_SKIPPED) {
            $dispatchReason = $comment;
        }

        $this->dispatchTransition(
            voorstelId: $voorstelId,
            action: $transitionType,
            step: (string) $stepOrder,
            actor: $currentUser->getUID(),
            actorRole: $actorRoleForAudit,
            reason: $dispatchReason,
        );
    }//end propagateDecision()

    /**
     * Apply the side effects of a completed accordering step: PDF signature and
     * steller notification. A no-op for any other step type or action.
     *
     * @param array<string, mixed> $step        The current route step.
     * @param string               $action      The recorded action.
     * @param array<string, mixed> $voorstel    The voorstel array (current state).
     * @param string               $voorstelId  The voorstel UUID.
     * @param IUser                $currentUser The authenticated user from IUserSession.
     * @param string               $timestamp   The ATOM timestamp of this action.
     *
     * @return void
     */
    private function applyAccorderingEffects(
        array $step,
        string $action,
        array $voorstel,
        string $voorstelId,
        IUser $currentUser,
        string $timestamp,
    ): void {
        $accorderingDone = ($step['type'] ?? null) === self::STEP_TYPE_ACCORDERING
            && $action === self::ACTION_ACCORDED;
        if ($accorderingDone === false) {
            return;
        }

        // PDF signature on completed accordering step (only when document attached).
        if (empty($voorstel['document']) === false) {
            $this->applyPdfSignature(
                voorstelId: $voorstelId,
                fileId: (string) $voorstel['document'],
                actor: $currentUser,
                step: (int) ($step['order'] ?? 0),
                timestamp: $timestamp,
            );
        }

        // Notify steller on full accordering.
        if (empty($voorstel['steller']) === false) {
            try {
                $this->notificationService->notifyVoorstelReturned(
                    (string) $voorstel['steller'],
                    (string) ($voorstel['onderwerp'] ?? ''),
                    $voorstelId,
                    $currentUser->getDisplayName(),
                    'Voorstel volledig geaccordeerd'
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to send accordering notification to steller',
                    ['voorstel' => $voorstelId, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end applyAccorderingEffects()

    /**
     * Delegate a parafering step decision to OpenRegister's approval-workflow.
     *
     * Maps the procest action onto OR's approve/reject endpoints and encodes
     * app-specific semantics (actorType, onBehalfOf mandate, advisory text,
     * skip reason) into the OR step comment as JSON `_meta`. Only runs when the
     * voorstel carries an `approvalChainUuid` and OR's approval-workflow is
     * available; otherwise it is a no-op and the legacy in-array path governs.
     *
     * Best-effort: a failed OR transition is logged and does NOT abort the
     * consumer-facing action during the migration window.
     *
     * @param array<string, mixed> $voorstel    The voorstel array (provides approvalChainUuid).
     * @param string               $voorstelId  The voorstel UUID.
     * @param string               $action      The procest action (parafered/advised/accorded/returned/skipped).
     * @param string               $comment     The human-readable comment/reden.
     * @param string               $advice      The advisory text (advies steps).
     * @param string|null          $onBehalfOf  The principal UID when acting as delegate.
     * @param string|null          $mandate     The mandate reference.
     * @param IUser                $currentUser The authenticated actor.
     *
     * @return void
     *
     * @spec openspec/changes/migrate-parafering-to-or-approval-workflow/tasks.md#P1.2
     */
    private function delegateToApprovalWorkflow(
        array $voorstel,
        string $voorstelId,
        string $action,
        string $comment,
        string $advice,
        ?string $onBehalfOf,
        ?string $mandate,
        IUser $currentUser
    ): void {
        $chainUuid = (string) ($voorstel['approvalChainUuid'] ?? '');
        if ($chainUuid === '' || $this->approvalBridge->isAvailable() === false) {
            return;
        }

        // The OR object UUID for the step lookup is the voorstel UUID.
        $objectUuid = (string) ($voorstel['id'] ?? $voorstel['uuid'] ?? $voorstelId);
        $userId     = $currentUser->getUID();

        $actorType = 'user';
        if ($onBehalfOf !== null) {
            $actorType = 'delegate';
        }

        $meta = [
            'action'     => $action,
            'actorType'  => $actorType,
            'onBehalfOf' => $onBehalfOf,
            'mandate'    => $mandate,
        ];

        $text = $comment;
        if ($action === self::ACTION_ADVISED) {
            $meta['advice'] = $advice;
            if ($text === '') {
                $text = $advice;
            }
        }

        try {
            if ($action === self::ACTION_RETURNED) {
                $this->approvalBridge->rejectCurrentStep(
                    voorstelUuid: $objectUuid,
                    userId: $userId,
                    text: $text,
                    meta: $meta,
                );
                return;
            }

            $this->approvalBridge->approveCurrentStep(
                voorstelUuid: $objectUuid,
                userId: $userId,
                text: $text,
                meta: $meta,
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Procest: approval-workflow delegation failed; legacy path governs',
                ['voorstel' => $voorstelId, 'action' => $action, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end delegateToApprovalWorkflow()

    /**
     * List all parafeeracties for a voorstel, sorted by createdAt ascending.
     *
     * @param string $voorstelId The voorstel UUID.
     *
     * @return array<int, array<string, mixed>> The parafeeractie objects.
     *
     * @spec openspec/changes/parafering-actions/tasks.md#T02
     */
    public function listActions(string $voorstelId): array
    {
        try {
            [$register, , $actieSchema] = $this->voorstelRepository->resolveSchemas();
            $objectService = $this->voorstelRepository->objectServiceOrNull();
            if ($objectService === null) {
                return [];
            }

            $results = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $actieSchema,
                filters: ['voorstel' => $voorstelId, '_limit' => 500],
            );

            $rows = [];
            if (is_array($results) === true) {
                foreach ($results as $row) {
                    $rows[] = $this->normalizer->toArray(value: $row);
                }
            }

            // Sort by createdAt ascending; fall back to created/_self.created.
            usort(
                    $rows,
                    static function (array $a, array $b): int {
                        $aKey = $a['createdAt'] ?? $a['created'] ?? ($a['@self']['created'] ?? '');
                        $bKey = $b['createdAt'] ?? $b['created'] ?? ($b['@self']['created'] ?? '');
                        return strcmp((string) $aKey, (string) $bKey);
                    }
                    );

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->error(
                'ParafeerActieService::listActions failed',
                ['voorstel' => $voorstelId, 'exception' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end listActions()

    /**
     * Apply a PDF signature annotation to a voorstel document.
     *
     * Non-blocking: failures are logged at warning level and swallowed
     * per REQ-PAA-005-002 (absence of a writable document is a valid state).
     *
     * @param string $voorstelId The voorstel UUID (for logging context).
     * @param string $fileId     The Nextcloud file ID of the voorstel document.
     * @param IUser  $actor      The authenticated actor performing the accordering.
     * @param int    $step       The completed step number.
     * @param string $timestamp  ISO 8601 timestamp.
     *
     * @return void
     *
     * @spec openspec/changes/parafering-actions/tasks.md#T02
     */
    public function applyPdfSignature(
        string $voorstelId,
        string $fileId,
        IUser $actor,
        int $step,
        string $timestamp
    ): void {
        try {
            $userFolder = $this->rootFolder->getUserFolder($actor->getUID());
            $nodes      = $userFolder->getById((int) $fileId);
            if (count($nodes) === 0) {
                $this->logger->warning(
                    'ParafeerActieService: voorstel document not found for PDF signing',
                    ['voorstel' => $voorstelId, 'file' => $fileId]
                );
                return;
            }

            $file = $nodes[0];
            if (($file instanceof File) === false) {
                $this->logger->warning(
                    'ParafeerActieService: voorstel node is not a writable file',
                    ['voorstel' => $voorstelId, 'file' => $fileId]
                );
                return;
            }

            $template       = "\n%%%% Procest parafering signature %%%%\n"
                ."Geaccordeerd via Procest parafeerroute\n"
                ."Acteur: %s (%s)\nStap: %d\nTijdstip: %s\n"
                ."%%%% End Procest signature %%%%\n";
            $annotationText = sprintf(
                $template,
                $actor->getUID(),
                $actor->getDisplayName(),
                $step,
                $timestamp
            );

            $current = $file->getContent();
            $file->putContent($current.$annotationText);

            $this->logger->info(
                'ParafeerActieService: PDF signature annotation applied',
                [
                    'voorstel' => $voorstelId,
                    'file'     => $fileId,
                    'actor'    => $actor->getUID(),
                ]
            );
        } catch (NotFoundException $e) {
            $this->logger->warning(
                'ParafeerActieService: PDF signing skipped — file not found',
                ['voorstel' => $voorstelId, 'file' => $fileId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ParafeerActieService: PDF signature could not be applied',
                ['voorstel' => $voorstelId, 'file' => $fileId, 'exception' => $e->getMessage()]
            );
        }//end try
    }//end applyPdfSignature()

    /**
     * Handle a "returned" action: update voorstel status and notify steller.
     *
     * @param object               $objectService  The OpenRegister ObjectService.
     * @param string               $register       The register identifier.
     * @param string               $voorstelSchema The voorstel schema identifier.
     * @param array<string, mixed> $voorstel       The voorstel array (current state).
     * @param string               $voorstelId     The voorstel UUID.
     * @param IUser                $currentUser    The user who returned the voorstel.
     * @param string               $reason         The mandatory return reason.
     *
     * @return void
     */
    private function handleReturn(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
        string $voorstelId,
        IUser $currentUser,
        string $reason
    ): void {
        $currentStep = (int) ($voorstel['currentStep'] ?? 0);

        $updateData = [
            'status'           => self::STATUS_TERUGGESTUURD,
            'returnedFromStep' => $currentStep,
        ];

        $objectService->saveObject(object: $updateData, register: $register, schema: $voorstelSchema, uuid: (string) $voorstelId);

        $steller = (string) ($voorstel['steller'] ?? '');
        if ($steller !== '') {
            try {
                $this->notificationService->notifyVoorstelReturned(
                    $steller,
                    (string) ($voorstel['onderwerp'] ?? ''),
                    $voorstelId,
                    $currentUser->getDisplayName(),
                    $reason
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Failed to send teruggestuurd notification',
                    ['voorstel' => $voorstelId, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end handleReturn()

    /**
     * Advance the voorstel to the next step in its route snapshot.
     *
     * @param object               $objectService  The OpenRegister ObjectService.
     * @param string               $register       The register identifier.
     * @param string               $voorstelSchema The voorstel schema identifier.
     * @param array<string, mixed> $voorstel       The current voorstel state.
     * @param string               $voorstelId     The voorstel UUID.
     *
     * @return array<string, mixed> The updated voorstel.
     */
    private function advanceVoorstel(
        object $objectService,
        string $register,
        string $voorstelSchema,
        array $voorstel,
        string $voorstelId
    ): array {
        $currentStep = (int) ($voorstel['currentStep'] ?? 0);
        $next        = $this->actionMapper->findNextRouteStep(
            snapshotRaw: ($voorstel['routeSnapshot'] ?? null),
            currentStep: $currentStep,
        );

        $updateData = ['status' => self::STATUS_GEACCORDEERD];
        if ($next !== null) {
            $status = self::STATUS_IN_PARAFERING;
            if ($next['type'] === self::STEP_TYPE_ACCORDERING) {
                $status = self::STATUS_TER_ACCORDERING;
            }

            $updateData = [
                'currentStep' => $next['order'],
                'status'      => $status,
            ];
        }

        $updated = $objectService->saveObject(object: $updateData, register: $register, schema: $voorstelSchema, uuid: (string) $voorstelId);

        return $this->normalizer->toArray(value: $updated);
    }//end advanceVoorstel()
}//end class
