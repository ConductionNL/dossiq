<?php

/**
 * Dossiq term outcome.
 *
 * The quarterly term report counts, per case type, how many terms were met,
 * missed, are running or are suspended (REQ-WTR-005). This is the one place
 * that decides which of the four a term instance is, and the share of decided
 * terms that were met, so the quarterly report and the dashboard snapshot
 * cannot count a late decision differently.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * Classify a term instance as met, missed, running or suspended, and compute the met share.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
 */
class TermOutcome {

	/**
	 * A term decided on or before its end.
	 */
	public const MET = 'met';

	/**
	 * A term that ran out, or was decided after its end.
	 */
	public const MISSED = 'missed';

	/**
	 * A term still on the clock.
	 */
	public const RUNNING = 'running';

	/**
	 * A suspended term: its clock is stopped.
	 */
	public const SUSPENDED = 'suspended';

	/**
	 * Whether a term was met, missed, is running or is suspended (REQ-WTR-005).
	 *
	 * Met: completed on or before `endDateCurrent` (a completion without a
	 * recorded date counts as met, as `withinTerm` always did). Missed: status
	 * `exceeded`, or completed after `endDateCurrent`: a decision closes the
	 * term, it does not undo the overrun. A withdrawn term is none of the four.
	 *
	 * @param array<string, mixed> $row Instance row.
	 *
	 * @return string One of MET, MISSED, RUNNING, SUSPENDED, or the empty string.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function classify(array $row): string {
		$status = (string)($row['status'] ?? '');
		$completedOn = substr((string)($row['voltooiDatum'] ?? ''), 0, 10);
		$end = substr((string)($row['endDateCurrent'] ?? ''), 0, 10);

		return match (true) {
			$status === 'completed' && $completedOn !== '' && $end !== '' && $completedOn > $end => self::MISSED,
			$status === 'completed' => self::MET,
			$status === 'exceeded' => self::MISSED,
			$status === 'lopend', $status === 'verlengd' => self::RUNNING,
			$status === 'paused' => self::SUSPENDED,
			default => '',
		};
	}//end classify()

	/**
	 * The share of decided terms that were met, or null when none was decided.
	 *
	 * A share of nothing is not full compliance and not zero compliance: with
	 * no term met or missed there is no figure to report, and a running term
	 * is not in the denominator because nothing about it is decided yet.
	 *
	 * @param int $met    Terms met.
	 * @param int $missed Terms missed.
	 *
	 * @return float|null The percentage with one decimal, or null.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function shareMet(int $met, int $missed): ?float {
		$decided = ($met + $missed);
		if ($decided === 0) {
			return null;
		}

		return round(($met / $decided) * 100, 1);
	}//end shareMet()

}//end class
