<?php

/**
 * Folds the projector's block onto the live case schema without erasing local rules.
 *
 * Ruben decided on 3 November 2026 that {@see CaseTeamAccessProjector} merges
 * rather than replaces. The projector owns what it wrote last time, which is
 * the case schema's recorded shipped baseline. Every rule on the live schema
 * that is not in the baseline is local, an administrator's, and is kept as it
 * stands. A rule the projector wrote that the administrator has since changed
 * or removed is not written back: it is reported as a conflict and the
 * administrator's version stays.
 *
 * 🔴 NOTHING LOCAL IS EVER WIDENED. A local rule is copied as it is; the
 * projector's rules are added beside it as separate entries and are never
 * folded into it.
 *
 * Without a recorded baseline, the projector's own rules are recognised by
 * their shape instead: the shipped block plus every rule that grants a group on
 * `caseType` (the handling team rules). Anything else is local. A map value
 * (`roles`, `matrix`) has no rules to tell apart, so without a baseline it is
 * written as the projector derives it, as before.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

/**
 * Merges a desired authorization block onto a live one.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseAuthorizationMerge {

	/**
	 * Constructor.
	 *
	 * @param CaseAuthorizationEntries $entries Reads one rule at a time.
	 */
	public function __construct(
		private readonly CaseAuthorizationEntries $entries = new CaseAuthorizationEntries(),
	) {
	}//end __construct()

	/**
	 * The merged block, and the rules kept local against what the projector derives.
	 *
	 * @param array<string, mixed>      $desired  What the projector would write now: shipped plus team rules.
	 * @param array<string, mixed>      $live     The case schema's block as it stands.
	 * @param array<string, mixed>|null $baseline What the projector wrote last, or null when nothing was recorded.
	 * @param array<int, string>        $managed  The teams the projector manages besides those it derives now.
	 *
	 * @return array{block: array<string, mixed>, conflicts: array<int, string>} The block, and `action` or `action[rule]` paths kept local.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function merge(array $desired, array $live, ?array $baseline, array $managed = []): array {
		$teams = array_merge($managed, $this->entries->teamsIn(block: $desired), $this->entries->teamsIn(block: ($baseline ?? [])));
		$teams = array_values(array_unique($teams));
		$block = [];
		$conflicts = [];
		foreach (array_unique(array_merge(array_keys($desired), array_keys($live))) as $action) {
			$owned = null;
			if ($baseline !== null) {
				$owned = ['owned' => ($baseline[$action] ?? null)];
			}

			$merged = $this->mergeAction(
				values: ['desired' => ($desired[$action] ?? null), 'live' => ($live[$action] ?? null)],
				baseline: $owned,
				teams: $teams,
			);
			if ($merged['value'] !== null) {
				$block[$action] = $merged['value'];
			}

			foreach ($merged['conflicts'] as $suffix) {
				$conflicts[] = $action . $suffix;
			}
		}

		return ['block' => $block, 'conflicts' => $conflicts];
	}//end merge()

	/**
	 * One action's value.
	 *
	 * @param array{desired: mixed, live: mixed} $values   What the projector derives now, and the live value.
	 * @param array{owned: mixed}|null           $baseline What the projector wrote last, or null without a baseline.
	 * @param array<int, string>                 $teams    The managed teams.
	 *
	 * @return array{value: mixed, conflicts: array<int, string>} The value (null: leave the action out) and the conflict suffixes.
	 */
	private function mergeAction(array $values, ?array $baseline, array $teams): array {
		if ($this->entries->isList(value: $values['desired']) === false || $this->entries->isList(value: $values['live']) === false) {
			return $this->mergeWhole(values: $values, baseline: $baseline);
		}

		$owned = array_map($this->entries->key(...), $this->entries->listOf(value: ($baseline['owned'] ?? null)));
		$live = $this->entries->listOf(value: $values['live']);
		$conflicts = [];
		$value = $this->derivedEntries(
			values: $values,
			known: ['owned' => $owned, 'live' => array_map($this->entries->key(...), $live), 'hasBaseline' => ($baseline !== null)],
			teams: $teams,
			conflicts: $conflicts,
		);

		foreach ($live as $entry) {
			if ($this->entries->isManagedTeamRule(entry: $entry, teams: $teams) === true) {
				continue;
			}

			$ownedEntry = ($baseline !== null && in_array($this->entries->key(entry: $entry), $owned, true) === true);
			$value = array_merge($value, $this->keptLive(entry: $entry, ownedEntry: $ownedEntry, desired: $values['desired'], conflicts: $conflicts));
		}

		return ['value' => $this->entries->unique(entries: $value), 'conflicts' => $conflicts];
	}//end mergeAction()

	/**
	 * The rules the projector derives that it may write: all but a non-team rule an administrator removed or changed.
	 *
	 * @param array{desired: mixed, live: mixed}                                           $values    What the projector derives now, and the live value.
	 * @param array{owned: array<int, string>, live: array<int, string>, hasBaseline: bool} $known     Keys of the baseline's and the live rules.
	 * @param array<int, string>                                                           $teams     The managed teams.
	 * @param array<int, string>                                                           $conflicts The conflict suffixes, extended in place.
	 *
	 * @return array<int, mixed> The rules to write.
	 */
	private function derivedEntries(array $values, array $known, array $teams, array &$conflicts): array {
		$value = [];
		foreach ($this->entries->listOf(value: $values['desired']) as $entry) {
			$key = $this->entries->key(entry: $entry);
			$removedLocally = in_array($key, $known['live'], true) === false
				&& (($known['hasBaseline'] === false && $values['live'] !== null) || in_array($key, $known['owned'], true) === true);
			if ($this->entries->isManagedTeamRule(entry: $entry, teams: $teams) === false && $removedLocally === true) {
				$conflicts[] = '[' . $this->entries->label(entry: $entry) . ']';
				continue;
			}

			$value[] = $entry;
		}

		return $value;
	}//end derivedEntries()

	/**
	 * A live rule that is not a managed team rule: kept unless the projector owned it and dropped it.
	 *
	 * A local rule the projector does not derive is reported when it is
	 * shaped like a team rule, so an administrator's team rule is visible.
	 *
	 * @param mixed              $entry      The live rule.
	 * @param bool               $ownedEntry Whether the baseline says the projector wrote it.
	 * @param mixed              $desired    What the projector derives now for this action.
	 * @param array<int, string> $conflicts  The conflict suffixes, extended in place.
	 *
	 * @return array<int, mixed> The rule to keep, or nothing.
	 */
	private function keptLive(mixed $entry, bool $ownedEntry, mixed $desired, array &$conflicts): array {
		$derived = in_array($this->entries->key(entry: $entry), array_map($this->entries->key(...), $this->entries->listOf(value: $desired)), true);
		if ($ownedEntry === true && $derived === false) {
			return [];
		}

		if ($derived === false && $this->entries->isTeamRule(entry: $entry) === true) {
			$conflicts[] = '[' . $this->entries->label(entry: $entry) . ']';
		}

		return [$entry];
	}//end keptLive()

	/**
	 * A map value, or a value that is no list on one side.
	 *
	 * The projector's value while the live one is what it wrote; the live one
	 * otherwise, reported. Without a baseline a live value that differs is kept.
	 *
	 * @param array{desired: mixed, live: mixed} $values   What the projector derives now, and the live value.
	 * @param array{owned: mixed}|null           $baseline What the projector wrote last, or null without a baseline.
	 *
	 * @return array{value: mixed, conflicts: array<int, string>} The value and the conflict suffixes.
	 */
	private function mergeWhole(array $values, ?array $baseline): array {
		$live = $values['live'];
		$desired = $values['desired'];
		if ($live === null || ($baseline !== null && $this->entries->key(entry: $live) === $this->entries->key(entry: $baseline['owned']))) {
			return ['value' => $desired, 'conflicts' => []];
		}

		$conflicts = [];
		if ($desired !== null && $this->entries->key(entry: $desired) !== $this->entries->key(entry: $live)) {
			$conflicts[] = '';
		}

		return ['value' => $live, 'conflicts' => $conflicts];
	}//end mergeWhole()

}//end class
