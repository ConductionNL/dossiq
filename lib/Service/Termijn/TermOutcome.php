<?php

/**
 * Dossiq term outcome.
 *
 * What a term came to (met, missed, running, suspended) and the share of
 * decided terms that were met. One rule for every report that counts terms,
 * for any case type: the quarterly report per case type and the dashboard
 * snapshot read it here, so they cannot count a late decision differently.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * Classifies a term instance row and computes the met share.
 *
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
	 * What a term came to.
	 *
	 * A completed term is met when it was decided on or before its end, and
	 * missed when the decision came later: a decision closes the term, it does
	 * not undo the overrun. A term that ran out (`exceeded`) is missed. A
	 * paused term is suspended. Everything else is still running.
	 *
	 * @param array<string, mixed> $row Term instance row.
	 *
	 * @return string One of MET, MISSED, RUNNING, SUSPENDED.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
	 */
	public function classify(array $row): string {
		$status = (string)($row['status'] ?? '');
		if ($status === 'exceeded') {
			return self::MISSED;
		}

		if ($status === 'paused') {
			return self::SUSPENDED;
		}

		if ($status !== 'completed') {
			return self::RUNNING;
		}

		$decided = substr((string)($row['voltooiDatum'] ?? ''), 0, 10);
		$end = substr((string)($row['endDateCurrent'] ?? ''), 0, 10);
		if ($decided !== '' && $end !== '' && $decided > $end) {
			return self::MISSED;
		}

		return self::MET;
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
