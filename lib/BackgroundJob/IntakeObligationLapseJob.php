<?php

/**
 * Lapses the intake obligations whose due date has passed.
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily: marks a passed intake obligation expired and applies its kind's rule.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
 */
class IntakeObligationLapseJob extends TimedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time           The time factory.
	 * @param IntakeObligations        $obligations    Lapses the obligations.
	 * @param BackgroundServiceAccount $serviceAccount The account the sweep acts as.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IntakeObligations $obligations,
		private readonly BackgroundServiceAccount $serviceAccount,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the sweep as the service account.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-refused-or-lapsed-intake-obligation-follows-its-kinds-rule
	 */
	protected function run($argument): void {
		try {
			$this->serviceAccount->runAs(operation: fn () => $this->obligations->lapse());
		} catch (ServiceAccountUnavailableException $e) {
			// Already logged and told to the admins; the next run tries again.
			return;
		}
	}//end run()
}//end class
