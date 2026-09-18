<?php

/**
 * A status holds only so many cases, and refuses the one past that.
 *
 * Guard type: `statusCapacity`. Like the status checklist it is not a guard a
 * template declares: the engine appends it to every transition, because a
 * limit that only bites on the roads somebody remembered to write it onto is a
 * limit anybody can walk around by taking another road into the phase.
 *
 * 🔴 A LIMIT THAT DOES NOT REFUSE IS DECORATION. That is the whole of gap
 * register row Q3.22, and it was measured rather than argued: Kanboard colours
 * a full column's header and lets a second card into a column with a limit of
 * one; Vikunja refuses the card. Colouring a header is a nicer way of missing a
 * term. So this answers `passed: false` and the engine turns that into a
 * refusal with a sentence.
 *
 * 🔴 IT READS THE TARGET, NEVER THE CASE'S CURRENT STATUS. `StatusChecklistGuard`
 * beside it evaluates the status the case is LEAVING; this one evaluates the
 * status it is entering, which arrives in the guard config as `toStatus`. That
 * is also what makes "a full status can always be emptied" true without a rule
 * of its own: moving out is a transition into some other status, and this guard
 * never looks where a case came from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;
use OCP\IL10N;
use Throwable;

/**
 * Guard: the status being entered is not already at its capacity.
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */
class CapacityGuard implements GuardEvaluatorInterface {

	/**
	 * Constructor.
	 *
	 * @param StatusTypeLookup $statuses        Resolves the status row and its name.
	 * @param CaseTypeReader   $caseTypes       Answers whether a status is final.
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the schemas.
	 * @param IL10N            $l10n            The localisation service.
	 */
	public function __construct(
		private readonly StatusTypeLookup $statuses,
		private readonly CaseTypeReader $caseTypes,
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Evaluate the implicit capacity guard against the status being entered.
	 *
	 * @param array<string, mixed> $guardConfig Carries `toStatus`, appended by the engine.
	 * @param array<string, mixed> $case        The case being moved.
	 * @param string               $userId      Current user UID. Unused: a capacity is
	 *                                          a property of the status, not of who asks.
	 *
	 * @return GuardResult The verdict, naming the status, the limit and the count.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$targetId = trim((string)($guardConfig['toStatus'] ?? ''));
		if ($targetId === '') {
			return new GuardResult(passed: true);
		}

		$capacity = $this->capacityOf(statusTypeId: $targetId);
		if ($capacity <= 0) {
			// Absent or zero is no limit, which is every status shipped today.
			return new GuardResult(passed: true);
		}

		// 🔴 A FINAL STATUS IS NEVER CAPPED, and this is the whole of the
		// design's "excluding cases in a final status". A case counted towards
		// a status's limit is by definition IN that status, so the only way a
		// closed case can reach the count is if the status being entered is
		// itself the closing one. Capping that would mean a case type could
		// stop being able to close cases after its twelfth, which is the
		// deadlock the rule exists to prevent, one step further along.
		if ($this->caseTypes->isFinalStatus(statusTypeId: $targetId) === true) {
			return new GuardResult(passed: true, details: ['final' => true]);
		}

		// The case being moved is not counted against the place it is about to
		// take. Without this a self-transition, or a re-run of a transition
		// that already landed, would refuse a case for occupying its own seat.
		$count = $this->countIn(statusTypeId: $targetId, excluding: $this->idOf(case: $case));
		if ($count === null) {
			// 🔴 AN UNREADABLE COUNT ALLOWS THE MOVE. A capacity is a planning
			// aid, not an authorization: refusing work because a read failed
			// would stop a desk over an outage, and the term keeps running
			// either way. The checklist guard fails the other way because a
			// required item is a rule about the case; this is a rule about the
			// queue.
			return new GuardResult(passed: true, details: ['unreadable' => true]);
		}

		if ($count < $capacity) {
			return new GuardResult(
				passed: true,
				details: ['capacity' => $capacity, 'count' => $count],
			);
		}

		return new GuardResult(
			passed: false,
			failureMessage: $this->l10n->t(
				'Status %1$s is full: %2$s of %3$s cases.',
				[$this->statuses->nameFor(statusTypeId: $targetId), (string)$count, (string)$capacity]
			),
			details: [
				'statusType' => $targetId,
				'capacity' => $capacity,
				'count' => $count,
			],
		);
	}//end evaluate()

	/**
	 * The limit a status carries, as a whole number.
	 *
	 * @param string $statusTypeId The status being entered.
	 *
	 * @return int The capacity, or 0 for no limit.
	 *
	 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
	 */
	public function capacityOf(string $statusTypeId): int {
		$row = $this->statuses->rowFor(statusTypeId: $statusTypeId);
		$declared = ($row['capacity'] ?? 0);
		if (is_numeric($declared) === false) {
			return 0;
		}

		return max(0, (int)$declared);
	}//end capacityOf()

	/**
	 * How many cases are sitting in a status right now.
	 *
	 * 🔴 COUNTED BY STATUS ALONE, NOT BY STATUS AND CASE TYPE. `statusType`
	 * carries its own `caseType`, so a status id already names one lifecycle;
	 * and where a child case type INHERITS a parent's status, the cases of both
	 * types really are in one status, so one limit over both is what "this
	 * status holds twelve" means. Filtering by case type as well would let two
	 * types put twenty-four cases in a status whose limit reads twelve.
	 *
	 * 🔴 NO `limit` IS PASSED. A limited read answers a truncated `total` on
	 * some shapes and a truncated row list on all of them, and a count that
	 * silently stops at the limit is a count that can only ever report the
	 * boundary. A capped status holds a small number by construction, and a
	 * status with no capacity never reaches this method.
	 *
	 * @param string $statusTypeId The status.
	 * @param string $excluding    A case id not to count, or ''.
	 *
	 * @return int|null The count, or null when the store could not answer.
	 *
	 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
	 */
	public function countIn(string $statusTypeId, string $excluding = ''): ?int {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$result = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						// A BARE key. The objects endpoint reads a bare property
						// name and answers the empty set for a `filter[...]`
						// one, with no error either way (openregister#3611) —
						// and an empty set here reads as "the status is free".
						'status' => $statusTypeId,
					],
				]
			);
		} catch (Throwable) {
			return null;
		}

		$rows = [];
		if (is_array($result) === true) {
			$rows = ($result['results'] ?? $result);
		}

		if (is_array($rows) === false) {
			return null;
		}

		$counted = 0;
		foreach ($rows as $row) {
			$id = $this->idOf(case: $this->arrayOf(row: $row));
			if ($id !== '' && $id === $excluding) {
				continue;
			}

			$counted++;
		}

		return $counted;
	}//end countIn()

	/**
	 * A case's id, whichever key the row carries it under.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $case): string {
		foreach (['uuid', 'id'] as $key) {
			$value = trim((string)($case[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$self = ($case['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id'] as $key) {
				$value = trim((string)($self[$key] ?? ''));
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end idOf()

	/**
	 * One row as an array, whatever the store handed back.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function arrayOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end arrayOf()
}//end class
