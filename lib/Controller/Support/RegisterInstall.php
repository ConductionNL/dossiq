<?php

/**
 * The register side of the setup wizard: whether it is ready, and what gets installed into it.
 *
 * {@see \OCA\Dossiq\Controller\SetupController} reports `registerReady` from
 * here and runs the `init-register` and `seed` actions through it. Both
 * actions write into OpenRegister in an admin request context, which is why
 * they run on the server and never from the browser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\PackageInstallerInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;

/**
 * Register readiness, the register import and the seed package install.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
final class RegisterInstall {

	/**
	 * The case definition package the `seed` action installs.
	 *
	 * It ships with `install: on-request`, so no upgrade writes it; this
	 * action and `occ dossiq:package:install bezwaar-beroep` are the two ways in.
	 *
	 * @var string
	 */
	public const SEED_PACKAGE = 'bezwaar-beroep';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                $appConfig       Reads the register keys, records the seed install.
	 * @param SettingsServiceInterface  $settingsService OpenRegister availability and the register import.
	 * @param PackageInstallerInterface $packages        Installs the seed package.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly SettingsServiceInterface $settingsService,
		private readonly PackageInstallerInterface $packages,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister is reachable and the register and case type schema are configured.
	 *
	 * @return bool True when the register is ready.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function isReady(): bool {
		return $this->settingsService->isOpenRegisterAvailable() === true
			&& $this->config(key: 'register') !== ''
			&& $this->config(key: 'case_type_schema') !== '';
	}//end isReady()

	/**
	 * Import the register and its schemas, forced.
	 *
	 * @return DataResponse The outcome.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function initialise(): DataResponse {
		$this->settingsService->loadConfiguration(force: true);
		return new DataResponse(['success' => true, 'message' => 'Register and schemas initialised.']);
	}//end initialise()

	/**
	 * Install the seed package; installed or already current both count as done.
	 *
	 * @return DataResponse The outcome, 422 with the installer's answer when refused or failed.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function installSeedPackage(): DataResponse {
		$outcome = $this->packages->install(id: self::SEED_PACKAGE);
		$done    = in_array($outcome['status'], [PackageInstallerInterface::INSTALLED, PackageInstallerInterface::CURRENT], true);
		if ($done === false) {
			return new DataResponse(
				['success' => false, 'message' => $outcome['message'], 'detail' => $outcome],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		$this->appConfig->setValueString('dossiq', 'setup_seed_done', '1');
		return new DataResponse(['success' => true, 'message' => $outcome['message'], 'detail' => $outcome]);
	}//end installSeedPackage()

	/**
	 * Read one dossiq app-config value, empty when unset.
	 *
	 * @param string $key The key.
	 *
	 * @return string The value.
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString('dossiq', $key, '');
	}//end config()
}//end class
