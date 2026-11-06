<?php

/**
 * Dossiq Carry BAG Check Setting Repair Step
 *
 * Keeps the BAG check running on an instance that ran it before the upgrade.
 *
 * Before bag-client-retired, the location save asked the BAG whenever dossiq's
 * own BAG client was switched on: `integration.bag.mode` at `test` or `live`
 * (the Kadaster API), or `integration.bag.source` at `pdok` (the free mirror).
 * That client is gone. The check now runs through integriq, and only where
 * `integration.bag.check` is `on`. Without this step an instance that checked
 * every BAG location yesterday would stop checking after the upgrade, and
 * nothing would say so.
 *
 * WHICH INSTANCES. Exactly those whose old settings switched the client on, read
 * the way the deleted BagRegistrar read them (the mode through IntegrationMode,
 * so `Live ` counts as `live`; the source trimmed and compared to `pdok`). Every
 * other instance is left alone, which leaves the check off, its default.
 *
 * SAFE TO RUN TWICE. The step writes `integration.bag.check` only when the key
 * does not exist yet. Once it is written, by this step or by an administrator,
 * the step does nothing, so an administrator who later switches the check off
 * is not overruled by the next upgrade.
 *
 * IT NEVER THROWS. A repair step that throws aborts the upgrade; one unreadable
 * config value is not worth that. A failure is logged and the check stays off.
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
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-an-instance-that-checked-bag-locations-before-the-upgrade-keeps-checking-them
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Listener\LocationBagValidationListener;
use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets `integration.bag.check` to `on` where the old BAG client was switched on.
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-an-instance-that-checked-bag-locations-before-the-upgrade-keeps-checking-them
 */
class CarryBagCheckSetting implements IRepairStep {
	/**
	 * The key the location save reads.
	 */
	public const CHECK_KEY = 'integration.bag.check';

	/**
	 * The old source value that bound the free PDOK mirror.
	 */
	private const OLD_PDOK_SOURCE = 'pdok';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param IntegrationMode $integrationMode Reads the old BAG settings the way the deleted client did.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IntegrationMode $integrationMode,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-an-instance-that-checked-bag-locations-before-the-upgrade-keeps-checking-them
	 */
	public function getName(): string {
		return 'Keep the BAG check on where the old BAG client was switched on';
	}//end getName()

	/**
	 * Switch the check on where the old settings ran it; leave every other instance off.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-an-instance-that-checked-bag-locations-before-the-upgrade-keeps-checking-them
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->appConfig->hasKey(Application::APP_ID, self::CHECK_KEY) === true) {
				return;
			}

			if ($this->oldClientWasOn() === false) {
				return;
			}

			$this->appConfig->setValueString(Application::APP_ID, self::CHECK_KEY, LocationBagValidationListener::CHECK_ON);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not carry the BAG check setting; the BAG check is off until an administrator sets {key} to on',
				['key' => self::CHECK_KEY, 'error' => $e->getMessage()]
			);
			return;
		}//end try

		$output->info('The BAG check stays on: ' . self::CHECK_KEY . ' is set to on.');
		$this->logger->info(
			'Dossiq: {key} set to on, because the old BAG client was switched on',
			['key' => self::CHECK_KEY]
		);
	}//end run()

	/**
	 * Whether the deleted BagRegistrar would have bound a client that asks the BAG.
	 *
	 * @return bool
	 */
	private function oldClientWasOn(): bool {
		$mode = $this->integrationMode->resolve(
			integration: 'bag',
			allowed: [IntegrationMode::TEST, IntegrationMode::LIVE]
		);
		if ($mode !== IntegrationMode::LOG) {
			return true;
		}

		return $this->integrationMode->setting(integration: 'bag', key: 'source') === self::OLD_PDOK_SOURCE;
	}//end oldClientWasOn()
}//end class
