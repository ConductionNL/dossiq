<?php

/**
 * When each milestone of a case is due, and who owns it.
 *
 * Rows 3.27 and part of 3.29 of the competitor gap register.
 * `milestoneDefinition` has declared `dependsOn` since it shipped and NOTHING
 * READ IT. Every milestone was dated from the case start, so a milestone that
 * should be two weeks after the hearing was two weeks after an ESTIMATE of the
 * hearing, and when the hearing moved, nothing else did. That is the specific
 * failure this class exists to end: one date moves and the rest follow.
 *
 * WHAT A PREDECESSOR'S DATE IS. The date a milestone was actually reached when
 * it has been reached, and its own projection when it has not. That is the
 * whole mechanism behind "the hearing moves and the timeline follows": nothing
 * recomputes the downstream items on a schedule, they are simply projected
 * from whatever the predecessor's date is now.
 *
 * WHY `dependsOn` IS A LIST AND THE LATEST ENTRY WINS. The field's own
 * description is "Milestone identifiers that must be reached before this one",
 * so a milestone with three predecessors waits for all three, and the date that
 * satisfies all three is the LAST of them. Taking the first entry would have
 * been the obvious reading and it would date a milestone before work it is
 * declared to wait on.
 *
 * WHAT STAYS THE SAME. A milestone naming no predecessor keeps counting from
 * the case start, cumulatively, in `order`. That is D-2 and it is why nothing
 * has to be rewritten on the day this ships: a case type adopts the new shape
 * one item at a time, and an item that adopts nothing behaves exactly as it
 * did.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Milestone
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
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

use DateTimeImmutable;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * Projects the milestone timeline of one case.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
class MilestoneSchedule {

	/**
	 * How deep a dependency chain may be resolved.
	 *
	 * A cycle is refused at authoring time by {@see self::cycle()}, but a
	 * definition set imported before that check existed can still carry one,
	 * and a projection that recurses forever is a request that never returns.
	 * The cap is the second line of defence rather than the first.
	 *
	 * @var int
	 */
	public const MAX_CHAIN_DEPTH = 50;

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
	 * The projected date of every milestone definition, by identifier.
	 *
	 * @param array<int, array<string, mixed>> $definitions The case type's definitions.
	 * @param DateTimeImmutable $caseStart The case start date.
	 * @param array<string, DateTimeImmutable> $reached Actual dates, by identifier.
	 *
	 * @return array<string, DateTimeImmutable> The date each milestone is due, by identifier.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function project(array $definitions, DateTimeImmutable $caseStart, array $reached = []): array {
		$ordered = $this->ordered(definitions: $definitions);
		$byIdentifier = [];
		foreach ($ordered as $definition) {
			$identifier = (string)($definition['identifier'] ?? '');
			if ($identifier !== '') {
				$byIdentifier[$identifier] = $definition;
			}
		}

		// The cumulative sum is over the items that name NO predecessor, in
		// order, and over those only. Counting a dependent item into the
		// running total would push every later independent item out by work
		// that hangs off a different branch entirely.
		$cumulative = [];
		$running = 0;
		foreach ($ordered as $definition) {
			$identifier = (string)($definition['identifier'] ?? '');
			if ($identifier === '' || count($this->predecessorsOf(definition: $definition)) > 0) {
				continue;
			}

			$running = ($running + $this->durationOf(definition: $definition));
			$cumulative[$identifier] = $running;
		}

		$dates = [];
		foreach ($byIdentifier as $identifier => $definition) {
			$this->resolve(
				identifier: $identifier,
				byIdentifier: $byIdentifier,
				cumulative: $cumulative,
				caseStart: $caseStart,
				reached: $reached,
				dates: $dates,
				depth: 0
			);
		}

		return $dates;
	}//end project()

	/**
	 * The identifiers that form a dependency cycle, or an empty list.
	 *
	 * Called when the case type is saved, which is where the person who can
	 * fix it is (D-3). A cycle discovered at runtime is either a loop or a
	 * date that silently never resolves, and both are found by whoever is
	 * reading the case rather than by whoever wrote the definition.
	 *
	 * @param array<int, array<string, mixed>> $definitions The definitions to check.
	 *
	 * @return string[] The identifiers on the cycle, in the order they chain, or [].
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function cycle(array $definitions): array {
		$byIdentifier = [];
		foreach ($definitions as $definition) {
			$identifier = (string)($definition['identifier'] ?? '');
			if ($identifier !== '') {
				$byIdentifier[$identifier] = $definition;
			}
		}

		$state = [];
		foreach (array_keys($byIdentifier) as $identifier) {
			$found = $this->walkForCycle(
				identifier: $identifier,
				byIdentifier: $byIdentifier,
				state: $state,
				path: []
			);
			if (count($found) > 0) {
				return $found;
			}
		}

		return [];
	}//end cycle()

	/**
	 * Who owns this milestone on this case.
	 *
	 * Resolved from a ROLE rather than a named person (D-4), for the reason
	 * `task-defaults-to-case-handler` gives: a timeline that names people is
	 * wrong the first time somebody leaves, and a timeline that names roles is
	 * not. A definition naming no role has NO owner, and that is returned as
	 * an empty string rather than filled in from the case's assignee: a
	 * guessed owner is worse than none, because nobody can tell it was guessed.
	 *
	 * @param array<string, mixed> $definition The milestone definition.
	 * @param array<string, mixed> $case The case, as the object API answers it.
	 * @param array<string, string> $roleHolders Who holds each role on this case, by role name.
	 *
	 * @return string The owner's user id, or '' when the definition names no role.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function ownerOf(array $definition, array $case, array $roleHolders = []): string {
		$role = trim((string)($definition['ownerRole'] ?? ''));
		if ($role === '') {
			return '';
		}

		if (isset($roleHolders[$role]) === true && (string)$roleHolders[$role] !== '') {
			return (string)$roleHolders[$role];
		}

		// `assignee` is the case handler, which is a role a case always has and
		// which OpenRegister answers on the case itself rather than through the
		// role table. It is resolved here ONLY for the role that names it, so
		// this is a lookup and not the fallback the docblock refuses.
		if (in_array($role, ['assignee', 'behandelaar', 'caseHandler'], true) === true) {
			return (string)($case['assignee'] ?? '');
		}

		return '';
	}//end ownerOf()

	/**
	 * Resolve one milestone's date, recursively.
	 *
	 * @param string $identifier The milestone to resolve.
	 * @param array<string, array<string, mixed>> $byIdentifier Every definition, by identifier.
	 * @param array<string, int> $cumulative The running total for predecessor-less items.
	 * @param DateTimeImmutable $caseStart The case start date.
	 * @param array<string, DateTimeImmutable> $reached Actual dates, by identifier.
	 * @param array<string, DateTimeImmutable> $dates The memo, written in place.
	 * @param int $depth The current recursion depth.
	 *
	 * @return DateTimeImmutable|null The date, or null when it cannot be resolved.
	 *
	 * @psalm-suppress UnusedReturnValue The return IS read, by the recursive
	 * call that folds a predecessor's date into this one. Psalm does not count
	 * a method's call to itself, so it sees only `project()`'s outer loop,
	 * which discards it on purpose: that loop wants the memo, and the memo is
	 * `$dates`, written by reference so a diamond in the chain is resolved
	 * once rather than once per path into it.
	 */
	private function resolve(
		string $identifier,
		array $byIdentifier,
		array $cumulative,
		DateTimeImmutable $caseStart,
		array $reached,
		array &$dates,
		int $depth,
	): ?DateTimeImmutable {
		if (isset($dates[$identifier]) === true) {
			return $dates[$identifier];
		}

		if ($depth > self::MAX_CHAIN_DEPTH || isset($byIdentifier[$identifier]) === false) {
			return null;
		}

		// A milestone that has been reached IS its date. Projecting over the
		// top of a fact is how a timeline ends up disagreeing with the record
		// of what happened.
		if (isset($reached[$identifier]) === true) {
			$dates[$identifier] = $reached[$identifier];
			return $dates[$identifier];
		}

		$definition = $byIdentifier[$identifier];
		$predecessors = $this->predecessorsOf(definition: $definition);

		if (count($predecessors) === 0) {
			$dates[$identifier] = $this->workingDays->addWorkingDays(
				start: $caseStart,
				days: (int)($cumulative[$identifier] ?? $this->durationOf(definition: $definition))
			);
			return $dates[$identifier];
		}

		// Every named predecessor missing from this case type falls back to the
		// case start: the declaration points at nothing, and dropping the
		// milestone off the timeline would hide that rather than show it.
		$from = $this->latestPredecessorDate(
			predecessors: $predecessors,
			byIdentifier: $byIdentifier,
			cumulative: $cumulative,
			caseStart: $caseStart,
			reached: $reached,
			dates: $dates,
			depth: $depth,
		) ?? $caseStart;

		$dates[$identifier] = $this->workingDays->addWorkingDays(
			start: $from,
			days: $this->durationOf(definition: $definition)
		);

		return $dates[$identifier];
	}//end resolve()

	/**
	 * The latest date among a milestone's predecessors, or null when none resolve.
	 *
	 * All of them must be reached before this one, so the date that satisfies
	 * the declaration is the LATEST of them, not the first.
	 *
	 * @param array<int, string>                    $predecessors The declared predecessors.
	 * @param array<string, array<string, mixed>>   $byIdentifier Every milestone by identifier.
	 * @param array<string, int>                    $cumulative   The cumulative durations.
	 * @param DateTimeImmutable                     $caseStart    When the case started.
	 * @param array<string, DateTimeImmutable>      $reached      The milestones already reached.
	 * @param array<string, DateTimeImmutable|null> $dates        The dates resolved so far.
	 * @param int                                   $depth        How deep the chain walk is.
	 *
	 * @return DateTimeImmutable|null The latest predecessor date, or null when none resolved.
	 */
	private function latestPredecessorDate(
		array $predecessors,
		array $byIdentifier,
		array $cumulative,
		DateTimeImmutable $caseStart,
		array $reached,
		array &$dates,
		int $depth,
	): ?DateTimeImmutable {
		$from = null;
		foreach ($predecessors as $predecessor) {
			$predecessorDate = $this->resolve(
				identifier: $predecessor,
				byIdentifier: $byIdentifier,
				cumulative: $cumulative,
				caseStart: $caseStart,
				reached: $reached,
				dates: $dates,
				depth: ($depth + 1)
			);

			if ($predecessorDate !== null && ($from === null || $predecessorDate > $from)) {
				$from = $predecessorDate;
			}
		}

		return $from;
	}//end latestPredecessorDate()

	/**
	 * Depth-first walk looking for a cycle.
	 *
	 * @param string $identifier The node to walk from.
	 * @param array<string, array<string, mixed>> $byIdentifier Every definition, by identifier.
	 * @param array<string, string> $state Node colours, written in place.
	 * @param string[] $path The chain walked so far.
	 *
	 * @return string[] The cycle, or [].
	 */
	private function walkForCycle(string $identifier, array $byIdentifier, array &$state, array $path): array {
		if (($state[$identifier] ?? '') === 'done') {
			return [];
		}

		if (($state[$identifier] ?? '') === 'walking') {
			// Report the cycle itself, not the path that led into it: an
			// administrator has to know which items to break, and the run-up
			// is not part of the answer.
			$start = array_search($identifier, $path, true);
			if ($start === false) {
				return [$identifier];
			}

			return array_values(array_slice($path, (int)$start));
		}

		if (isset($byIdentifier[$identifier]) === false) {
			return [];
		}

		$state[$identifier] = 'walking';
		$path[] = $identifier;

		foreach ($this->predecessorsOf(definition: $byIdentifier[$identifier]) as $predecessor) {
			$found = $this->walkForCycle(
				identifier: $predecessor,
				byIdentifier: $byIdentifier,
				state: $state,
				path: $path
			);
			if (count($found) > 0) {
				return $found;
			}
		}

		$state[$identifier] = 'done';

		return [];
	}//end walkForCycle()

	/**
	 * The identifiers this definition waits on.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return string[] The predecessors, empty entries dropped.
	 */
	private function predecessorsOf(array $definition): array {
		$raw = ($definition['dependsOn'] ?? []);
		if (is_string($raw) === true) {
			// OpenRegister answers an array property as a JSON string on some
			// read paths. Decoding here rather than at every call site is what
			// keeps the difference from becoming "dependsOn is empty".
			$decoded = json_decode($raw, true);
			$raw = [];
			if (is_array($decoded) === true) {
				$raw = $decoded;
			}
		}

		if (is_array($raw) === false) {
			return [];
		}

		$out = [];
		foreach ($raw as $entry) {
			$identifier = trim((string)$entry);
			if ($identifier !== '') {
				$out[] = $identifier;
			}
		}

		return $out;
	}//end predecessorsOf()

	/**
	 * How many working days this milestone takes.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return int The duration, never negative.
	 */
	private function durationOf(array $definition): int {
		return max(0, (int)($definition['expectedDurationWorkingDays'] ?? 0));
	}//end durationOf()

	/**
	 * The definitions, sorted by their declared order.
	 *
	 * @param array<int, array<string, mixed>> $definitions The definitions.
	 *
	 * @return array<int, array<string, mixed>> The sorted definitions.
	 */
	private function ordered(array $definitions): array {
		$sorted = array_values($definitions);
		usort(
			$sorted,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		return $sorted;
	}//end ordered()
}//end class
