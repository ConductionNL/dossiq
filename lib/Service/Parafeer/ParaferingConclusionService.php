<?php

/**
 * Dossiq parafering conclusion recorder.
 *
 * The one sanctioned writer of parafering case data now that the runtime lives
 * in the decision app. When a chain concludes there, this service projects the
 * outcome onto the case file: one `parafeeractie` per sign-off in the event's
 * action record (who signed, on whose behalf, under which mandate, with which
 * comment or advice), the voorstel's final status, the steller's notification,
 * the accordering signature on the document, and the append-only audit trail.
 *
 * It RECORDS and never DECIDES — the parafering twin of
 * {@see \OCA\Dossiq\Service\BesluitMaterialisationService}, and the class the
 * structural test names as the sanctioned door. Nothing here resolves a
 * current step, advances a snapshot, or judges an actor: those questions were
 * asked and answered in the decision app before the event ever fired.
 *
 * THE AUDIT PREFIX SURVIVES THE MOVE. Every recorded sign-off still raises a
 * {@see \OCA\Dossiq\Event\ParafeerTransitionEvent}, so ParaferingAuditListener
 * keeps writing the frozen `procest.parafering.*` trail — the legal approval
 * history must not split just because the engine moved apps.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Service\ParaferingNotificationService;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a concluded chain onto the voorstel's case record.
 *
 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The recorder touches every
 *   case-data surface a conclusion feeds — records, status, notification,
 *   signature, audit — which is the projection's whole job.
 */
class ParaferingConclusionService {

	use SearchesObjects;

	/**
	 * The decision app's action vocabulary, mapped back onto parafeeractie's.
	 *
	 * The inverse of ParaferingDelegationService::STAGE_TYPES' direction: what
	 * travelled out as advisory/endorsement/decisive comes home as
	 * advised/parafered/accorded. An unknown verb maps to `parafered` — the
	 * weakest claim the enum can carry — rather than being dropped, because a
	 * dropped sign-off is a hole in an administrative-law record.
	 *
	 * @var array<string, string>
	 */
	private const ACTION_MAP = [
		'advised' => 'advised',
		'endorsed' => 'parafered',
		'approved' => 'accorded',
		'returned' => 'returned',
		'skipped' => 'skipped',
	];

	/**
	 * Audit transition per recorded action: [transitionType, actorRole].
	 *
	 * Absorbed verbatim from ParafeerActieService::transitionForAction(), so
	 * the frozen `procest.parafering.*` trail keeps its vocabulary.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const AUDIT_TRANSITIONS = [
		'parafered' => ['paraferd', 'parafeerder'],
		'advised' => ['advised', 'advisor'],
		'returned' => ['terugsturen', 'parafeerder'],
		'accorded' => ['completed', 'accorderend'],
		'skipped' => ['route-changed', 'beheerder'],
	];

	/**
	 * Terminal voorstel statuses; a voorstel already carrying one is done.
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL_STATUSES = ['geaccordeerd', 'teruggestuurd'];

	/**
	 * The voorstel schema's slug in dossiq's register.
	 *
	 * The saves address the schema by its configured identifier, which is a
	 * numeric id on a live install; the slug is what
	 * {@see JsonEncodedStringProperties} keys its map by.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'proposal';

	/**
	 * Constructor.
	 *
	 * @param ParafeerVoorstelRepository    $repository          Schema resolution + voorstel loads.
	 * @param ParaferingNotificationService $notificationService The steller's notifications.
	 * @param IRootFolder                   $rootFolder          For the accordering signature annotation.
	 * @param IEventDispatcher              $eventDispatcher     Raises the audit transition events.
	 * @param ObjectArrayNormalizer         $normalizer          Collapses OpenRegister's array-or-entity shape.
	 * @param JsonEncodedStringProperties   $jsonProperties      Restores the declared string shape of JSON-encoded properties.
	 * @param LoggerInterface               $logger              Logger.
	 */
	public function __construct(
		private readonly ParafeerVoorstelRepository $repository,
		private readonly ParaferingNotificationService $notificationService,
		private readonly IRootFolder $rootFolder,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ObjectArrayNormalizer $normalizer,
		private readonly JsonEncodedStringProperties $jsonProperties,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a concluded chain on its voorstel.
	 *
	 * Idempotent: a voorstel already in a terminal status is left exactly as
	 * it is, and a sign-off already recorded is not recorded twice, so a
	 * replayed or duplicated event changes nothing.
	 *
	 * @param string $proposalId The voorstel uuid (the event's subject).
	 * @param string $outcome The decision app's final outcome.
	 * @param string $actor Who decided the final stage.
	 * @param array<int, array<string, mixed>> $actions The chronological sign-off record.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runtime-to-decidiq/specs/parafering-runtime-to-decidiq/spec.md
	 */
	public function recordConclusion(string $proposalId, string $outcome, string $actor, array $actions): void {
		[$register, $proposalSchema, $actionSchema] = $this->repository->resolveSchemas();
		$objectService = $this->repository->requireObjectService();

		$proposal = $this->repository->findVoorstel(
			objectService: $objectService,
			register: $register,
			schema: $proposalSchema,
			proposalId: $proposalId,
		);

		if (in_array((string)($proposal['status'] ?? ''), self::TERMINAL_STATUSES, true) === true) {
			$this->logger->info(
				'Dossiq parafering: conclusion for ' . $proposalId . ' arrived on an already-concluded voorstel; nothing to record'
			);

			return;
		}

		$recorded = $this->recordActions(
			objectService: $objectService,
			register: $register,
			actionSchema: $actionSchema,
			proposalId: $proposalId,
			actions: $actions,
		);

		$returned = ($outcome === 'returned');
		$this->writeFinalStatus(
			objectService: $objectService,
			register: $register,
			proposalSchema: $proposalSchema,
			proposal: $proposal,
			returned: $returned,
			actions: $actions,
		);

		$this->notifySteller(proposal: $proposal, proposalId: $proposalId, returned: $returned, actor: $actor, actions: $actions);

		if ($returned === false) {
			$this->applyAccorderingSignature(proposal: $proposal, proposalId: $proposalId, actions: $actions);
		}

		$this->logger->info(
			'Dossiq parafering: recorded the decision app\'s conclusion',
			['proposal' => $proposalId, 'outcome' => $outcome, 'signOffs' => $recorded]
		);
	}//end recordConclusion()

	/**
	 * Write one parafeeractie per sign-off the event carries.
	 *
	 * Deduplicated per (step, actor, action): the repair replay and a
	 * re-delivered event both hand the same record twice, and a doubled
	 * signature reads as two people having signed.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $actionSchema The parafeeractie schema identifier.
	 * @param string $proposalId The voorstel uuid.
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return int How many rows were written.
	 */
	private function recordActions(
		object $objectService,
		string $register,
		string $actionSchema,
		string $proposalId,
		array $actions,
	): int {
		if ($actionSchema === '') {
			return 0;
		}

		$existing = $this->existingActionKeys(
			objectService: $objectService,
			register: $register,
			actionSchema: $actionSchema,
			proposalId: $proposalId,
		);

		$written = 0;
		foreach ($actions as $action) {
			if (is_array($action) === false) {
				continue;
			}

			$row = $this->actionRow(proposalId: $proposalId, action: $action);
			if ($row === null || in_array($this->keyOf(row: $row), $existing, true) === true) {
				continue;
			}

			try {
				$objectService->saveObject(object: $row, register: $register, schema: $actionSchema);
				$written++;
				$this->dispatchAudit(proposalId: $proposalId, row: $row);
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq parafering: could not record a sign-off from the conclusion',
					['proposal' => $proposalId, 'step' => ($row['step'] ?? null), 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $written;
	}//end recordActions()

	/**
	 * One sign-off, translated into the parafeeractie shape.
	 *
	 * @param string $proposalId The voorstel uuid.
	 * @param array<string, mixed> $action The decision app's action.
	 *
	 * @return array<string, mixed>|null The row, or null for an unusable entry.
	 */
	private function actionRow(string $proposalId, array $action): ?array {
		$actor = trim((string)($action['actor'] ?? ''));
		$verb = (string)($action['action'] ?? '');
		if ($actor === '' || $verb === '') {
			return null;
		}

		$row = [
			'proposal' => $proposalId,
			'step' => (int)($action['step'] ?? 0),
			'actor' => $actor,
			'actorType' => 'user',
			'action' => (self::ACTION_MAP[$verb] ?? 'parafered'),
		];

		$onBehalfOf = trim((string)($action['onBehalfOf'] ?? ''));
		if ($onBehalfOf !== '') {
			$row['actorType'] = 'delegate';
			$row['onBehalfOf'] = $onBehalfOf;
		}

		foreach (['mandate', 'comment', 'advice'] as $field) {
			$value = trim((string)($action[$field] ?? ''));
			if ($value !== '') {
				$row[$field] = $value;
			}
		}

		return $row;
	}//end actionRow()

	/**
	 * The dedup keys of the sign-offs already on file.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $actionSchema The parafeeractie schema identifier.
	 * @param string $proposalId The voorstel uuid.
	 *
	 * @return array<int, string> The keys.
	 */
	private function existingActionKeys(
		object $objectService,
		string $register,
		string $actionSchema,
		string $proposalId,
	): array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $actionSchema,
				filters: ['proposal' => $proposalId, '_limit' => 500],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: could not read existing parafeeracties; recording without dedup',
				['proposal' => $proposalId, 'error' => $e->getMessage()]
			);

			return [];
		}

		$keys = [];
		foreach ($rows as $row) {
			$keys[] = $this->keyOf(row: $this->normalizer->toArray(value: $row));
		}

		return $keys;
	}//end existingActionKeys()

	/**
	 * The dedup key of one sign-off row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The key.
	 */
	private function keyOf(array $row): string {
		return ((int)($row['step'] ?? 0)) . '|' . ((string)($row['actor'] ?? '')) . '|' . ((string)($row['action'] ?? ''));
	}//end keyOf()

	/**
	 * Write the voorstel's final status.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register identifier.
	 * @param string $proposalSchema The voorstel schema identifier.
	 * @param array<string, mixed> $proposal The voorstel.
	 * @param bool $returned Whether the chain concluded returned.
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return void
	 */
	private function writeFinalStatus(
		object $objectService,
		string $register,
		string $proposalSchema,
		array $proposal,
		bool $returned,
		array $actions,
	): void {
		$updateData = ['status' => 'geaccordeerd', 'currentStep' => 0];
		if ($returned === true) {
			$updateData = [
				'status' => 'teruggestuurd',
				'returnedFromStep' => $this->lastStepOf(actions: $actions),
			];
		}

		// 🔴 NOT `array_merge()`. `$proposal` came back from OpenRegister with
		// `routeSnapshot` DECODED (the read path decodes a string-declared
		// property whose text parses as JSON), and the update does not replace
		// it — so a bare merge carries an array into a property the schema
		// declares as a string and the save is refused. The conclusion is then
		// heard, this listener dies, and the voorstel is stranded on
		// `in_parafering` forever. See JsonEncodedStringProperties.
		$objectService->saveObject(
			object: $this->jsonProperties->mergeForWrite(
				stored: $proposal,
				updates: $updateData,
				schemaSlug: self::SCHEMA_SLUG,
			),
			register: $register,
			schema: $proposalSchema
		);
	}//end writeFinalStatus()

	/**
	 * The step number of the last recorded sign-off.
	 *
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return int The step, or 0.
	 */
	private function lastStepOf(array $actions): int {
		$last = end($actions);
		if (is_array($last) === false) {
			return 0;
		}

		return (int)($last['step'] ?? 0);
	}//end lastStepOf()

	/**
	 * Tell the steller how their voorstel came back.
	 *
	 * Best effort, exactly as the retired pipeline held it: a notification
	 * backend that is down must not undo a conclusion already recorded.
	 *
	 * @param array<string, mixed> $proposal The voorstel.
	 * @param string $proposalId Its uuid.
	 * @param bool $returned Whether the chain concluded returned.
	 * @param string $actor Who decided the final stage.
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return void
	 */
	private function notifySteller(array $proposal, string $proposalId, bool $returned, string $actor, array $actions): void {
		$author = (string)($proposal['author'] ?? '');
		if ($author === '') {
			return;
		}

		$reason = 'Voorstel volledig geaccordeerd';
		if ($returned === true) {
			$reason = $this->returnReasonOf(actions: $actions);
		}

		try {
			$this->notificationService->notifyVoorstelReturned(
				$author,
				(string)($proposal['subject'] ?? ''),
				$proposalId,
				$actor,
				$reason
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: could not notify the steller of the conclusion',
				['proposal' => $proposalId, 'error' => $e->getMessage()]
			);
		}
	}//end notifySteller()

	/**
	 * The reason the last returned sign-off carried.
	 *
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return string The reason, or ''.
	 */
	private function returnReasonOf(array $actions): string {
		foreach (array_reverse($actions) as $action) {
			if (is_array($action) === true && (string)($action['action'] ?? '') === 'returned') {
				return (string)($action['comment'] ?? '');
			}
		}

		return '';
	}//end returnReasonOf()

	/**
	 * Annotate the voorstel document with the accordering signature.
	 *
	 * Absorbed from ParafeerActieService::applyPdfSignature(). Non-blocking:
	 * absence of a writable document is a valid state, so every failure is
	 * logged at warning level and swallowed.
	 *
	 * @param array<string, mixed> $proposal The voorstel.
	 * @param string $proposalId Its uuid.
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return void
	 */
	private function applyAccorderingSignature(array $proposal, string $proposalId, array $actions): void {
		$fileId = (string)($proposal['document'] ?? '');
		if ($fileId === '') {
			return;
		}

		$accorder = $this->accorderOf(actions: $actions);
		if ($accorder === null) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder((string)$accorder['actor']);
			$nodes = $userFolder->getById((int)$fileId);
			$file = ($nodes[0] ?? null);
			if (($file instanceof File) === false) {
				$this->logger->warning(
					'Dossiq parafering: voorstel document not found or not writable for the signature annotation',
					['proposal' => $proposalId, 'file' => $fileId]
				);

				return;
			}

			$annotation = sprintf(
				"\n%%%%%%%% Dossiq parafering signature %%%%%%%%\n"
				. "Geaccordeerd via parafeerroute\nActeur: %s\nStap: %d\nTijdstip: %s\n"
				. "%%%%%%%% End Dossiq signature %%%%%%%%\n",
				(string)$accorder['actor'],
				(int)($accorder['step'] ?? 0),
				(string)($accorder['recordedAt'] ?? '')
			);
			$file->putContent($file->getContent() . $annotation);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: the accordering signature could not be applied',
				['proposal' => $proposalId, 'file' => $fileId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end applyAccorderingSignature()

	/**
	 * The final approving sign-off, if the record carries one.
	 *
	 * @param array<int, array<string, mixed>> $actions The sign-off record.
	 *
	 * @return array<string, mixed>|null The accordering action.
	 */
	private function accorderOf(array $actions): ?array {
		foreach (array_reverse($actions) as $action) {
			if (is_array($action) === true && (string)($action['action'] ?? '') === 'approved') {
				return $action;
			}
		}

		return null;
	}//end accorderOf()

	/**
	 * Raise the audit transition for one recorded sign-off.
	 *
	 * Best effort: the trail listener swallows its own failures too, and an
	 * audit hiccup must not block the record.
	 *
	 * @param string $proposalId The voorstel uuid.
	 * @param array<string, mixed> $row The recorded parafeeractie row.
	 *
	 * @return void
	 */
	private function dispatchAudit(string $proposalId, array $row): void {
		[$transition, $role] = (self::AUDIT_TRANSITIONS[(string)$row['action']] ?? ['paraferd', 'parafeerder']);

		$reason = null;
		$action = (string)$row['action'];
		if (($action === 'returned' || $action === 'skipped') && trim((string)($row['comment'] ?? '')) !== '') {
			$reason = (string)$row['comment'];
		}

		try {
			$this->eventDispatcher->dispatchTyped(
				new ParafeerTransitionEvent(
					proposalId: $proposalId,
					action: $transition,
					step: (string)((int)$row['step']),
					actor: (string)$row['actor'],
					actorRole: $role,
					reason: $reason,
				),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq parafering: audit transition dispatch failed',
				['proposal' => $proposalId, 'action' => $action, 'error' => $e->getMessage()]
			);
		}
	}//end dispatchAudit()

}//end class
