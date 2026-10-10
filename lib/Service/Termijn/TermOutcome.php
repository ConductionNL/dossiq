<?php

/**
 * Dossiq term outcome.
 *
 * The quarterly term report counts, per case type, how many terms were met,
 * missed, are running or are suspended (REQ-WTR-005). This is the one place
 * that decides which of the four a term instance is.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * Classify a term instance as met, missed, running or suspended.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class TermOutcome {

	/**
	 * Whether a term was met, missed, is running or is suspended (REQ-WTR-005).
	 *
	 * Met: completed on or before `endDateCurrent` (a completion without a
	 * recorded date counts as met, as `withinTerm` always did). Missed: status
	 * `exceeded`, or completed after `endDateCurrent`. A withdrawn term is none
	 * of the four.
	 *
	 * @param array<string, mixed> $row Instance row.
	 *
	 * @return string One of met, missed, running, suspended, or the empty string.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 */
	public function classify(array $row): string {
		$status = (string)($row['status'] ?? '');
		$completedOn = substr((string)($row['voltooiDatum'] ?? ''), 0, 10);
		$end = substr((string)($row['endDateCurrent'] ?? ''), 0, 10);

		return match (true) {
			$status === 'completed' && $completedOn !== '' && $end !== '' && $completedOn > $end => 'missed',
			$status === 'completed' => 'met',
			$status === 'exceeded' => 'missed',
			$status === 'lopend', $status === 'verlengd' => 'running',
			$status === 'paused' => 'suspended',
			default => '',
		};
	}//end classify()

}//end class
