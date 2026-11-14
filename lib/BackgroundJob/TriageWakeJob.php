<?php

/**
 * Dossiq triage wake job.
 *
 * Wakes every triage item whose date has come, and puts it back in the queue it
 * left.
 *
 * WHY A SWEEP AND NOT A TIMER PER ITEM. A timer per item would have to be
 * disarmed when the item is released, junked, bounced or moved, and every one of
 * those five acts would have to remember. An item that woke nobody because its
 * timer was armed against an entry that no longer exists is invisible: nothing
 * errors, the item simply never comes back. The sweep reads the same rows the
 * queue does, so an item cannot be asleep in one and awake in the other.
 *
 * Hourly rather than daily. A sleep is a calendar decision, so the earliest run
 * after midnight is the one that matters, and an hourly sweep costs one filtered
 * read of a log that is small by construction.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Kernel\Contracts\TriageSleepInterface;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Returns slept triage items to the queue on their date.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/kcc-routing/spec.md
 */
class TriageWakeJob extends TimedJob {

	/**
	 * How often the sweep runs, in seconds.
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           The time factory.
	 * @param TriageSleepInterface              $sleep          The triage sleep.
	 * @param LoggerInterface          $logger         The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the sweep writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TriageSleepInterface $sleep,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Wake every item whose date has come.
	 *
	 * @param mixed $argument The job argument; this job takes none.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — the parameter is
	 *  `TimedJob::run()`'s, and a sweep takes no argument.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	protected function run($argument): void {
		// Without a usable account this runs nothing: that is already logged as an
		// error and told to the admins. Nothing was read, sent or
		// written; the next run tries again.
		$this->serviceAccount->runAsWhenAvailable(operation: fn () => $this->work());
	}//end run()

	/**
	 * The wake sweep itself, run as the service account.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	private function work(): void {
		try {
			$woken = $this->sleep->wakeDue();
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq triage: the wake sweep failed',
				['reason' => $e->getMessage()],
			);

			return;
		}

		if ($woken === []) {
			return;
		}

		$this->logger->info(
			'Dossiq triage: {count} item(s) returned to the queue',
			['count' => count($woken)],
		);
	}//end work()
}//end class
