<?php

/**
 * Dossiq ParafeerActie Service
 *
 * Service for recording parafering actions (advies, paraferen, accorderen,
 * terugsturen) for a voorstel. Enforces per-step actor authorization,
 * advances the parafeerroute on successful action, and applies a PDF
 * signature annotation when an accordering step is completed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-actions/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Service\Parafeer\ParafeerStepGuard;
use OCA\Dossiq\Service\Parafeer\ParafeerVoorstelRepository;
use OCA\Dossiq\Service\Parafeer\ParaferingActionMapper;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCA\Dossiq\Service\Support\SearchesObjects;
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
class ParafeerActieService {

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
	 * @param IRootFolder $rootFolder The Nextcloud root folder (for PDF signing).
	 * @param LoggerInterface $logger The logger.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher (parafering transition events).
	 * @param ParaferingActionMapper $actionMapper Pure shaping of action input, payload and route steps.
	 * @param ParafeerStepGuard $stepGuard Current-step resolution + fail-closed authorisation.
	 * @param ParafeerVoorstelRepository $proposalRepository Register/schema resolution + voorstel loads.
	 * @param ObjectArrayNormalizer $normalizer Collapses OpenRegister's array-or-entity shape.
	 */
	public function __construct(
		private readonly ParaferingNotificationService $notificationService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ParaferingActionMapper $actionMapper,
		private readonly ParafeerStepGuard $stepGuard,
		private readonly ParafeerVoorstelRepository $proposalRepository,
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
	private function transitionForAction(string $action): array {
		return match ($action) {
			'parafered' => ['paraferd',    'parafeerder'],
			'advised' => ['advised',     'advisor'],
			'returned' => ['terugsturen', 'parafeerder'],
			'accorded' => ['completed',   'accorderend'],
			'skipped' => ['route-changed', 'beheerder'],
			default => ['paraferd', 'parafeerder'],
		};
	}//end transitionForAction()

	/**
	 * Best-effort dispatch of a ParafeerTransitionEvent.
	 *
	 * @param string $proposalId The voorstel UUID
	 * @param string $action Transition action
	 * @param string|null $step Step identifier
	 * @param string $actor The actor user UID
	 * @param string $actorRole The actor role
	 * @param string|null $reason Reason text when applicable
	 *
	 * @return void
	 */
	private function dispatchTransition(
		string $proposalId,
		string $action,
		?string $step,
		string $actor,
		string $actorRole,
		?string $reason = null,
	): void {
		try {
			$this->eventDispatcher->dispatchTyped(
				new ParafeerTransitionEvent(
					proposalId: $proposalId,
					action: $action,
					step: $step,
					actor: $actor,
					actorRole: $actorRole,
					reason: $reason,
				),
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq: ParafeerTransitionEvent dispatch failed',
				[
					'proposal' => $proposalId,
					'action' => $action,
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
	 * @param string $proposalId The voorstel UUID.
	 * @param array<string, mixed> $data Request payload (action, comment, advice, onBehalfOf, mandate).
	 * @param IUser $currentUser The authenticated user from IUserSession.
	 *
	 * @return array<string, mixed> Result envelope with parafeeractie and updated voorstel.
	 *
	 * @throws OCSForbiddenException When the current user is not authorized for this step.
	 * @throws OCSBadRequestException When request data is invalid (e.g. missing reason on returned).
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function recordAction(string $proposalId, array $data, IUser $currentUser): array {
		try {
			return $this->performRecordAction(
				proposalId: $proposalId,
				data: $data,
				currentUser: $currentUser,
			);
		} catch (OCSForbiddenException|OCSBadRequestException $e) {
			// Re-throw intentional exceptions for the controller to map to HTTP codes.
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ParafeerActieService::recordAction failed',
				['proposal' => $proposalId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Operation failed');
		}//end try
	}//end recordAction()

	/**
	 * Run the parafering action pipeline: validate, persist, propagate, advance.
	 *
	 * @param string $proposalId The voorstel UUID.
	 * @param array<string, mixed> $data Request payload (action, comment, advice, onBehalfOf, mandate).
	 * @param IUser $currentUser The authenticated user from IUserSession.
	 *
	 * @return array<string, mixed> Result envelope with parafeeractie and updated voorstel.
	 *
	 * @throws OCSForbiddenException When the current user is not authorized for this step.
	 * @throws OCSBadRequestException When request data is invalid (e.g. missing reason on returned).
	 */
	private function performRecordAction(string $proposalId, array $data, IUser $currentUser): array {
		[$register, $proposalSchema, $actionSchema] = $this->proposalRepository->resolveSchemas();
		$objectService = $this->proposalRepository->requireObjectService();

		$proposal = $this->proposalRepository->findVoorstel(
			objectService: $objectService,
			register: $register,
			schema: $proposalSchema,
			proposalId: $proposalId,
		);
		$step = $this->stepGuard->resolveCurrentStep(proposal: $proposal);

		$input = $this->actionMapper->parseActionInput(data: $data);
		$action = (string)$input['action'];
		$comment = (string)$input['comment'];

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
			advice: (string)$input['advice'],
		);

		$timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$stepOrder = (int)($step['order'] ?? ($proposal['currentStep'] ?? 0));

		$actionData = $this->actionMapper->buildActieData(
			proposalId: $proposalId,
			stepOrder: $stepOrder,
			actor: $currentUser->getUID(),
			input: $input,
		);

		// Persist the parafeeractie.
		$savedAction = $objectService->saveObject(object: $actionData, register: $register, schema: $actionSchema);

		$this->propagateDecision(
			proposalId: $proposalId,
			input: $input,
			stepOrder: $stepOrder,
			currentUser: $currentUser,
		);

		// Handle terugsturen: set voorstel status + notify steller, no route advance.
		if ($action === self::ACTION_RETURNED) {
			$this->handleReturn(
				objectService: $objectService,
				register: $register,
				proposalSchema: $proposalSchema,
				proposal: $proposal,
				proposalId: $proposalId,
				currentUser: $currentUser,
				reason: $comment,
			);

			return [
				'parafeeractie' => $this->normalizer->toArray(value: $savedAction),
				'proposal' => ['id' => $proposalId, 'status' => self::STATUS_TERUGGESTUURD],
			];
		}

		// Advance the route on success.
		$updatedProposal = $this->advanceProposal(
			objectService: $objectService,
			register: $register,
			proposalSchema: $proposalSchema,
			proposal: $proposal,
			proposalId: $proposalId,
		);

		$this->applyAccorderingEffects(
			step: $step,
			action: $action,
			proposal: $proposal,
			proposalId: $proposalId,
			currentUser: $currentUser,
			timestamp: $timestamp,
		);

		return [
			'parafeeractie' => $this->normalizer->toArray(value: $savedAction),
			'proposal' => $this->normalizer->toArray(value: $updatedProposal),
		];
	}//end performRecordAction()

	/**
	 * Propagate the step decision to OpenRegister and emit the audit transition.
	 *
	 * @param string $proposalId The voorstel UUID.
	 * @param array<string, mixed> $input The parsed action inputs.
	 * @param int $stepOrder The step order this action applies to.
	 * @param IUser $currentUser The authenticated user from IUserSession.
	 *
	 * @return void
	 */
	private function propagateDecision(
		string $proposalId,
		array $input,
		int $stepOrder,
		IUser $currentUser,
	): void {
		$action = (string)$input['action'];
		$comment = (string)$input['comment'];

		// ADR-022's delegation to OpenRegister's approval-workflow was REMOVED
		// here, and with it ParaferingApprovalBridge. It never ran: it required
		// `proposal.approvalChainUuid`, a property the `proposal` schema does not
		// declare and that was only ever written inside ParafeerRouteService::
		// startParafering() — which was itself unreachable, because its route
		// bound no argument and answered 400. So the bridge short-circuited on
		// every single call, the "legacy in-array path" it called a migration
		// window was the only path, and no approval event dossiq raised ever
		// reached OpenRegister.

		// Emit the parafering transition event for the audit listener.
		[$transitionType, $actorRoleForAudit] = $this->transitionForAction(action: $action);
		$dispatchReason = null;
		if ($action === self::ACTION_RETURNED || $action === self::ACTION_SKIPPED) {
			$dispatchReason = $comment;
		}

		$this->dispatchTransition(
			proposalId: $proposalId,
			action: $transitionType,
			step: (string)$stepOrder,
			actor: $currentUser->getUID(),
			actorRole: $actorRoleForAudit,
			reason: $dispatchReason,
		);
	}//end propagateDecision()

	/**
	 * Apply the side effects of a completed accordering step: PDF signature and
	 * steller notification. A no-op for any other step type or action.
	 *
	 * @param array<string, mixed> $step The current route step.
	 * @param string $action The recorded action.
	 * @param array<string, mixed> $proposal The voorstel array (current state).
	 * @param string $proposalId The voorstel UUID.
	 * @param IUser $currentUser The authenticated user from IUserSession.
	 * @param string $timestamp The ATOM timestamp of this action.
	 *
	 * @return void
	 */
	private function applyAccorderingEffects(
		array $step,
		string $action,
		array $proposal,
		string $proposalId,
		IUser $currentUser,
		string $timestamp,
	): void {
		$accorderingDone = ($step['type'] ?? null) === self::STEP_TYPE_ACCORDERING
			&& $action === self::ACTION_ACCORDED;
		if ($accorderingDone === false) {
			return;
		}

		// PDF signature on completed accordering step (only when document attached).
		if (empty($proposal['document']) === false) {
			$this->applyPdfSignature(
				proposalId: $proposalId,
				fileId: (string)$proposal['document'],
				actor: $currentUser,
				step: (int)($step['order'] ?? 0),
				timestamp: $timestamp,
			);
		}

		// Notify steller on full accordering.
		if (empty($proposal['author']) === false) {
			try {
				$this->notificationService->notifyVoorstelReturned(
					(string)$proposal['author'],
					(string)($proposal['subject'] ?? ''),
					$proposalId,
					$currentUser->getDisplayName(),
					'Voorstel volledig geaccordeerd'
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to send accordering notification to steller',
					['proposal' => $proposalId, 'exception' => $e->getMessage()]
				);
			}
		}
	}//end applyAccorderingEffects()

	/**
	 * List all parafeeracties for a voorstel, sorted by createdAt ascending.
	 *
	 * @param string $proposalId The voorstel UUID.
	 *
	 * @return array<int, array<string, mixed>> The parafeeractie objects.
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function listActions(string $proposalId): array {
		try {
			[$register, , $actionSchema] = $this->proposalRepository->resolveSchemas();
			$objectService = $this->proposalRepository->objectServiceOrNull();
			if ($objectService === null) {
				return [];
			}

			$results = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $actionSchema,
				filters: ['proposal' => $proposalId, '_limit' => 500],
			);

			$rows = [];
			// No is_array() guard: $results is already typed as an array.
			foreach ($results as $row) {
				$rows[] = $this->normalizer->toArray(value: $row);
			}

			// Sort by createdAt ascending; fall back to created/_self.created.
			usort(
				$rows,
				static function (array $a, array $b): int {
					$aKey = $a['createdAt'] ?? $a['created'] ?? ($a['@self']['created'] ?? '');
					$bKey = $b['createdAt'] ?? $b['created'] ?? ($b['@self']['created'] ?? '');
					return strcmp((string)$aKey, (string)$bKey);
				}
			);

			return $rows;
		} catch (\Throwable $e) {
			$this->logger->error(
				'ParafeerActieService::listActions failed',
				['proposal' => $proposalId, 'exception' => $e->getMessage()]
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
	 * @param string $proposalId The voorstel UUID (for logging context).
	 * @param string $fileId The Nextcloud file ID of the voorstel document.
	 * @param IUser $actor The authenticated actor performing the accordering.
	 * @param int $step The completed step number.
	 * @param string $timestamp ISO 8601 timestamp.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-actions/tasks.md#T02
	 */
	public function applyPdfSignature(
		string $proposalId,
		string $fileId,
		IUser $actor,
		int $step,
		string $timestamp,
	): void {
		try {
			$userFolder = $this->rootFolder->getUserFolder($actor->getUID());
			$nodes = $userFolder->getById((int)$fileId);
			if (count($nodes) === 0) {
				$this->logger->warning(
					'ParafeerActieService: voorstel document not found for PDF signing',
					['proposal' => $proposalId, 'file' => $fileId]
				);
				return;
			}

			$file = $nodes[0];
			if (($file instanceof File) === false) {
				$this->logger->warning(
					'ParafeerActieService: voorstel node is not a writable file',
					['proposal' => $proposalId, 'file' => $fileId]
				);
				return;
			}

			$template = "\n%%%% Dossiq parafering signature %%%%\n"
				. "Geaccordeerd via Dossiq parafeerroute\n"
				. "Acteur: %s (%s)\nStap: %d\nTijdstip: %s\n"
				. "%%%% End Dossiq signature %%%%\n";
			$annotationText = sprintf(
				$template,
				$actor->getUID(),
				$actor->getDisplayName(),
				$step,
				$timestamp
			);

			$current = $file->getContent();
			$file->putContent($current . $annotationText);

			$this->logger->info(
				'ParafeerActieService: PDF signature annotation applied',
				[
					'proposal' => $proposalId,
					'file' => $fileId,
					'actor' => $actor->getUID(),
				]
			);
		} catch (NotFoundException $e) {
			$this->logger->warning(
				'ParafeerActieService: PDF signing skipped — file not found',
				['proposal' => $proposalId, 'file' => $fileId]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ParafeerActieService: PDF signature could not be applied',
				['proposal' => $proposalId, 'file' => $fileId, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end applyPdfSignature()

	/**
	 * Handle a "returned" action: update voorstel status and notify steller.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register identifier.
	 * @param string $proposalSchema The voorstel schema identifier.
	 * @param array<string, mixed> $proposal The voorstel array (current state).
	 * @param string $proposalId The voorstel UUID.
	 * @param IUser $currentUser The user who returned the voorstel.
	 * @param string $reason The mandatory return reason.
	 *
	 * @return void
	 */
	private function handleReturn(
		object $objectService,
		string $register,
		string $proposalSchema,
		array $proposal,
		string $proposalId,
		IUser $currentUser,
		string $reason,
	): void {
		$currentStep = (int)($proposal['currentStep'] ?? 0);

		$updateData = [
			'status' => self::STATUS_TERUGGESTUURD,
			'returnedFromStep' => $currentStep,
		];

		$objectService->saveObject(object: $updateData, register: $register, schema: $proposalSchema, uuid: (string)$proposalId);

		$author = (string)($proposal['author'] ?? '');
		if ($author !== '') {
			try {
				$this->notificationService->notifyVoorstelReturned(
					$author,
					(string)($proposal['subject'] ?? ''),
					$proposalId,
					$currentUser->getDisplayName(),
					$reason
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Failed to send teruggestuurd notification',
					['proposal' => $proposalId, 'exception' => $e->getMessage()]
				);
			}
		}
	}//end handleReturn()

	/**
	 * Advance the voorstel to the next step in its route snapshot.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register The register identifier.
	 * @param string $proposalSchema The voorstel schema identifier.
	 * @param array<string, mixed> $proposal The current voorstel state.
	 * @param string $proposalId The voorstel UUID.
	 *
	 * @return array<string, mixed> The updated voorstel.
	 */
	private function advanceProposal(
		object $objectService,
		string $register,
		string $proposalSchema,
		array $proposal,
		string $proposalId,
	): array {
		$currentStep = (int)($proposal['currentStep'] ?? 0);
		$next = $this->actionMapper->findNextRouteStep(
			snapshotRaw: ($proposal['routeSnapshot'] ?? null),
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
				'status' => $status,
			];
		}

		$updated = $objectService->saveObject(object: $updateData, register: $register, schema: $proposalSchema, uuid: (string)$proposalId);

		return $this->normalizer->toArray(value: $updated);
	}//end advanceVoorstel()
}//end class
