<?php

/**
 * The DSO term a timer is armed for.
 *
 * Built by `DsoDeadlineTimer::sync()` once the deadline is known to lie ahead, and handed through the
 * arm steps: the case, its id, midnight today and the calendar days to the deadline day.
 *
 * @category Dso
 * @package  OCA\Dossiq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Dso;

use DateTimeImmutable;

/**
 * The DSO term a timer is armed for.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */
final class DsoTermSpan {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $case    The case.
	 * @param string               $caseId  Its id.
	 * @param DateTimeImmutable    $anchor  Midnight today.
	 * @param int                  $slaDays Calendar days to the deadline day.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $case,
		public readonly string $caseId,
		public readonly DateTimeImmutable $anchor,
		public readonly int $slaDays,
	) {
	}//end __construct()
}//end class
