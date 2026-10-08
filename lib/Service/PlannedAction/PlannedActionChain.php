<?php

/**
 * What is planned next on a case, and what follows that.
 *
 * Row 3.28 of the competitor gap register. A reminder fires at a date; a
 * planned next action is a different thing entirely: a typed piece of work,
 * with an owner, whose completion schedules the one that follows. It is how a
 * case with a long sequence of contacts is actually run, and it is the record
 * that answers "what happens next on this case" without reading the whole
 * file.
 *
 * THIS CLASS IS THE DECISION AND NOTHING ELSE. It reads no register and writes
 * none, so the chain can be tested against a table of types rather than against
 * an instance, and so the one question it answers, WHAT COMES NEXT, is in one
 * place rather than spread through a service that also fetches and saves.
 *
 * ONE STEP AT A TIME (D-6). Creating the whole chain up front produces a list
 * of future actions that are wrong the moment one of them is skipped, and worse
 * than wrong: it is a plan somebody reads and believes. Each completion plans
 * exactly the next one.
 *
 * A CANCELLED ACTION PLANS NOTHING, which is the difference between cancelling
 * and completing and the reason the state is an enum rather than a boolean. A
 * handler who drops a step is saying the chain stops here, not that the step
 * happened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\PlannedAction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\PlannedAction;

use DateTimeImmutable;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * Decides which action follows a completed one, and when.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */
class PlannedActionChain {

	/**
	 * How many links a chain may have before it is treated as a loop.
	 *
	 * Only reached by {@see self::isCyclic()}: the chain is scheduled one step
	 * at a time, so a cycle in the TYPES does not hang anything at runtime, it
	 * simply plans forever, one completion at a time. That is worse than a
	 * hang in one way, because nobody notices, which is why the cycle is
	 * reported rather than waited for.
	 *
	 * @var int
	 */
	public const MAX_CHAIN_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $workingDays Weekend and Dutch-holiday arithmetic.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $workingDays,
	) {
	}//end __construct()

	/**
	 * The action to plan when this one is completed, or null when the chain ends.
	 *
	 * @param array<string, mixed> $completed The action being completed.
	 * @param array<string, array<string, mixed>> $types The action types, by identifier.
	 * @param DateTimeImmutable $completedOn The date it was completed.
	 * @param array<string, string> $roleHolders Who holds each role on this case, by role name.
	 *
	 * @return array<string, mixed>|null The action to write, or null.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function next(
		array $completed,
		array $types,
		DateTimeImmutable $completedOn,
		array $roleHolders = [],
	): ?array {
		if ((string)($completed['state'] ?? 'planned') === 'cancelled') {
			return null;
		}

		$typeIdentifier = (string)($completed['actionType'] ?? '');
		$type = ($types[$typeIdentifier] ?? null);
		if ($type === null) {
			// The type this action was planned as is gone. Planning a successor
			// would mean guessing what an administrator deleted, so the chain
			// ends here and the action stays completed.
			return null;
		}

		$successorIdentifier = trim((string)($type['successor'] ?? ''));
		if ($successorIdentifier === '') {
			return null;
		}

		$successor = ($types[$successorIdentifier] ?? null);
		if ($successor === null) {
			// A successor naming a type that does not exist. The declaration is
			// broken and the honest answer is no next action, not an action of
			// an unknown type that no list can label.
			return null;
		}

		$offset = max(0, (int)($type['successorOffsetWorkingDays'] ?? 0));

		return [
			'case' => (string)($completed['case'] ?? ''),
			'actionType' => $successorIdentifier,
			'label' => (string)($successor['label'] ?? $successorIdentifier),
			'owner' => $this->ownerOf(type: $successor, roleHolders: $roleHolders),
			'plannedFor' => $this->workingDays
				->addWorkingDays(start: $completedOn, days: $offset)
				->format('Y-m-d'),
			'state' => 'planned',
			'plannedFrom' => (string)($completed['id'] ?? ($completed['uuid'] ?? '')),
		];
	}//end next()

	/**
	 * Who owns an action of this type on this case.
	 *
	 * Resolved from a ROLE and never from a person, for the reason D-4 gives
	 * about the timeline: a plan that names people is wrong the first time
	 * somebody leaves. A type naming no role leaves the action unowned rather
	 * than assigning it to whoever completed the previous step, which would be
	 * a guess that reads exactly like a decision.
	 *
	 * @param array<string, mixed> $type The action type.
	 * @param array<string, string> $roleHolders Who holds each role on this case.
	 *
	 * @return string The owner's user id, or ''.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function ownerOf(array $type, array $roleHolders): string {
		$role = trim((string)($type['ownerRole'] ?? ''));
		if ($role === '') {
			return '';
		}

		return (string)($roleHolders[$role] ?? '');
	}//end ownerOf()

	/**
	 * Whether the successor declarations form a loop.
	 *
	 * @param array<string, array<string, mixed>> $types The action types, by identifier.
	 *
	 * @return string[] The identifiers on the loop, or [].
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	public function isCyclic(array $types): array {
		foreach (array_keys($types) as $start) {
			$seen = [];
			$current = (string)$start;
			for ($step = 0; $step < self::MAX_CHAIN_LENGTH; $step++) {
				if (isset($seen[$current]) === true) {
					return array_keys($seen);
				}

				$seen[$current] = true;
				$next = trim((string)($types[$current]['successor'] ?? ''));
				if ($next === '' || isset($types[$next]) === false) {
					break;
				}

				$current = $next;
			}
		}

		return [];
	}//end isCyclic()
}//end class
