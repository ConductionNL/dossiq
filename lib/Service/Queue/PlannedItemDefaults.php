<?php

/**
 * What the "Plan an item" dialog opens with.
 *
 * A planned item starts at the next working day at 10:00 (DqUwWachtrij), the
 * working day read from the calendar the organisation administers through
 * {@see WorkingDayCalculator}. The reader can change it before planning.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 *
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\WorkingDayCalculator;

/**
 * The defaults of a planned item.
 *
 * @spec openspec/specs/my-work/spec.md
 */
class PlannedItemDefaults {
	/**
	 * The hour a planned item starts at by default.
	 */
	private const START_HOUR = 10;

	/**
	 * Constructor.
	 *
	 * @param WorkingDayCalculator $workingDays The organisation's working days.
	 */
	public function __construct(
		private readonly WorkingDayCalculator $workingDays,
	) {
	}//end __construct()

	/**
	 * The start a new planned item opens with: the next working day after
	 * `now` at 10:00, as a `datetime-local` value.
	 *
	 * @param DateTimeImmutable|null $now The moment to look from; now by default.
	 *
	 * @return array{startsAt: string} The defaults.
	 *
	 * @spec openspec/specs/my-work/spec.md
	 */
	public function startsAt(?DateTimeImmutable $now = null): array {
		$today = ($now ?? new DateTimeImmutable())->setTime(hour: self::START_HOUR, minute: 0);
		$start = $this->workingDays->addWorkingDays(start: $today, days: 1);

		return ['startsAt' => $start->format('Y-m-d\TH:i')];
	}//end startsAt()
}//end class
