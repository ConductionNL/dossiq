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

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Event\ParafeerTransitionEvent;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use Psr\Log\LoggerInterface;

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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ParafeerActieService
{
    /**
     * Action: actor advised on an advies step.
     */
    public const ACTION_ADVISED = 'advised';

    /**
     * Action: actor parafered a parafering step.
     */
    public const ACTION_PARAFERED = 'parafered';

    /**
     * Action: actor accorded an accordering step.
     */
    public const ACTION_ACCORDED = 'accorded';

    /**
     * Action: actor returned the voorstel to the steller.
     */
    public const ACTION_RETURNED = 'returned';

    /**
     * Action: step was skipped.
     */
    public const ACTION_SKIPPED = 'skipped';

    /**
     * Step type: advies.
     */
    public const STEP_TYPE_ADVIES = 'advies';

    /**
     * Step type: parafering.
     */
    public const STEP_TYPE_PARAFERING = 'parafering';

    /**
     * Step type: accordering.
     */
    public const STEP_TYPE_ACCORDERING = 'accordering';

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
     * @param SettingsService               $settingsService               The settings service (provides ObjectService access).
     * @param ParaferingNotificationService $paraferingNotificationService The Nextcloud notification service.
     * @param IRootFolder                   $rootFolder                    The Nextcloud root folder (for PDF signing).
     * @param LoggerInterface               $logger                        The logger.
     * @param IEventDispatcher              $eventDispatcher               The event dispatcher (parafering transition events).
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ParaferingNotificationService $paraferingNotificationService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher,
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
            [$register, $voorstelSchema, $actieSchema] = $this->resolveSchemas();
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                throw new \RuntimeException('OpenRegister is not available');
            }

            $voorstel = $this->findVoorstel(
                objectService: $objectService,
                register: $register,
                schema: $voorstelSchema,
                voorstelId: $voorstelId,
            );
            $step     = $this->resolveCurrentStep(voorstel: $voorstel);

            $action  = (string) ($data['action'] ?? '');
            $comment = '';
            if (isset($data['comment']) === true) {
                $comment = trim((string) $data['comment']);
            }

            $advice = '';
            if (isset($data['advice']) === true) {
                $advice = trim((string) $data['advice']);
            }

            $onBehalfOf = null;
            if (isset($data['onBehalfOf']) === true && $data['onBehalfOf'] !== '') {
                $onBehalfOf = (string) $data['onBehalfOf'];
            }

            $mandate = null;
            if (isset($data['mandate']) === true && $data['mandate'] !== '') {
                $mandate = (string) $data['mandate'];
            }

            $this->authorize(
                step: $step,
                currentUser: $currentUser,
                onBehalfOf: $onBehalfOf,
                mandate: $mandate,
            );
            $this->validateActionForStepType(step: $step, action: $action);
            $this->validateRequiredFields(
                action: $action,
                comment: $comment,
                advice: $advice,
                step: $step,
            );

            $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

            $actorType = 'user';
            if ($onBehalfOf !== null) {
                $actorType = 'delegate';
            }

            $actieData = [
                'voorstel'  => $voorstelId,
                'step'      => (int) ($step['order'] ?? ($voorstel['currentStep'] ?? 0)),
                'actor'     => $currentUser->getUID(),
                'actorType' => $actorType,
                'action'    => $action,
            ];

            if ($onBehalfOf !== null) {
                $actieData['onBehalfOf'] = $onBehalfOf;
            }

            if ($mandate !== null) {
                $actieData['mandate'] = $mandate;
            }

            if ($comment !== '') {
                $actieData['comment'] = $comment;
            }

            if ($advice !== '') {
                $actieData['advice'] = $advice;
            }

            // Persist the parafeeractie.
            $savedActie = $objectService->saveObject($register, $actieSchema, $actieData);

            // Emit the parafering transition event for the audit listener.
            [$transitionType, $actorRoleForAudit] = $this->transitionForAction(action: $action);
            $dispatchReason = null;
            if ($action === self::ACTION_RETURNED || $action === self::ACTION_SKIPPED) {
                $dispatchReason = $comment;
            }

            $this->dispatchTransition(
                voorstelId: $voorstelId,
                action: $transitionType,
                step: (string) ((int) ($step['order'] ?? ($voorstel['currentStep'] ?? 0))),
                actor: $currentUser->getUID(),
                actorRole: $actorRoleForAudit,
                reason: $dispatchReason,
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
                    'parafeeractie' => $this->toArray(value: $savedActie),
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

            // PDF signature on completed accordering step (only when document attached).
            $isAccorderingComplete = ($step['type'] ?? null) === self::STEP_TYPE_ACCORDERING
                && $action === self::ACTION_ACCORDED;
            if ($isAccorderingComplete === true && empty($voorstel['document']) === false) {
                $this->applyPdfSignature(
                    voorstelId: $voorstelId,
                    fileId: (string) $voorstel['document'],
                    actor: $currentUser,
                    step: (int) ($step['order'] ?? 0),
                    timestamp: $timestamp,
                );
            }

            // Notify steller on full accordering.
            if ($isAccorderingComplete === true && empty($voorstel['steller']) === false) {
                try {
                    $this->paraferingNotificationService->notifyVoorstelReturned(
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

            return [
                'parafeeractie' => $this->toArray(value: $savedActie),
                'voorstel'      => $this->toArray(value: $updatedVoorstel),
            ];
        } catch (OCSForbiddenException | OCSBadRequestException $e) {
            // Re-throw intentional exceptions for the controller to map to HTTP codes.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'ParafeerActieService::recordAction failed',
                ['voorstel' => $voorstelId, 'exception' => $e->getMessage()]
            );
            throw new \RuntimeException('Operation failed');
        }//end try
    }//end recordAction()

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
            [$register, , $actieSchema] = $this->resolveSchemas();
            $objectService = $this->settingsService->getObjectService();
            if ($objectService === null) {
                return [];
            }

            $results = $objectService->findObjects(
                $register,
                $actieSchema,
                ['voorstel' => $voorstelId],
                [],
                500,
            );

            $rows = [];
            if (is_array($results) === true) {
                foreach ($results as $row) {
                    $rows[] = $this->toArray(value: $row);
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
     * Resolve the OpenRegister register and schemas from settings.
     *
     * @return array{0: string, 1: string, 2: string} [register, voorstelSchema, parafeeractieSchema]
     *
     * @throws \RuntimeException When register/schemas are not configured.
     */
    private function resolveSchemas(): array
    {
        $register       = $this->settingsService->getConfigValue('register');
        $voorstelSchema = $this->settingsService->getConfigValue('voorstel_schema');
        $actieSchema    = $this->settingsService->getConfigValue('parafeeractie_schema');

        if (empty($register) === true || empty($voorstelSchema) === true || empty($actieSchema) === true) {
            throw new \RuntimeException('Procest register/schemas not configured');
        }

        return [(string) $register, (string) $voorstelSchema, (string) $actieSchema];
    }//end resolveSchemas()

    /**
     * Fetch a voorstel by UUID.
     *
     * @param object $objectService The OpenRegister ObjectService.
     * @param string $register      The register identifier.
     * @param string $schema        The voorstel schema identifier.
     * @param string $voorstelId    The voorstel UUID.
     *
     * @return array<string, mixed>
     *
     * @throws OCSBadRequestException When the voorstel cannot be located.
     */
    private function findVoorstel(object $objectService, string $register, string $schema, string $voorstelId): array
    {
        try {
            $voorstel = $objectService->findObject($register, $schema, $voorstelId);
        } catch (\Throwable $e) {
            throw new OCSBadRequestException('Voorstel not found');
        }

        $array = $this->toArray(value: $voorstel);
        if (empty($array) === true) {
            throw new OCSBadRequestException('Voorstel not found');
        }

        return $array;
    }//end findVoorstel()

    /**
     * Resolve the current step from the route snapshot.
     *
     * @param array<string, mixed> $voorstel The voorstel array.
     *
     * @return array<string, mixed> The current step (order, type, actor, label).
     *
     * @throws OCSBadRequestException When no current step is set or route snapshot missing.
     */
    private function resolveCurrentStep(array $voorstel): array
    {
        $currentStep = (int) ($voorstel['currentStep'] ?? 0);
        if ($currentStep < 1) {
            throw new OCSBadRequestException('Voorstel has no active step');
        }

        $snapshotRaw = $voorstel['routeSnapshot'] ?? null;
        if ($snapshotRaw === null) {
            throw new OCSBadRequestException('Voorstel has no route snapshot');
        }

        if (is_string($snapshotRaw) === true) {
            $decoded = json_decode($snapshotRaw, true);
        } else {
            $decoded = $snapshotRaw;
        }

        if (is_array($decoded) === false) {
            throw new OCSBadRequestException('Invalid route snapshot');
        }

        foreach ($decoded as $step) {
            if (is_array($step) === true && (int) ($step['order'] ?? 0) === $currentStep) {
                return $step;
            }
        }

        throw new OCSBadRequestException('Current step not found in route snapshot');
    }//end resolveCurrentStep()

    /**
     * Authorize the current user against the step actor (or valid delegate).
     *
     * @param array<string, mixed> $step        The current step.
     * @param IUser                $currentUser The authenticated user.
     * @param string|null          $onBehalfOf  The principal UID when acting as delegate.
     * @param string|null          $mandate     The mandate reference.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the current user is not the step actor and no valid delegate is configured.
     */
    private function authorize(array $step, IUser $currentUser, ?string $onBehalfOf, ?string $mandate): void
    {
        $stepActor = (string) ($step['actor'] ?? '');
        $userUid   = $currentUser->getUID();

        if ($stepActor === $userUid) {
            return;
        }

        if ($onBehalfOf !== null && $onBehalfOf === $stepActor && $mandate !== null && $mandate !== '') {
            // Mandate-based delegate authorization. The mandate registry check is the
            // responsibility of the frontend "Namens" selector (which only exposes
            // configured mandates) and the future MandaatService — see roadmap.
            return;
        }

        throw new OCSForbiddenException('Not authorized for this parafering step');
    }//end authorize()

    /**
     * Validate that the action is allowed for the given step type.
     *
     * @param array<string, mixed> $step   The current step (must include 'type').
     * @param string               $action The proposed action.
     *
     * @return void
     *
     * @throws OCSBadRequestException When the action is invalid for the step type.
     */
    private function validateActionForStepType(array $step, string $action): void
    {
        $stepType = (string) ($step['type'] ?? '');
        $allowed  = [
            self::STEP_TYPE_ADVIES      => [self::ACTION_ADVISED, self::ACTION_RETURNED],
            self::STEP_TYPE_PARAFERING  => [self::ACTION_PARAFERED, self::ACTION_RETURNED],
            self::STEP_TYPE_ACCORDERING => [self::ACTION_ACCORDED, self::ACTION_RETURNED],
        ];

        if (isset($allowed[$stepType]) === false || in_array($action, $allowed[$stepType], true) === false) {
            throw new OCSBadRequestException('Invalid action for this step type');
        }
    }//end validateActionForStepType()

    /**
     * Validate required fields per action.
     *
     * @param string               $action  The action.
     * @param string               $comment The comment (may be empty).
     * @param string               $advice  The advice (may be empty).
     * @param array<string, mixed> $step    The current step (reserved for future validation rules).
     *
     * @psalm-suppress UnusedParam
     *
     * @return void
     *
     * @throws OCSBadRequestException When mandatory comment/advice is missing.
     */
    private function validateRequiredFields(string $action, string $comment, string $advice, array $step): void
    {
        if ($action === self::ACTION_RETURNED && $comment === '') {
            throw new OCSBadRequestException('Return reason is required');
        }

        if ($action === self::ACTION_ADVISED && $advice === '') {
            throw new OCSBadRequestException('Advice text is required for advies steps');
        }
    }//end validateRequiredFields()

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

        $objectService->saveObject($register, $voorstelSchema, $updateData, $voorstelId);

        $steller = (string) ($voorstel['steller'] ?? '');
        if ($steller !== '') {
            try {
                $this->paraferingNotificationService->notifyVoorstelReturned(
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
        $snapshotRaw = $voorstel['routeSnapshot'] ?? null;
        if (is_string($snapshotRaw) === true) {
            $steps = json_decode($snapshotRaw, true);
        } else {
            $steps = $snapshotRaw;
        }

        if (is_array($steps) === false) {
            $steps = [];
        }

        $currentStep  = (int) ($voorstel['currentStep'] ?? 0);
        $nextStep     = null;
        $nextStepType = null;
        foreach ($steps as $step) {
            if (is_array($step) === false) {
                continue;
            }

            $order = (int) ($step['order'] ?? 0);
            if ($order > $currentStep && ($nextStep === null || $order < $nextStep)) {
                $nextStep     = $order;
                $nextStepType = (string) ($step['type'] ?? '');
            }
        }

        if ($nextStep === null) {
            $updateData = ['status' => self::STATUS_GEACCORDEERD];
        } else {
            $status = self::STATUS_IN_PARAFERING;
            if ($nextStepType === self::STEP_TYPE_ACCORDERING) {
                $status = self::STATUS_TER_ACCORDERING;
            }

            $updateData = [
                'currentStep' => $nextStep,
                'status'      => $status,
            ];
        }

        $updated = $objectService->saveObject($register, $voorstelSchema, $updateData, $voorstelId);

        return $this->toArray(value: $updated);
    }//end advanceVoorstel()

    /**
     * Normalize an OpenRegister return value to an array.
     *
     * ObjectService can return either an array (older API) or an object exposing
     * a jsonSerialize/toArray method (newer API). This helper collapses both.
     *
     * @param mixed $value The value to normalize.
     *
     * @return array<string, mixed>
     */
    private function toArray($value): array
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
                $converted = $value->toArray();
                if (is_array($converted) === true) {
                    return $converted;
                }
            }
        }

        return [];
    }//end toArray()
}//end class
