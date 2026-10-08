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
 * 🔑 EACH DIGEST IS COMPOSED AS ITS RECIPIENT. Cron has no user, so composed
 * as nobody OpenRegister returned nothing and no digest went out. Composed as
 * the background service account it would list the titles of cases the
 * recipient cannot open, because the account reads more than any one
 * handler. So the queue is read with the recipient as the volatile active
 * user, restored in a `finally`, and only the digest row is written as the
 * account. Without a usable account nothing is composed or sent.
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
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends each person their daily digest, in the hour they chose.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The classes over the limit are the
 *   background service account, its refusal and the session that carries the
 *   recipient, which composing as the recipient needs.
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
	 * @param BackgroundServiceAccount $serviceAccount Writes the digest row.
	 * @param IUserSession        $userSession Carries the recipient while their digest is composed.
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
		private readonly BackgroundServiceAccount $serviceAccount,
		private readonly IUserSession $userSession,
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

		try {
			$account = $this->serviceAccount->require();
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// composed or sent; the next run tries again.
			return;
		}

		$now = new DateTimeImmutable();
		$hour = (int)$now->format('G');
		$today = $now->format('Y-m-d');
		$sent = 0;

		$this->users->callForSeenUsers(
			function (IUser $user) use ($account, $now, $hour, $today, &$sent): ?bool {
				if ($user->getUID() !== $account->getUID()) {
					$sent += $this->sendOne(user: $user, now: $now, hour: $hour, today: $today);
				}

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
	 * @param IUser             $user   The person.
	 * @param DateTimeImmutable $now    The moment the run started.
	 * @param integer           $hour   The hour the run is in.
	 * @param string            $today  Today, `Y-m-d`.
	 *
	 * @return integer 1 when a digest was sent, 0 otherwise.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	private function sendOne(IUser $user, DateTimeImmutable $now, int $hour, string $today): int {
		$userId = $user->getUID();
		if ($this->settings->isDue(userId: $userId, hour: $hour, today: $today) === false) {
			return 0;
		}

		try {
			$digest = $this->composeAs(user: $user, now: $now);
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
			$this->serviceAccount->runAs(operation: fn (): string => $this->dispatcher->send(digest: $digest));
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not send a digest to ' . $userId . ': ' . $e->getMessage());

			return 0;
		}

		$this->settings->markSent(userId: $userId, today: $today);

		return 1;
	}//end sendOne()

	/**
	 * Compose one digest with the recipient as the acting user.
	 *
	 * OpenRegister then answers every read the way it answers that person, so
	 * the digest cannot name a case they could not open themselves. The
	 * previous user is restored even when composing throws.
	 *
	 * @param IUser             $user The recipient.
	 * @param DateTimeImmutable $now  The moment the run started.
	 *
	 * @return array<string, mixed>|null The digest, or null when nothing waits.
	 *
	 * @spec openspec/changes/background-jobs-decisions/specs/my-work/spec.md
	 */
	private function composeAs(IUser $user, DateTimeImmutable $now): ?array {
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($user);

		try {
			return $this->composer->compose(userId: $user->getUID(), now: $now);
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end composeAs()
}//end class
