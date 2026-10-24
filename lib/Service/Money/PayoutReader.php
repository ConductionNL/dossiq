<?php

/**
 * Dossiq PayoutReader
 *
 * Reads the payouts dossiq raised back from the finance app, so the penalty
 * audit and the objection flow read the payout where its money lives instead
 * of a copy in dossiq. It asks with shillinq's PayablesRequestedEvent and
 * answers null when no finance app answers; the caller then reads the rows
 * dossiq kept before the payout moved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\AppInfo\Application;
use OCA\Shillinq\Event\PayablesRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Asks the finance app for dossiq's payouts.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */
class PayoutReader {
	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Carries the question to the finance app.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
	) {
	}//end __construct()

	/**
	 * Dossiq's penalty payouts, narrowed by calculation and by the year they were paid in.
	 *
	 * @param array<int, string> $correlationIds Only these calculations, when not empty.
	 * @param int $paidInYear Only payouts paid in this year, when above zero.
	 *
	 * @return array<int, array<string, mixed>>|null The payouts, or null when no finance app answers.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function payouts(array $correlationIds = [], int $paidInYear = 0): ?array {
		if (class_exists(PayablesRequestedEvent::class) === false) {
			return null;
		}

		$event = new PayablesRequestedEvent(
			sourceApp: Application::APP_ID,
			correlationIds: $correlationIds,
			category: PayoutService::CATEGORY,
			paidInYear: $paidInYear,
		);
		$this->dispatcher->dispatchTyped($event);
		if ($event->isHandled() === false) {
			return null;
		}

		return $event->getPayables();
	}//end payouts()
}//end class
