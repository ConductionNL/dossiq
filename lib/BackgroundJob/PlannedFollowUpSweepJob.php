<?php

/**
 * Dossiq Planned Follow-Up Sweep.
 *
 * Switches off every planned follow-up flow that has nothing left to do, which
 * is what makes "plan a follow-up" single-shot and what makes a series stop.
 *
 * 🔴 THE ENGINE HAS NO ONE-SHOT TRIGGER, AND FIVE CRON FIELDS CANNOT SAY
 * "ONCE" OR "THREE TIMES". A planned date pins minute, hour, day and month,
 * which names one minute of one day of one month, and that minute comes round
 * again next year. Left alone, a follow-up planned for 15 October 2026 would
 * also open a case on 15 October 2027, and every year after that, with nothing
 * anywhere reporting it. So the promise is kept HERE, by disabling the flow
 * once it is spent, rather than pretended in the cron expression.
 *
 * What "spent" means is the document's rule, not this job's
 * ({@see \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument::isSpent()}): a
 * single follow-up after one firing, a series when its count is reached or its
 * end date has passed, and a series with neither end never — it stops when
 * somebody stops it.
 *
 * The sweep is hourly rather than daily because the residue of a missed sweep
 * is a year long: a flow that fires at 06:00 and is not switched off before the
 * next tick is still switched off within the hour, and the only way to miss the
 * window entirely is for cron to stop, which stops the firing too.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly job retiring the planned follow-ups that are spent.
 *
 * It runs as the background service account, because cron has no user and
 * OpenRegister refuses a write from nobody.
 *
 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
 */
class PlannedFollowUpSweepJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param CaseFlowActions $flowActions Owns the retirement rule.
	 * @param IAppManager $appManager Tells whether OpenRegister is here at all.
	 * @param LoggerInterface $logger The logger.
	 * @param BackgroundServiceAccount $serviceAccount The account the run writes as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly CaseFlowActions $flowActions,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		// Hourly.
		$this->setInterval(seconds: 3600);
	}//end __construct()

	/**
	 * Run once as the background service account.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The job list hands every job an argument; this one takes none.
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->work());
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged as an error and told to the admins. Nothing was
			// read, sent or written; the next run tries again.
			return;
		}
	}//end run()

	/**
	 * Retire the planned follow-ups that are spent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	private function work(): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		$retired = $this->flowActions->retireSpent();
		if ($retired === 0) {
			return;
		}

		// Logged only when something happened: an hourly job that says "0" every
		// hour is an hourly job nobody reads.
		$this->logger->info(
			'Dossiq: retired ' . $retired . ' planned follow-up(s) that were spent',
			['app' => Application::APP_ID],
		);
	}//end work()
}//end class
