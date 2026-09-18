<?php

/**
 * A threshold declared as a share of the term, turned into engine rungs.
 *
 * The escalation thresholds were fourteen, seven, two and nought days. On a
 * six-week term fourteen days is a third of it; on a twenty-six-week term it
 * is a formality. A threshold at 25 per cent means the same thing to a handler
 * on both terms and a different date on each, which is the point.
 *
 * 🔴 A SHARE IS RESOLVED WHEN THE TIMER IS ARMED, AND NOWHERE ELSE. The engine
 * ladder takes dates, so a share becomes a number of days at the one moment
 * the length of the term is known. That is why a percentage adds no second
 * escalation mechanism: the rungs handed to the engine are the same rungs, and
 * an extension re-arms the timer, which resolves the shares again.
 *
 * Days and shares coexist on one ladder. Some thresholds are genuinely
 * absolute: a statutory notice two days before the end is two days before on
 * every term, however long it is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

/**
 * Turns a declared ladder into the engine's own escalation rules.
 *
 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
 */
class ThresholdShares {
	/**
	 * The engine trigger every rung of a due-date ladder uses.
	 */
	private const TRIGGER = 'preBreach';

	/**
	 * The unit the engine counts a rung's offset in.
	 */
	private const UNIT = 'calendarDays';

	/**
	 * The engine rules a declared ladder becomes, for a term of this length.
	 *
	 * A rung is either `days` (that many days before the end) or `share` (that
	 * share of the term elapsed, so the rung sits at `term - share * term`
	 * before the end). A rung that declares neither, or a share that lands
	 * outside the term, is dropped: a rung nobody can place is not a rung.
	 *
	 * @param array<int, mixed> $ladder  The declared rungs.
	 * @param int               $slaDays The whole term, in days.
	 *
	 * @return array<int, array<string, mixed>> The engine rules, longest offset first.
	 *
	 * @spec openspec/changes/term-configuration-beyond-the-case-type/specs/termijnbewaking-schemas/spec.md#requirement-a-term-declares-the-statuses-its-clock-runs-in-with-thresholds-as-days-or-shares-req-tcf-03
	 */
	public function rulesFor(array $ladder, int $slaDays): array {
		if ($slaDays <= 0) {
			return [];
		}

		$rules = [];
		foreach ($ladder as $rung) {
			if (is_array($rung) === false) {
				continue;
			}

			$offset = $this->offsetOf(rung: $rung, slaDays: $slaDays);
			if ($offset === null) {
				continue;
			}

			$rules[] = [
				'trigger' => self::TRIGGER,
				'offset' => $offset,
				'offsetUnit' => self::UNIT,
				'notifyRole' => ['handler'],
				'escalateToRole' => [],
				'priority' => (string)(($rung['priority'] ?? '') ?: 'normal'),
				'message' => (string)(($rung['message'] ?? '') ?: 'termijn-drempel'),
				'openIncident' => false,
			];
		}//end foreach

		// Longest offset first, so a reader of the engine's own rule list sees
		// the ladder in the order it fires.
		usort($rules, static fn (array $a, array $b): int => ($b['offset'] <=> $a['offset']));

		return $rules;
	}//end rulesFor()

	/**
	 * How many days before the end one rung sits.
	 *
	 * @param array<string, mixed> $rung    The declared rung.
	 * @param int                  $slaDays The whole term, in days.
	 *
	 * @return int|null The offset, or null when the rung cannot be placed.
	 */
	private function offsetOf(array $rung, int $slaDays): ?int {
		if (array_key_exists('days', $rung) === true && is_numeric($rung['days']) === true) {
			$days = (int)$rung['days'];

			return (($days < 0 || $days > $slaDays) ? null : $days);
		}

		if (array_key_exists('share', $rung) === false || is_numeric($rung['share']) === false) {
			return null;
		}

		$share = (float)$rung['share'];
		if ($share < 0.0 || $share > 1.0) {
			return null;
		}

		// The rung sits where that share of the term has elapsed, so the
		// engine's offset, which counts BACK from the end, is the rest of it.
		$elapsed = (int)round($share * $slaDays);

		return max(0, ($slaDays - $elapsed));
	}//end offsetOf()
}//end class
