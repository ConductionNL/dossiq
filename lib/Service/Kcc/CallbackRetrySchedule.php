<?php

/**
 * Dossiq KCC callback retry schedule.
 *
 * When a callback attempt fails, the next attempt waits 15 minutes, then 30,
 * then 60, doubling and never more than a day. This was the one part of
 * Kcc\SlaCalculator anything called; the rest of that class duplicated the
 * engine's SlaCalculator name for name and computed channel SLAs nothing
 * read, so it retired (termijnbewaking-op-engine-timers 4.1) and this
 * stays, because a retry wait is not a deadline and has no calendar.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateInterval;
use DateTimeImmutable;

/**
 * The wait before the next callback attempt.
 *
 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
 */
class CallbackRetrySchedule {

	/**
	 * The first wait, in minutes.
	 *
	 * @var int
	 */
	private const BASE_MINUTES = 15;

	/**
	 * The longest wait, in minutes.
	 *
	 * @var int
	 */
	private const CAP_MINUTES = 1440;

	/**
	 * The moment of the next attempt.
	 *
	 * @param DateTimeImmutable $from         The failed attempt.
	 * @param int               $attemptCount Attempts made so far, the failed one included.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/specs/kcc-klantcontact-integratie/spec.md#requirement-callback-scheduling-and-sla-tracking
	 */
	public function nextRetryAt(DateTimeImmutable $from, int $attemptCount): DateTimeImmutable {
		$minutes = (int) min((self::BASE_MINUTES * (2 ** max(0, $attemptCount))), self::CAP_MINUTES);

		return $from->add(new DateInterval('PT'.$minutes.'M'));
	}//end nextRetryAt()
}//end class
