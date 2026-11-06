<?php

/**
 * Dossiq raise due payments job.
 *
 * Once a day, raises every due instalment of every decision's payment
 * schedule with the finance app. It runs as the background service account,
 * because cron has no user and OpenRegister refuses a write from nobody.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\Service\Money\PaymentScheduleRaiser;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Raise the due instalments of payment schedules, daily.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
 */
class RaiseDuePayments extends TimedJob {
	/**
	 * Interval: once per day.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           Time factory.
	 * @param PaymentScheduleRaiser    $raiser         Raises the due instalments.
	 * @param BackgroundServiceAccount $serviceAccount The account the run writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PaymentScheduleRaiser $raiser,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Raise what is due today.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/specs/subsidieverlening-keten/spec.md#requirement-advances-are-a-payment-schedule-raised-with-finance-when-due-req-sub-case-02
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job list hands every job an argument; this one takes none.
	 */
	protected function run($argument): void {
		$today = new DateTimeImmutable($this->time->getDateTime()->format('Y-m-d H:i:s'));
		try {
			$this->serviceAccount->runAs(operation: fn (): int => $this->raiser->raiseDue(today: $today));
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins; the next run tries again.
			return;
		}
	}//end run()
}//end class
