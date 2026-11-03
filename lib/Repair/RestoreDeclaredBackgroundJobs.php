<?php

/**
 * Dossiq repair step: put the declared background jobs that are missing back on the job list.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/background-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Adds every job info.xml declares that the job list does not hold.
 *
 * Nextcloud lists the declared jobs only when the app is installed or its
 * version changes, and cron drops a listed job whose class it cannot load at
 * that moment. A job dropped that way stays off the list until the next
 * version bump; this step, also run by `occ maintenance:repair`, puts it
 * back. A job that is listed keeps its schedule.
 *
 * @spec openspec/specs/background-jobs/spec.md
 */
class RestoreDeclaredBackgroundJobs implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IJobList    $jobList    The instance's job list.
	 * @param IAppManager $appManager Reads the jobs info.xml declares.
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/background-jobs/spec.md
	 */
	public function getName(): string {
		return 'Put the missing Dossiq background jobs back on the job list';
	}//end getName()

	/**
	 * Add each declared job the job list does not hold.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/background-jobs/spec.md
	 */
	public function run(IOutput $output): void {
		$declared = (array)($this->appManager->getAppInfo(Application::APP_ID)['background-jobs'] ?? []);
		$added = 0;
		foreach ($declared as $class) {
			if ($this->jobList->has((string)$class, null) === false) {
				$this->jobList->add((string)$class);
				$added++;
			}
		}

		$output->info('Put ' . $added . ' missing Dossiq background jobs back on the job list.');
	}//end run()
}//end class
