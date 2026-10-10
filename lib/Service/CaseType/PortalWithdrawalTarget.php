<?php

/**
 * Whether a case type's portal withdrawal lands on a status its workflow can write.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git-id>
 * @link https://conduction.nl
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * Judges `caseType.portalWithdrawal` against the type's statuses and moves.
 *
 * A resident's withdrawal is ONE status write, made by portaliq, from the
 * status the case is in to `targetStatus`. So the question is not whether the
 * target can be reached in some number of steps: it is whether a move leads
 * straight there from every status the withdrawal is open in. A withdrawal the
 * portal offers and the workflow then refuses is a button that fails in front
 * of a resident, which is what this class exists to stop at save time.
 *
 * 🔑 IT IS PURE. It takes the statuses and the moves and answers, like
 * {@see CaseTypeReachability}, so the listener that owns the save decides
 * WHEN to ask and this class only decides WHAT is wrong.
 *
 * 🔴 A TYPE WITH NO MOVES IS NOT JUDGED ON REACHABILITY. A case type that
 * drives its lifecycle from statuses alone carries no workflow template and
 * no move refuses a status write on it, so only "is this one of the type's
 * statuses" applies there.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
final class PortalWithdrawalTarget {

	/**
	 * The target is not one of the type's statuses. Parameter: the target id.
	 */
	public const NOT_OWN = 'not-own';

	/**
	 * No move leads from an open status to the target. Parameters: both titles.
	 */
	public const UNREACHABLE = 'unreachable';

	/**
	 * What is wrong with this withdrawal, as a reason and the names it carries.
	 *
	 * @param array<string, mixed>             $withdrawal The `portalWithdrawal` block being saved.
	 * @param array<string, string>            $statuses   The type's statuses: id to title.
	 * @param array<int, array<string, mixed>> $moves      The active template's transitions.
	 *
	 * @return array{reason: string, parameters: array<int, string>}|null The refusal, or null when the withdrawal can be written.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function refusal(array $withdrawal, array $statuses, array $moves): ?array {
		$target = $this->text(value: ($withdrawal['targetStatus'] ?? null));
		if ($target === '' || $statuses === []) {
			return null;
		}

		if (isset($statuses[$target]) === false) {
			return [
				'reason' => self::NOT_OWN,
				'parameters' => [$target],
			];
		}

		if ($moves === []) {
			return null;
		}

		foreach ($this->openStatuses(withdrawal: $withdrawal) as $open) {
			if ($open === $target || isset($statuses[$open]) === false) {
				continue;
			}

			if ($this->leadsStraightTo(from: $open, target: $target, moves: $moves) === false) {
				return [
					'reason' => self::UNREACHABLE,
					'parameters' => [$statuses[$open], $statuses[$target]],
				];
			}
		}

		return null;
	}//end refusal()

	/**
	 * The statuses the withdrawal is open in, as ids.
	 *
	 * @param array<string, mixed> $withdrawal The `portalWithdrawal` block.
	 *
	 * @return array<int, string> The ids.
	 */
	private function openStatuses(array $withdrawal): array {
		$open = ($withdrawal['openStatuses'] ?? []);
		if (is_array($open) === false) {
			return [];
		}

		$ids = [];
		foreach ($open as $status) {
			$id = $this->text(value: $status);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end openStatuses()

	/**
	 * Whether one move goes from this status straight to the target.
	 *
	 * @param string                           $from   The status the case is in.
	 * @param string                           $target The status the withdrawal lands on.
	 * @param array<int, array<string, mixed>> $moves  The transitions.
	 *
	 * @return boolean True when a move connects them.
	 */
	private function leadsStraightTo(string $from, string $target, array $moves): bool {
		foreach ($moves as $move) {
			if (is_array($move) === false) {
				continue;
			}

			if ($this->text(value: ($move['fromStatus'] ?? null)) === $from
				&& $this->text(value: ($move['toStatus'] ?? null)) === $target
			) {
				return true;
			}
		}

		return false;
	}//end leadsStraightTo()

	/**
	 * The id a value carries: a uuid string, or a row with `id` or `uuid`.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The id, or the empty string.
	 */
	private function text(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
