<?php

/**
 * An ISO 8601 period read as a number of days.
 *
 * Split out of {@see \OCA\Dossiq\Service\Import\CaseRecordImport}, which
 * passes a case type's extension period on as days. A period of months has no
 * fixed length, so it is counted from one fixed day; an empty or unreadable
 * period reads as no days at all rather than failing the import.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * Counts the days in a period.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PeriodDays {

	/**
	 * Constructor.
	 *
	 * @param DayCount $days Counts the days between two moments.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * An ISO 8601 period as days, 0 when absent or unreadable.
	 *
	 * @param string $period The period, such as P14D or P2W.
	 *
	 * @return int The days.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function days(string $period): int {
		// An empty or unreadable period throws, and reads as no extension.
		try {
			$from = new DateTimeImmutable('2000-01-01');
			return $this->days->between(from: $from, to: $from->add(new DateInterval($period)));
		} catch (Throwable) {
			return 0;
		}
	}//end days()
}//end class
