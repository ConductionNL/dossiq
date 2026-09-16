<?php

/**
 * Dossiq Case Status Store.
 *
 * Every OpenRegister read and write the status-transition engine performs.
 * Split out of StatusTransitionService so that service keeps only the
 * decision logic — which transition is available, whether its guards pass,
 * whether a concurrent write landed — while the mechanics of reaching the
 * object store live here: resolving the ObjectService bridge, reading the
 * register/schema ids out of configuration, coercing ObjectEntity results
 * to plain arrays, and translating a missing bridge or an unconfigured
 * schema into the engine's static error codes.
 *
 * The store covers four schemas the engine touches — `case`, `statusRecord`,
 * `caseType` and `statusType` — because they share exactly one concern:
 * they are the persistence surface of a single `case.status` transition.
 * Static error messages only; never bubble exception detail to callers.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister persistence for the status-transition engine.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseStatusStore {
	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService  Bridge to OpenRegister + config.
	 * @param StatusTypeLookup  $statusTypeLookup Reads a statusType, by id or by name.
	 * @param LoggerInterface   $logger           The logger.
	 * @param CaseTimeline|null $timeline         The one seam that writes a timeline entry.
	 * @param IUserSession|null $userSession      Who is making the move.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly StatusTypeLookup $statusTypeLookup,
		private readonly LoggerInterface $logger,
		private readonly ?CaseTimeline $timeline = null,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Load a case from OpenRegister.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array<string, mixed>|null The case, or null when unavailable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function loadCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
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
	 * @param array<string, mixed> $case Case payload.
	 *
	 * @return array<string, mixed> The saved case.
	 *
	 * @throws RuntimeException When OpenRegister or the case schema is unavailable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function saveCase(array $case): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('storage_unavailable');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $caseSchema === '') {
			throw new RuntimeException('case_schema_not_configured');
		}

		return $this->toArray(value: $objectService->saveObject(object: $case, register: $register, schema: $caseSchema));
	}//end saveCase()

	/**
	 * Write a statusRecord row for a transition.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $toStatus Target statusType UUID.
	 * @param string $fromStatus Prior statusType UUID.
	 * @param string $label Transition label.
	 * @param string|null $comment Free-form comment.
	 * @param array<int, array<string, mixed>> $evaluatedGuards Guard snapshots.
	 * @param bool $noWorkflowTemplate Flag for free-form transitions.
	 * @param string $actor Who made the move, or the empty string when the caller names nobody.
	 *
	 * @return array<string, mixed> The written statusRecord.
	 *
	 * @throws RuntimeException When OpenRegister or the statusRecord schema is unavailable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function writeStatusRecord(
		string $caseId,
		string $toStatus,
		string $fromStatus,
		string $label,
		?string $comment,
		array $evaluatedGuards,
		bool $noWorkflowTemplate,
		string $actor = '',
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('storage_unavailable');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
		if ($register === '' || $recordSchema === '') {
			throw new RuntimeException('status_record_schema_not_configured');
		}

		$payload = [
			'case' => $caseId,
			'statusType' => $toStatus,
			'transitionLabel' => $label,
			'evaluatedGuards' => $evaluatedGuards,
			'dispatchedActions' => [],
			'noWorkflowTemplate' => $noWorkflowTemplate,
		];

		// WHO made this move. The four-eyes rule reads this chain to find who
		// performed a named earlier act, and before this property the only
		// answer was the row's OpenRegister owner, which is a fact about who
		// wrote the record rather than about who took the step. They agree
		// today and they are not the same claim. Written only when the caller
		// names somebody: an empty string is not an actor, and a record
		// stamped with one would read as a move nobody made.
		if (trim($actor) !== '') {
			$payload['actor'] = $actor;
		}
		if ($fromStatus !== '') {
			$payload['fromStatus'] = $fromStatus;
		}

		if ($comment !== null && $comment !== '') {
			$payload['description'] = $comment;
		}

		$record = $this->toArray(value: $objectService->saveObject(object: $payload, register: $register, schema: $recordSchema));

		$this->recordOnTimeline(
			caseId: $caseId,
			toStatus: $toStatus,
			fromStatus: $fromStatus,
			label: $label,
			comment: $comment,
			record: $record,
			actor: $actor,
		);

		return $record;
	}//end writeStatusRecord()

	/**
	 * Put the move on the case's timeline.
	 *
	 * WHY HERE AND NOT IN `StatusTransitionService`. Four callers move a case's
	 * status and all four write a statusRecord through this method: the guarded
	 * transition, the admin free-form move, the ending acts (finish, abort,
	 * archive) and a reopen. A writer placed in the engine would have covered
	 * the first two and left a closed case with no line saying it closed. This
	 * is the one place all four already meet.
	 *
	 * THE MESSAGE CARRIES NAMES, THE FIELDS CARRY IDS. A status is a uuid on
	 * the case, and a timeline that read `a1b2c3…` at a handler would be worse
	 * than no line at all, so the two statuses are resolved to their
	 * administered names for the sentence while `from` and `to` keep the ids a
	 * consumer can filter on.
	 *
	 * IT NEVER FAILS THE MOVE. `CaseTimeline::record()` already answers rather
	 * than throws, and the lookup around it is guarded the same way: the case
	 * has already changed status and refusing the write because the log could
	 * not be written would trade a missing line for a lost move.
	 *
	 * @param string               $caseId     The case that moved.
	 * @param string               $toStatus   The statusType it moved into.
	 * @param string               $fromStatus The statusType it left, empty on a first status.
	 * @param string               $label      The transition's own label.
	 * @param string|null          $comment    What the mover said about it.
	 * @param array<string, mixed> $record     The statusRecord just written.
	 * @param string               $actor      Who the caller says made the move, '' when it did not say.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	private function recordOnTimeline(
		string $caseId,
		string $toStatus,
		string $fromStatus,
		string $label,
		?string $comment,
		array $record,
		string $actor = '',
	): void {
		if ($this->timeline === null || $caseId === '' || $toStatus === '') {
			return;
		}

		$toName = $this->statusName(statusTypeId: $toStatus);
		$fromName = $this->statusName(statusTypeId: $fromStatus);

		if ($fromName === '') {
			$message = 'Status gezet op ' . $toName;
		} else {
			$message = 'Status gewijzigd van ' . $fromName . ' naar ' . $toName;
		}

		$this->timeline->record(
			caseId: $caseId,
			kind: TimelineKinds::STATUS_CHANGE,
			message: $message,
			fields: [
				'from' => $fromStatus,
				'to' => $toStatus,
				'actor' => $this->actor(named: $actor),
				'explanation' => (string)($comment ?? ''),
				'label' => $label,
				'statusRecordId' => (string)($record['id'] ?? ($record['@self']['id'] ?? '')),
			],
			visibility: CaseTimeline::INTERNAL,
		);
	}//end recordOnTimeline()

	/**
	 * The administered name of a statusType, or its id when the name is not readable.
	 *
	 * The id is the honest fallback: a sentence reading "Status gewijzigd naar
	 * a1b2c3" is poor, and a sentence reading "Status gewijzigd naar " is a bug
	 * report nobody can act on.
	 *
	 * @param string $statusTypeId The statusType uuid, or ''.
	 *
	 * @return string The name, the id, or '' when nothing was asked for.
	 */
	private function statusName(string $statusTypeId): string {
		if ($statusTypeId === '') {
			return '';
		}

		try {
			$name = trim($this->statusTypeLookup->nameFor(statusTypeId: $statusTypeId));
		} catch (\Throwable $e) {
			$name = '';
		}

		if ($name === '') {
			return $statusTypeId;
		}

		return $name;
	}//end statusName()

	/**
	 * Who made the move, as a user id, or '' for a move nobody signed.
	 *
	 * THE CALLER'S ANSWER WINS. `writeStatusRecord()` now takes the actor,
	 * because the four-eyes rule needs to know who took a step rather than who
	 * wrote the row, and the two are not the same claim. The session is the
	 * fallback for the callers that do not name one yet.
	 *
	 * A background job and a timer both move cases, and '' is the true answer
	 * for those rather than a name invented to fill the field.
	 *
	 * @param string $named Who the caller says made the move, '' when it did not say.
	 *
	 * @return string The uid, or ''.
	 */
	private function actor(string $named = ''): string {
		if (trim($named) !== '') {
			return trim($named);
		}

		return (string)($this->userSession?->getUser()?->getUID() ?? '');
	}//end actor()

	/**
	 * Persist an updated statusRecord.
	 *
	 * Returns the record untouched when OpenRegister is unavailable — the
	 * caller treats a failed dispatched-action write-back as non-fatal.
	 *
	 * @param array<string, mixed> $record Current record payload.
	 *
	 * @return array<string, mixed> The saved (or unchanged) statusRecord.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function updateStatusRecord(array $record): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $record;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
		if ($register === '' || $recordSchema === '') {
			return $record;
		}

		return $this->toArray(value: $objectService->saveObject(object: $record, register: $register, schema: $recordSchema));
	}//end updateStatusRecord()

	/**
	 * Fetch every statusRecord written for a case, unordered.
	 *
	 * OpenRegister's ObjectService exposes `searchObjects($query)` — there is
	 * NO `findObjects()` method. Register/schema context lives under the
	 * `@self` block; the `case` field filter sits at the top level as a
	 * server-side equality match.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array<int, array<string, mixed>>|null The records, or null when
	 *                                               the history cannot be read.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function findStatusRecords(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
		if ($register === '' || $recordSchema === '') {
			return null;
		}

		try {
			$records = $objectService->searchObjects(
				[
					'@self' => [
						'register' => (int)$register,
						'schema' => (int)$recordSchema,
					],
					'case' => $caseId,
				],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'StatusTransitionService: replay searchObjects failed',
				['exception' => $e->getMessage(), 'caseId' => $caseId],
			);
			return null;
		}//end try

		$recordList = [];
		if (is_array($records) === true) {
			$recordList = $records;
		}

		$list = [];
		foreach ($recordList as $record) {
			$list[] = $this->toArray(value: $record);
		}

		return $list;
	}//end findStatusRecords()

	/**
	 * Look up a human-readable status name for the case-detail panel header.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The status name, or the empty string when unresolvable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function lookupStatusName(string $statusTypeId): string {
		return $this->statusTypeLookup->nameFor(statusTypeId: $statusTypeId);
	}//end lookupStatusName()

	/**
	 * Look up the colour a status is drawn in.
	 *
	 * The case page's transition strip renders the current status as a badge,
	 * and a badge with no colour says the same thing about every status. The
	 * colour travels with the status name rather than being fetched a second
	 * time by the browser: both come off the same row, and a second round trip
	 * to colour a label the page already has is a request nobody needs.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The colour name, or the empty string when the status
	 *                carries none. The frontend falls back to grey.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function lookupStatusColour(string $statusTypeId): string {
		$colour = ($this->statusTypeLookup->rowFor(statusTypeId: $statusTypeId)['colour'] ?? '');

		if (is_string($colour) === false) {
			return '';
		}

		return $colour;
	}//end lookupStatusColour()

	/**
	 * The explanation an administrator wrote on a status.
	 *
	 * `statusType.description` has existed for as long as the schema has, and
	 * nothing rendered it: an administrator who wrote one was writing into a
	 * field nobody read. It travels with the name and the colour because all
	 * three come off the same row, and a second round trip to fetch a sentence
	 * the page is already asking about is a request nobody needs.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return string The description, or the empty string.
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function lookupStatusDescription(string $statusTypeId): string {
		return trim((string)($this->statusTypeLookup->rowFor(statusTypeId: $statusTypeId)['description'] ?? ''));
	}//end lookupStatusDescription()

	/**
	 * Validate that a statusType belongs to the case's caseType.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the statusType is not a child of the caseType.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function assertStatusBelongsToCaseType(string $caseTypeId, string $statusTypeId): void {
		$unconfigured = in_array('', [$caseTypeId, $statusTypeId], true);
		if ($unconfigured === true) {
			throw new RuntimeException('case_type_not_configured');
		}

		// 🔴 THE LINK LIVES ON THE CHILD. This asked the CASE TYPE for a
		// `statusTypes` list, a property the schema does not declare — every
		// `statusType` carries a `caseType` back-reference instead. The list was
		// therefore empty on every real case type, the membership loop matched
		// nothing, and an admin's free-form move was refused with
		// `status_type_not_in_case_type` whatever it was asked to do. Reading
		// the back-reference asks the question the schema can answer.
		$parent = ($this->statusTypeLookup->rowFor(statusTypeId: $statusTypeId)['caseType'] ?? '');
		if (is_array($parent) === true) {
			$parent = ($parent['id'] ?? ($parent['uuid'] ?? ''));
		}

		if ((string)$parent !== $caseTypeId) {
			throw new RuntimeException('status_type_not_in_case_type');
		}
	}//end assertStatusBelongsToCaseType()

	/**
	 * Coerce ObjectService results to an array.
	 *
	 * @param mixed $value Raw result.
	 *
	 * @return array<string, mixed> The coerced array, empty when uncoercible.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function toArray(mixed $value): array {
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
