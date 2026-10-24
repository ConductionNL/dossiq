<?php

/**
 * Dossiq Carry Renamed Setting Keys Repair Step
 *
 * Copies each setting stored under an earlier, procedure-named key onto its
 * current key, so the document review reports an organisation switched on stay
 * on after the upgrade, and the money schemas and the payout callback secret
 * keep their values.
 *
 * SAFE TO RUN TWICE. A current key that already exists is never written: once it
 * is there, by this step or by an administrator, the step leaves it alone. The
 * old key is never deleted, so a rollback still finds its value.
 *
 * IT NEVER THROWS. A repair step that throws aborts the upgrade. A failure is
 * logged; WooReportSwitches still reads the old key while the current one is
 * missing.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes each renamed setting's stored value under its current key.
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */
class CarryRenamedSettingKeys implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig App config.
	 * @param LoggerInterface $logger    Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function getName(): string {
		return 'Carry renamed settings onto their current keys';
	}//end getName()

	/**
	 * Copy every old key that has a value and no current key yet.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function run(IOutput $output): void {
		$carried = 0;
		foreach (array_merge(WooReportSwitches::LEGACY_KEYS, MoneyConfigKeys::LEGACY_KEYS) as $current => $legacy) {
			try {
				if ($this->appConfig->hasKey(Application::APP_ID, $current) === true
					|| $this->appConfig->hasKey(Application::APP_ID, $legacy) === false
				) {
					continue;
				}

				$value = $this->appConfig->getValueString(Application::APP_ID, $legacy, '');
				$this->appConfig->setValueString(Application::APP_ID, $current, $value);
				$carried++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not carry setting {legacy} onto {current}; the old key is still read',
					['legacy' => $legacy, 'current' => $current, 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		if ($carried > 0) {
			$output->info('Carried ' . $carried . ' renamed setting(s) onto their current keys.');
		}
	}//end run()
}//end class
