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
 * Merges a desired authorization block onto a live one, against what was written last.
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseAuthorizationMerge {

	/**
	 * The merged block, and the projector rules an administrator changed.
	 *
	 * @param array<string, mixed>      $desired  What the projector would write now: shipped plus team rules.
	 * @param array<string, mixed>      $live     The case schema's block as it stands.
	 * @param array<string, mixed>|null $baseline What the projector wrote last, or null when nothing was recorded.
	 *
	 * @return array{block: array<string, mixed>, conflicts: array<int, string>} The block, and `action` or `action[i]` paths kept local.
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function merge(array $desired, array $live, ?array $baseline): array {
		$owned = ($baseline ?? $this->ownedByShape(desired: $desired, live: $live));
		$block = [];
		$conflicts = [];
		foreach (array_unique(array_merge(array_keys($desired), array_keys($live))) as $action) {
			$merged = $this->mergeAction(
				desired: ($desired[$action] ?? null),
				live: ($live[$action] ?? null),
				owned: ($owned[$action] ?? null),
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
	 * One action's value: the projector's entries it still owns, then the local ones.
	 *
	 * A map (`roles`, `matrix`) is taken whole: the projector's when the live
	 * value is still what it wrote, the live one otherwise.
	 *
	 * @param mixed $desired What the projector would write now.
	 * @param mixed $live    The live value.
	 * @param mixed $owned   What the projector wrote last.
	 *
	 * @return array{value: mixed, conflicts: array<int, string>} The value (null: leave the action out) and the conflict suffixes.
	 */
	private function mergeAction(mixed $desired, mixed $live, mixed $owned): array {
		if (self::isList(value: $desired) === false || self::isList(value: $live) === false) {
			return $this->mergeWhole(desired: $desired, live: $live, owned: $owned);
		}

		$ownedKeys = array_map(self::key(...), self::listOf(value: $owned));
		$liveKeys = array_map(self::key(...), self::listOf(value: $live));
		$changed = array_values(array_diff($ownedKeys, $liveKeys));

		$value = [];
		$conflicts = [];
		foreach (self::listOf(value: $desired) as $entry) {
			if ($this->replaces(entry: $entry, changed: $changed, owned: self::listOf(value: $owned)) === true) {
				$conflicts[] = '[' . self::label(entry: $entry) . ']';
				continue;
			}

			$value[] = $entry;
		}

		foreach (self::listOf(value: $live) as $entry) {
			if (in_array(self::key(entry: $entry), $ownedKeys, true) === false) {
				$value[] = $entry;
			}
		}

		return ['value' => self::unique(entries: $value), 'conflicts' => $conflicts];
	}//end mergeAction()

	/**
	 * A map value, or a value that is no list on one side.
	 *
	 * @param mixed $desired What the projector would write now.
	 * @param mixed $live    The live value.
	 * @param mixed $owned   What the projector wrote last.
	 *
	 * @return array{value: mixed, conflicts: array<int, string>} The value and the conflict suffixes.
	 */
	private function mergeWhole(mixed $desired, mixed $live, mixed $owned): array {
		if ($live === null || self::key(entry: $live) === self::key(entry: $owned)) {
			return ['value' => $desired, 'conflicts' => []];
		}

		$conflicts = [];
		if ($desired !== null && self::key(entry: $desired) !== self::key(entry: $live)) {
			$conflicts[] = '';
		}

		return ['value' => $live, 'conflicts' => $conflicts];
	}//end mergeWhole()

	/**
	 * Whether a desired entry stands where an administrator changed or removed a projector rule.
	 *
	 * The same rule (same key), or a rule for the same principal on the same
	 * kind of match, counts as the same place.
	 *
	 * @param mixed              $entry   The desired entry.
	 * @param array<int, string> $changed Keys of owned entries no longer on the live block.
	 * @param array<int, mixed>  $owned   What the projector wrote last.
	 *
	 * @return bool True when writing it would overwrite the administrator.
	 */
	private function replaces(mixed $entry, array $changed, array $owned): bool {
		if (in_array(self::key(entry: $entry), $changed, true) === true) {
			return true;
		}

		foreach ($owned as $before) {
			if (in_array(self::key(entry: $before), $changed, true) === true && self::label(entry: $before) === self::label(entry: $entry)) {
				return true;
			}
		}

		return false;
	}//end replaces()

	/**
	 * The projector's own rules when no baseline was recorded: those on the live
	 * block that are shipped or shaped like a handling team rule.
	 *
	 * Only rules that are on the live block count, so nothing reads as changed
	 * by an administrator: without a baseline that cannot be known.
	 *
	 * @param array<string, mixed> $desired What the projector would write now.
	 * @param array<string, mixed> $live    The live block.
	 *
	 * @return array<string, mixed> The owned block.
	 */
	private function ownedByShape(array $desired, array $live): array {
		$owned = [];
		foreach ($live as $action => $value) {
			if (self::isList(value: $value) === false || self::isList(value: ($desired[$action] ?? [])) === false) {
				$owned[$action] = $value;
				continue;
			}

			$shipped = array_map(self::key(...), self::listOf(value: ($desired[$action] ?? [])));
			$owned[$action] = array_values(
				array_filter(
					$value,
					static fn (mixed $entry): bool => self::isTeamRule(entry: $entry) === true || in_array(self::key(entry: $entry), $shipped, true) === true,
				)
			);
		}

		return $owned;
	}//end ownedByShape()

	/**
	 * Whether an entry is a handling team rule: a group granted on `caseType`.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return bool True for a team rule.
	 */
	private static function isTeamRule(mixed $entry): bool {
		return is_array($entry) === true
			&& is_string($entry['group'] ?? null) === true
			&& array_keys((array)($entry['match'] ?? [])) === [CaseTeamAccessRules::MATCH_FIELD];
	}//end isTeamRule()

	/**
	 * Where an entry stands: the principal it names and the fields it matches on.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string The label.
	 */
	private static function label(mixed $entry): string {
		if (is_array($entry) === false) {
			return (string)json_encode($entry);
		}

		$fields = array_keys((array)($entry['match'] ?? []));
		sort($fields);

		return (string)($entry['group'] ?? ($entry['user'] ?? '')) . ':' . implode(',', $fields);
	}//end label()

	/**
	 * A canonical key for an entry, independent of key order.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string The key.
	 */
	private static function key(mixed $entry): string {
		return (string)json_encode(self::canonical(value: $entry));
	}//end key()

	/**
	 * The value with every map's keys sorted.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The canonical value.
	 */
	private static function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		return array_map(self::canonical(...), $value);
	}//end canonical()

	/**
	 * Whether a value is a list of rules (or absent).
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a list or null.
	 */
	private static function isList(mixed $value): bool {
		return $value === null || (is_array($value) === true && array_is_list($value) === true);
	}//end isList()

	/**
	 * A value as a list of rules.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int, mixed> The list.
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === true && array_is_list($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()

	/**
	 * The entries without repeats, first one kept.
	 *
	 * @param array<int, mixed> $entries The entries.
	 *
	 * @return array<int, mixed> The entries.
	 */
	private static function unique(array $entries): array {
		$seen = [];
		$kept = [];
		foreach ($entries as $entry) {
			$key = self::key(entry: $entry);
			if (isset($seen[$key]) === false) {
				$seen[$key] = true;
				$kept[] = $entry;
			}
		}

		return $kept;
	}//end unique()
}//end class
