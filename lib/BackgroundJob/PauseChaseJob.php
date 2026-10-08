<?php

/**
 * Dossiq PauseChaseJob.
 *
 * The daily look at every suspended term, so a reminder goes out even where no
 * engine armed a rung for it.
 *
 * 🔴 IT IS A SECOND TRIGGER, NOT A SECOND SCHEDULE.
 *
 * The schedule lives on the pause reason and the count lives on the instance.
 * This job asks {@see PauseChaseService::sweep()} the same question the engine
 * rung asks, and the count that service writes is what stops the two from both
 * sending. It exists because OpenRegister's timer engine is an optional runtime
 * dependency: without it `armHersteltermijn()` arms nothing, and a pause with a
 * chasing reason would chase nobody. Chasing that only works when the engine is
 * installed is chasing a gemeente cannot rely on.
 *
 * 🔴 IT WRITES AS THE BACKGROUND SERVICE ACCOUNT. Cron has no user, and
 * OpenRegister refuses an anonymous write: the letter left, the count did not
 * store, and the next run chased again. The sweep runs inside
 * {@see BackgroundServiceAccount::runAs()}; without a usable account it logs,
 * tells the admins and sends nothing, because a reminder that cannot be
 * counted is one the next run sends again.
 *
 * It also catches what a rung cannot: a reason administered after the pause was
 * registered, and an interval an administrator lengthened this morning.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Pause\PauseChaseService;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Chases the suspended terms that are due one, once a day (REQ-TERM-011).
 *
 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
 */
class PauseChaseJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           The time factory.
	 * @param PauseChaseService        $chases         The reminders and the escalation.
	 * @param IAppManager              $appManager     Whether OpenRegister is there to read from.
	 * @param LoggerInterface          $logger         Logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the sweep writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PauseChaseService $chases,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		// Daily. A reminder interval is declared in days, so an hourly sweep
		// would ask the same question twenty-four times and answer it once.
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Sweep every suspended term once.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/pause-reason-with-chasing/specs/termijn-pause-extension/spec.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		try {
			$done = $this->serviceAccount->runAs(operation: fn (): array => $this->chases->sweep());
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// sent and nothing was written; tomorrow's run tries again.
			return;
		} catch (Throwable $e) {
			// A failed sweep must not stop the cron runner for the rest of the
			// app. Nothing was counted, so tomorrow's sweep sends what today's
			// could not.
			$this->logger->error(
				'Dossiq pause: the reminder sweep failed',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			return;
		}

		if ($done['chased'] === 0 && $done['escalated'] === 0) {
			return;
		}

		$this->logger->info(
			'Dossiq pause: reminders went out on suspended terms',
			[
				'app' => Application::APP_ID,
				'chased' => $done['chased'],
				'escalated' => $done['escalated'],
			]
		);
	}//end run()
}//end class
