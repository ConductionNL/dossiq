<?php

/**
 * Dossiq Daily Digest Job.
 *
 * Runs every hour and sends each person their digest in the hour they chose.
 * Hourly rather than daily because "at a time they choose" cannot be honoured
 * by a job that wakes once a day: it would send everybody's at whatever hour
 * the runner happened to fire.
 *
 * Every decision this job makes is somebody else's: whether a person is due is
 * {@see \OCA\Dossiq\Service\Queue\DigestPreferences}, what to say is
 * {@see \OCA\Dossiq\Service\Queue\DailyDigestComposer}, and how it reaches
 * them is {@see \OCA\Dossiq\Service\Queue\DigestDispatcher}. The job is the
 * loop, and that is deliberate: a job body is the one place in this app that
 * cannot be unit tested without a runner.
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\DailyDigestComposer;
use OCA\Dossiq\Service\Queue\DigestDispatcher;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends each person their daily digest, in the hour they chose.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class DailyDigestJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory        $time       Time factory.
	 * @param IUserManager        $users      The people who might be due a digest.
	 * @param IAppManager         $appManager Guards the run when OpenRegister is absent.
	 * @param DigestPreferences   $settings   Who wants one, and when.
	 * @param DailyDigestComposer $composer   What to say, or that there is nothing.
	 * @param DigestDispatcher    $dispatcher How it reaches them.
	 * @param LoggerInterface     $logger     Logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IUserManager $users,
		private readonly IAppManager $appManager,
		private readonly DigestPreferences $settings,
		private readonly DailyDigestComposer $composer,
		private readonly DigestDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// Hourly, because the hour is the reader's to choose.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Send every digest that is due in this hour.
	 *
	 * @param mixed $argument The job argument; this job takes none.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) TimedJob's signature.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$now = new DateTimeImmutable();
		$hour = (int)$now->format('G');
		$today = $now->format('Y-m-d');
		$sent = 0;

		$this->users->callForSeenUsers(
			function (IUser $user) use ($now, $hour, $today, &$sent): ?bool {
				$sent += $this->sendOne(userId: $user->getUID(), now: $now, hour: $hour, today: $today);

				// `callForSeenUsers` types its callback as returning bool|null
				// and stops walking on a literal false. Returning null keeps
				// the walk going and says so, rather than leaving the return
				// type to be inferred as void.
				return null;
			}
		);

		if ($sent > 0) {
			$this->logger->info('Dossiq: sent ' . $sent . ' work digests.');
		}
	}//end run()

	/**
	 * One person's digest, if they are due one and have something waiting.
	 *
	 * @param string            $userId The person.
	 * @param DateTimeImmutable $now    The moment the run started.
	 * @param integer           $hour   The hour the run is in.
	 * @param string            $today  Today, `Y-m-d`.
	 *
	 * @return integer 1 when a digest was sent, 0 otherwise.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function sendOne(string $userId, DateTimeImmutable $now, int $hour, string $today): int {
		if ($this->settings->isDue(userId: $userId, hour: $hour, today: $today) === false) {
			return 0;
		}

		try {
			$digest = $this->composer->compose(userId: $userId, now: $now);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not compose a digest for ' . $userId . ': ' . $e->getMessage());

			return 0;
		}

		if ($digest === null) {
			// Nothing is waiting on them, so they hear nothing. The day is
			// still marked, so an item arriving at noon does not trigger a
			// second run's digest in the afternoon.
			$this->settings->markSent(userId: $userId, today: $today);

			return 0;
		}

		try {
			$this->dispatcher->send(digest: $digest);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not send a digest to ' . $userId . ': ' . $e->getMessage());

			return 0;
		}

		$this->settings->markSent(userId: $userId, today: $today);

		return 1;
	}//end sendOne()
}//end class
