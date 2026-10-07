<?php

/**
 * Dossiq RetireUnscheduledBackgroundJobs repair step.
 *
 * Nextcloud adds the jobs `appinfo/info.xml` names on every upgrade and never
 * removes one. A job taken out of info.xml would therefore keep running on
 * every instance that already had it. This step takes those jobs off the job
 * list:
 *
 * - `EmailPdfRetryJob` is parked until the filinq PDF adapter exists. Running
 *   it now only burns the three retries and drops every failed archival row.
 * - `AppointmentReminderJob` is removed. It sent nothing and only set a
 *   "reminder sent" flag.
 * - `BerichtenboxReadStatusJob` is removed. Logius Berichtenbox has no read
 *   status (integriq spec `berichtenbox-client`), so there is nothing to poll.
 *
 * The names are strings because two of the classes no longer exist.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/background-jobs-decisions/specs/background-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Takes the parked and removed jobs off the instance's job list.
 *
 * @spec openspec/changes/background-jobs-decisions/specs/background-jobs/spec.md
 */
class RetireUnscheduledBackgroundJobs implements IRepairStep {

	/**
	 * The job classes that no longer run.
	 *
	 * @var array<int, string>
	 */
	public const RETIRED = [
		'OCA\Dossiq\BackgroundJob\EmailPdfRetryJob',
		'OCA\Dossiq\BackgroundJob\AppointmentReminderJob',
		'OCA\Dossiq\BackgroundJob\BerichtenboxReadStatusJob',
	];

	/**
	 * Constructor.
	 *
	 * @param IJobList $jobList The instance's job list.
	 */
	public function __construct(
		private readonly IJobList $jobList,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/background-jobs-decisions/specs/background-jobs/spec.md
	 */
	public function getName(): string {
		return 'Remove the Dossiq background jobs that no longer run';
	}//end getName()

	/**
	 * Remove every instance of each retired job, whatever its argument.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/background-jobs-decisions/specs/background-jobs/spec.md
	 */
	public function run(IOutput $output): void {
		foreach (self::RETIRED as $class) {
			$this->jobList->remove($class);
		}

		$output->info('Removed '.count(self::RETIRED).' retired Dossiq background jobs from the job list.');
	}//end run()
}//end class
