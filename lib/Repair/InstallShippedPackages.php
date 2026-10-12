<?php

/**
 * Dossiq repair step: install the shipped case definition packages.
 *
 * Installs every package under `lib/Settings/packages/` whose manifest says
 * it installs on every instance. A package already at its version here is
 * left alone, so the step is safe to run on every upgrade.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Installs the shipped packages that install on every instance.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
class InstallShippedPackages implements IRepairStep {

	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param PackageInstaller $installer The package installer.
	 * @param SettingsService  $settings  Resolves the OpenRegister object service.
	 */
	public function __construct(
		private readonly PackageInstaller $installer,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function getName(): string {
		return 'Install the shipped case definition packages for Dossiq';
	}//end getName()

	/**
	 * Install every automatic package and say what became of each.
	 *
	 * A refused or failed package is a warning, not an abort: one package
	 * this instance cannot take must not stop the upgrade of everything else,
	 * and the warning names the package and the reason.
	 *
	 * @param IOutput $output Where the step reports.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function run(IOutput $output): void {
		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'.
		$outcomes = [];
		$this->withSystemIdentity(
			objectService: $this->settings->getObjectService(),
			work: function () use (&$outcomes): void {
				$outcomes = $this->installer->installAutomatic();
			}
		);

		if ($outcomes === []) {
			$output->info('No shipped package installs automatically.');
			return;
		}

		foreach ($outcomes as $outcome) {
			$line = $outcome['package'] . ' ' . $outcome['version'] . ': ' . $outcome['status'] . '. ' . $outcome['message'];
			foreach ((array)($outcome['errors'] ?? []) as $error) {
				$line .= ' ' . $error;
			}

			if ($outcome['status'] === PackageInstaller::INSTALLED || $outcome['status'] === PackageInstaller::CURRENT) {
				$output->info($line);
				continue;
			}

			$output->warning($line);
		}
	}//end run()
}//end class
