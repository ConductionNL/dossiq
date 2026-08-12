<?php

/**
 * Procest Daily Termijn Scan Job.
 *
 * Daily background job that drives the AWB termijnbewaking sweep:
 * computes days-to-deadline for every active TermijnInstance, flips
 * overdue rows to `overschreden`, raises pause-expiry events, and
 * dispatches threshold escalation via {@see DeadlineEscalationService}.
 *
 * @category BackgroundJob
 * @package  OCA\Procest\BackgroundJob
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\BackgroundJob;

use OCA\Procest\Service\DeadlineDailyScanService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily timed job: AWB termijnbewaking sweep + escalation.
 *
 * @spec openspec/specs/termijn-escalation/spec.md
 */
class DailyDeadlineScanJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param DeadlineDailyScanService $scan Scan service.
	 * @param IAppManager $appManager App manager.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DeadlineDailyScanService $scan,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		// Daily at the job's regular cadence (NC will pick the 01:00 window
		// automatically; the underlying scan is idempotent if it runs twice).
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the daily sweep.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	protected function run($argument): void {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			return;
		}

		try {
			$counts = $this->scan->run();
			$this->logger->info('Procest daily termijn scan finished', $counts);
		} catch (\Throwable $e) {
			$this->logger->error('Procest daily termijn scan failed', ['error' => $e->getMessage()]);
		}
	}//end run()
}//end class
