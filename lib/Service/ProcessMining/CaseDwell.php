<?php

/**
 * One case whose status visits are measured: its id, its close moment and the report window.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;

/**
 * A case in a dwell-time report.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class CaseDwell {

	/**
	 * Constructor.
	 *
	 * @param string                 $caseId   The case id.
	 * @param DateTimeImmutable|null $closedAt The case's close moment, or null when still open.
	 * @param DwellWindow            $window   The report window.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly ?DateTimeImmutable $closedAt,
		public readonly DwellWindow $window,
	) {
	}//end __construct()

	/**
	 * Whether a visit entered at this moment falls in the window; an unknown moment never does.
	 *
	 * @param DateTimeImmutable|null $moment When the status was entered, or null when unknown.
	 *
	 * @return bool True when the moment lies in [start, end].
	 *
	 * @phpstan-assert-if-true DateTimeImmutable $moment
	 * @psalm-assert-if-true DateTimeImmutable $moment
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function admits(?DateTimeImmutable $moment): bool {
		return $moment !== null && $moment >= $this->window->start && $moment <= $this->window->end;
	}//end admits()

	/**
	 * When the case's last visit ends: its close moment, or "now" while it is open.
	 *
	 * @return DateTimeImmutable The moment.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function lastExit(): DateTimeImmutable {
		return ($this->closedAt ?? $this->window->now);
	}//end lastExit()

	/**
	 * Whether a visit is the one a still-open case is in: it ends at "now" and grows every day.
	 *
	 * @param StatusVisit $visit The visit.
	 *
	 * @return bool True for the last visit of an open case.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function isOpenAt(StatusVisit $visit): bool {
		return $visit->next === null && $this->closedAt === null;
	}//end isOpenAt()
}//end class
