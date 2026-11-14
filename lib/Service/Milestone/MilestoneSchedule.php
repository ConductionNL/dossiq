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
use OCA\Dossiq\Kernel\Contracts\MilestoneScheduleInterface;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * Projects the milestone timeline of one case.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
class MilestoneSchedule implements MilestoneScheduleInterface {

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
		$run = new MilestoneProjection(
			byIdentifier: $this->byIdentifier(definitions: $ordered),
			cumulative: $this->cumulativeDurations(ordered: $ordered),
			caseStart: $caseStart,
			reached: $reached
		);

		foreach (array_keys($run->byIdentifier) as $identifier) {
			$this->resolve(run: $run, step: new ChainStep(identifier: $identifier, depth: 0));
		}

		return $run->dates;
	}//end project()

	/**
	 * Every definition with an identifier, by identifier; a later one with the same identifier wins.
	 *
	 * @param array<int, array<string, mixed>> $definitions The definitions.
	 *
	 * @return array<string, array<string, mixed>> The definitions by identifier.
	 */
	private function byIdentifier(array $definitions): array {
		$byIdentifier = [];
		foreach ($definitions as $definition) {
			$identifier = (string)($definition['identifier'] ?? '');
			if ($identifier !== '') {
				$byIdentifier[$identifier] = $definition;
			}
		}

		return $byIdentifier;
	}//end byIdentifier()

	/**
	 * The running total of working days of the items that name no predecessor, in order.
	 *
	 * The cumulative sum is over the items that name NO predecessor, in
	 * order, and over those only. Counting a dependent item into the
	 * running total would push every later independent item out by work
	 * that hangs off a different branch entirely.
	 *
	 * @param array<int, array<string, mixed>> $ordered The definitions, in order.
	 *
	 * @return array<string, int> The running total at each predecessor-less item, by identifier.
	 */
	private function cumulativeDurations(array $ordered): array {
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

		return $cumulative;
	}//end cumulativeDurations()

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
		$search = new CycleSearch(byIdentifier: $this->byIdentifier(definitions: $definitions));
		foreach (array_keys($search->byIdentifier) as $identifier) {
			$found = $this->walkFrom(search: $search, identifier: $identifier);
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
	 * @param MilestoneProjection $run  The projection, whose memo this writes.
	 * @param ChainStep           $step The milestone to resolve and the depth of the walk.
	 *
	 * @return DateTimeImmutable|null The date, or null when it cannot be resolved.
	 *
	 * @psalm-suppress UnusedReturnValue The return IS read, by the recursive
	 * call that folds a predecessor's date into this one. Psalm does not count
	 * a method's call to itself, so it sees only `project()`'s outer loop,
	 * which discards it on purpose: that loop wants the memo, and the memo is
	 * `$run->dates`, written in place so a diamond in the chain is resolved
	 * once rather than once per path into it.
	 */
	private function resolve(MilestoneProjection $run, ChainStep $step): ?DateTimeImmutable {
		$identifier = $step->identifier;
		if (isset($run->dates[$identifier]) === true) {
			return $run->dates[$identifier];
		}

		if ($step->depth > self::MAX_CHAIN_DEPTH || isset($run->byIdentifier[$identifier]) === false) {
			return null;
		}

		// A milestone that has been reached IS its date. Projecting over the
		// top of a fact is how a timeline ends up disagreeing with the record
		// of what happened.
		$run->dates[$identifier] = ($run->reached[$identifier] ?? $this->projectedDate(run: $run, step: $step));

		return $run->dates[$identifier];
	}//end resolve()

	/**
	 * The projected date of a milestone that is not reached yet.
	 *
	 * @param MilestoneProjection $run  The projection.
	 * @param ChainStep           $step The milestone and the depth of the walk.
	 *
	 * @return DateTimeImmutable The date.
	 */
	private function projectedDate(MilestoneProjection $run, ChainStep $step): DateTimeImmutable {
		$definition = $run->byIdentifier[$step->identifier];
		if (count($this->predecessorsOf(definition: $definition)) === 0) {
			return $this->workingDays->addWorkingDays(
				start: $run->caseStart,
				days: (int)($run->cumulative[$step->identifier] ?? $this->durationOf(definition: $definition))
			);
		}

		// Every named predecessor missing from this case type falls back to the
		// case start: the declaration points at nothing, and dropping the
		// milestone off the timeline would hide that rather than show it.
		return $this->workingDays->addWorkingDays(
			start: ($this->latestPredecessorDate(run: $run, step: $step) ?? $run->caseStart),
			days: $this->durationOf(definition: $definition)
		);
	}//end projectedDate()

	/**
	 * The latest date among a milestone's predecessors, or null when none resolve.
	 *
	 * All of them must be reached before this one, so the date that satisfies
	 * the declaration is the LATEST of them, not the first.
	 *
	 * @param MilestoneProjection $run  The projection.
	 * @param ChainStep           $step The milestone whose predecessors are read.
	 *
	 * @return DateTimeImmutable|null The latest predecessor date, or null when none resolved.
	 */
	private function latestPredecessorDate(MilestoneProjection $run, ChainStep $step): ?DateTimeImmutable {
		$from = null;
		foreach ($this->predecessorsOf(definition: $run->byIdentifier[$step->identifier]) as $predecessor) {
			$predecessorDate = $this->resolve(run: $run, step: $step->toPredecessor(identifier: $predecessor));
			if ($predecessorDate !== null && ($from === null || $predecessorDate > $from)) {
				$from = $predecessorDate;
			}
		}

		return $from;
	}//end latestPredecessorDate()

	/**
	 * Walk for a cycle from one definition.
	 *
	 * @param CycleSearch $search     The definitions and the node colours.
	 * @param string      $identifier The node to walk from.
	 *
	 * @return string[] The cycle, or [].
	 */
	private function walkFrom(CycleSearch $search, string $identifier): array {
		return $this->walkForCycle(search: $search, trail: [$identifier]);
	}//end walkFrom()

	/**
	 * Depth-first walk looking for a cycle.
	 *
	 * @param CycleSearch $search The definitions and the node colours, written in place.
	 * @param string[]    $trail  The chain walked so far, ending at the node to walk from.
	 *
	 * @return string[] The cycle, or [].
	 */
	private function walkForCycle(CycleSearch $search, array $trail): array {
		$identifier = (string)end($trail);
		$colour = ($search->state[$identifier] ?? '');
		if ($colour === 'walking') {
			return $this->cycleOn(trail: $trail);
		}

		if ($colour === 'done' || isset($search->byIdentifier[$identifier]) === false) {
			return [];
		}

		$search->state[$identifier] = 'walking';
		foreach ($this->predecessorsOf(definition: $search->byIdentifier[$identifier]) as $predecessor) {
			$found = $this->walkForCycle(search: $search, trail: [...$trail, $predecessor]);
			if (count($found) > 0) {
				return $found;
			}
		}

		$search->state[$identifier] = 'done';

		return [];
	}//end walkForCycle()

	/**
	 * The cycle a trail closed, without the run-up that led into it.
	 *
	 * An administrator has to know which items to break, and the run-up is
	 * not part of the answer.
	 *
	 * @param string[] $trail The chain walked, ending at the node seen again.
	 *
	 * @return string[] The cycle.
	 */
	private function cycleOn(array $trail): array {
		$identifier = (string)end($trail);
		$path = array_slice($trail, 0, -1);
		$start = array_search($identifier, $path, true);
		if ($start === false) {
			return [$identifier];
		}

		return array_values(array_slice($path, (int)$start));
	}//end cycleOn()

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
