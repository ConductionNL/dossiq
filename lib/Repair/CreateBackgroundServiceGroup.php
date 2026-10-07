<?php

/**
 * Dossiq CreateBackgroundServiceGroup repair step.
 *
 * Creates the `dossiq-background-service` group the schemas a background job
 * writes grant create and update to, and puts the configured background
 * service account in it. It never picks an account: an admin does that in
 * the Dossiq settings, and until then the reminder sweep sends nothing, logs
 * an error and the administration overview says so.
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use InvalidArgumentException;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Creates the background service group and enrols the configured account.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class CreateBackgroundServiceGroup implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param BackgroundServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function getName(): string {
		return 'Create the group the Dossiq background jobs write with';
	}//end getName()

	/**
	 * Create the group and enrol the configured account.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-pause-extension/spec.md
	 */
	public function run(IOutput $output): void {
		$group = $this->serviceAccount->group();
		if ($this->serviceAccount->ensureGroup() === null) {
			$output->warning('Could not create group '.$group.'.');
			return;
		}

		$userId = $this->serviceAccount->configuredUserId();
		if ($userId === '') {
			$output->warning(
				'The Dossiq background jobs have no service account yet, so the termijn reminders send and save nothing. Pick one in the Dossiq settings.'
			);
			return;
		}

		try {
			$this->serviceAccount->assign(userId: $userId);
			$output->info('The background service account '.$userId.' is in group '.$group.'.');
		} catch (InvalidArgumentException $e) {
			$output->warning('The background service account '.$userId.' cannot be used: '.$e->getMessage());
		}
	}//end run()
}//end class
